<?php

/**
 * Run contract maintenance:
 * - Mark contracts as expired based on end date
 * - For expired contracts, move deceased records to archive and free plots
 * - Mark contracts as renewal_needed when within 30 days of expiry
 *
 * This file is intentionally side-effect free (no output, no closing connection)
 * so it can be safely called from both CLI scripts and web pages.
 *
 * @param mysqli $conn   Active database connection
 * @param bool   $is_cli If true, returns a details array that a caller may echo/log
 *
 * @return array{expired:int, archived:int, freed_plots:int, renewal_needed:int}
 */
function run_contract_maintenance($conn, $is_cli = false)
{
    $summary = [
        'expired'        => 0,
        'archived'       => 0,
        'freed_plots'    => 0,
        'renewal_needed' => 0,
    ];

    if (!$conn || !($conn instanceof mysqli)) {
        return $summary;
    }

    // 1) Update expired contracts (status only; contract details remain on plot)
    $expired_query = "UPDATE plots 
                      SET contract_status = 'expired' 
                      WHERE contract_end_date < CURDATE() 
                      AND contract_status IN ('active', 'renewal_needed')";
    $result = mysqli_query($conn, $expired_query);
    if ($result) {
        $summary['expired'] = mysqli_affected_rows($conn);
    }

    // 2) Auto-archive deceased records and free plots for expired contracts
    // Check if archived_deceased_records table exists
    $archive_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_deceased_records'");
    $has_archive_table = $archive_check && mysqli_num_rows($archive_check) > 0;
    if ($archive_check) {
        mysqli_free_result($archive_check);
    }
    
    // Check if deceased_records table exists
    $deceased_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
    $has_deceased_table = $deceased_check && mysqli_num_rows($deceased_check) > 0;
    if ($deceased_check) {
        mysqli_free_result($deceased_check);
    }
    
    if ($has_archive_table && $has_deceased_table) {
        // Get user_id from session if available (for web requests), otherwise NULL (for CLI)
        $archived_by = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            $archived_by = (int) $_SESSION['user_id'];
        }
        
        // Find all deceased records associated with expired contracts
        // Only archive contracts that expired 7+ days ago (7-day grace period)
        // AND deceased records that were created 7+ days ago (7-day grace period for newly added records)
        // This ensures newly added deceased records get a 7-day grace period even if contract expired long ago
        $expired_records_query = "SELECT dr.record_id, dr.plot_id, dr.full_name, 
                                  dr.date_of_birth, dr.date_of_death, dr.burial_date
                                  FROM deceased_records dr
                                  INNER JOIN plots p ON dr.plot_id = p.plot_id
                                  WHERE p.contract_end_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                  AND p.contract_end_date IS NOT NULL
                                  AND p.contract_type = 'temporary'
                                  AND (dr.created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) OR dr.created_at IS NULL)";
        
        $expired_result = mysqli_query($conn, $expired_records_query);
        
        if ($expired_result) {
            $archived_count = 0;
            $freed_plots = [];
            
            while ($record = mysqli_fetch_assoc($expired_result)) {
                // Use transaction to ensure atomicity (archive + delete)
                mysqli_begin_transaction($conn);
                
                try {
                    // Split full_name into first_name and last_name
                    $name_parts = explode(' ', trim($record['full_name']), 2);
                    $first_name = $name_parts[0] ?? '';
                    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                    
                    // If full_name is empty, use a default
                    if (empty($first_name)) {
                        $first_name = 'Unknown';
                        $last_name = '';
                    }
                    
                    // Insert into archived_deceased_records
                    $archive_query = "INSERT INTO archived_deceased_records 
                                     (deceased_id, plot_id, first_name, last_name, 
                                     date_of_birth, date_of_death, date_of_burial, 
                                     reason, archived_by)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $archive_stmt = mysqli_prepare($conn, $archive_query);
                    if (!$archive_stmt) {
                        throw new Exception("Failed to prepare archive statement: " . mysqli_error($conn));
                    }
                    
                    $reason = "Contract expired";
                    $deceased_id = (int) $record['record_id'];
                    $plot_id = (int) $record['plot_id'];
                    $date_of_birth = !empty($record['date_of_birth']) && $record['date_of_birth'] !== '0000-00-00' 
                                     ? $record['date_of_birth'] : null;
                    $date_of_death = !empty($record['date_of_death']) && $record['date_of_death'] !== '0000-00-00' 
                                     ? $record['date_of_death'] : null;
                    $date_of_burial = !empty($record['burial_date']) && $record['burial_date'] !== '0000-00-00' 
                                      ? $record['burial_date'] : null;
                    
                    mysqli_stmt_bind_param(
                        $archive_stmt,
                        "iissssssi",
                        $deceased_id,
                        $plot_id,
                        $first_name,
                        $last_name,
                        $date_of_birth,
                        $date_of_death,
                        $date_of_burial,
                        $reason,
                        $archived_by
                    );
                    
                    if (!mysqli_stmt_execute($archive_stmt)) {
                        throw new Exception("Failed to execute archive statement: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($archive_stmt);
                    
                    // Delete from deceased_records
                    $delete_query = "DELETE FROM deceased_records WHERE record_id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    if (!$delete_stmt) {
                        throw new Exception("Failed to prepare delete statement: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($delete_stmt, "i", $record['record_id']);
                    if (!mysqli_stmt_execute($delete_stmt)) {
                        throw new Exception("Failed to execute delete statement: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($delete_stmt);
                    
                    // Commit transaction
                    mysqli_commit($conn);
                    
                    $archived_count++;
                    // Track plot_id to check if it should be freed
                    if (!in_array($plot_id, $freed_plots)) {
                        $freed_plots[] = $plot_id;
                    }
                    
                } catch (Exception $e) {
                    // Rollback on error
                    mysqli_rollback($conn);
                    // Continue with next record even if this one fails
                    error_log("Failed to archive deceased record {$record['record_id']}: " . $e->getMessage());
                }
            }
            
            mysqli_free_result($expired_result);
            
            // Free plots that have no remaining deceased records
            $freed_count = 0;
            foreach ($freed_plots as $plot_id) {
                // Check if there are any remaining deceased records for this plot
                $check_query = "SELECT COUNT(*) as count FROM deceased_records WHERE plot_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                if ($check_stmt) {
                    mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    $check_row = mysqli_fetch_assoc($check_result);
                    
                    if ($check_row && (int) $check_row['count'] === 0) {
                        // No more deceased records, free the plot
                        $update_query = "UPDATE plots SET status = 'available' WHERE plot_id = ?";
                        $update_stmt = mysqli_prepare($conn, $update_query);
                        if ($update_stmt) {
                            mysqli_stmt_bind_param($update_stmt, "i", $plot_id);
                            if (mysqli_stmt_execute($update_stmt)) {
                                $freed_count++;
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    }
                    mysqli_stmt_close($check_stmt);
                }
            }
            
            $summary['archived'] = $archived_count;
            $summary['freed_plots'] = $freed_count;
        }
    } else {
        $summary['archived'] = 0;
        $summary['freed_plots'] = 0;
    }

    // 3) Update contracts that need renewal (30 days before expiration)
    $renewal_query = "UPDATE plots 
                      SET contract_status = 'renewal_needed' 
                      WHERE contract_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                      AND contract_status = 'active'";
    $result = mysqli_query($conn, $renewal_query);
    if ($result) {
        $summary['renewal_needed'] = mysqli_affected_rows($conn);
    }

    return $summary;
}


