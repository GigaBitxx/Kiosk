<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';
require_once '../admin/includes/logging.php';

// Run contract maintenance on each page load so expired contracts
// immediately archive deceased records and free plots
require_once __DIR__ . '/contract_maintenance.php';
run_contract_maintenance($conn, false);

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Initialize error variable early to allow restore flow to set it
$error = null;

if (!isset($_GET['id'])) {
    header('Location: plots.php');
    exit();
}

$plot_id = intval($_GET['id']);

// Determine where to go back (plots list by default)
$from = $_GET['from'] ?? '';
$section_from = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$backUrl = 'plots.php';
if ($from === 'maps') {
    $backUrl = 'maps.php';
} elseif ($from === 'section' && $section_from > 0) {
    $backUrl = 'section_layout.php?section_id=' . $section_from;
}

// Detect restore flow when coming from archived contract records
$is_restore_flow = (isset($_GET['restore']) && $_GET['restore'] === '1');

// If user cancels a restore, safely revert the plot status back to available
// Only revert if plot has no deceased records (meaning it was set to reserved during restore)
if (isset($_GET['cancel_restore']) && $_GET['cancel_restore'] === '1') {
    // Check if plot has deceased records
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
    $use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
    
    $has_deceased = false;
    if ($use_deceased_records) {
        $check_deceased = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM deceased_records WHERE plot_id = ?");
        if ($check_deceased) {
            mysqli_stmt_bind_param($check_deceased, "i", $plot_id);
            mysqli_stmt_execute($check_deceased);
            $result = mysqli_stmt_get_result($check_deceased);
            $row = mysqli_fetch_assoc($result);
            $has_deceased = ($row && (int)$row['cnt'] > 0);
            mysqli_stmt_close($check_deceased);
        }
    } else {
        $check_deceased = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM deceased WHERE plot_id = ?");
        if ($check_deceased) {
            mysqli_stmt_bind_param($check_deceased, "i", $plot_id);
            mysqli_stmt_execute($check_deceased);
            $result = mysqli_stmt_get_result($check_deceased);
            $row = mysqli_fetch_assoc($result);
            $has_deceased = ($row && (int)$row['cnt'] > 0);
            mysqli_stmt_close($check_deceased);
        }
    }
    
    // Only revert to available if plot has no deceased records (was set to reserved during restore)
    if (!$has_deceased) {
        $reset_query = "UPDATE plots SET status = 'available' WHERE plot_id = ? AND status = 'reserved'";
        if ($reset_stmt = mysqli_prepare($conn, $reset_query)) {
            mysqli_stmt_bind_param($reset_stmt, "i", $plot_id);
            mysqli_stmt_execute($reset_stmt);
            mysqli_stmt_close($reset_stmt);
        }
    }

    // Clean restore-related flags from URL and reload normal plot view
    $redirect_params = $_GET;
    unset($redirect_params['restore'], $redirect_params['cancel_restore'], $redirect_params['archive_id']);
    $qs = http_build_query($redirect_params);
    header('Location: plot_details.php' . ($qs ? '?' . $qs : ''));
    exit();
}

// When coming from restore flow, check if plot is occupied and find next available plot if needed
if ($is_restore_flow) {
    // First, check the current plot's status
    $check_plot_query = "SELECT plot_id, section_id, `row_number`, plot_number, status FROM plots WHERE plot_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_plot_query);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $current_plot = mysqli_fetch_assoc($check_result);
        mysqli_stmt_close($check_stmt);
        
        // If plot is occupied, find the next available plot in the same section
        if ($current_plot && $current_plot['status'] === 'occupied') {
            $section_id = (int)$current_plot['section_id'];
            $row_number = (int)$current_plot['row_number'];
            $plot_number = $current_plot['plot_number'];
            
            // Try to find next available plot in the same row first
            $next_plot_query = "SELECT plot_id FROM plots 
                               WHERE section_id = ? 
                               AND `row_number` = ? 
                               AND status = 'available' 
                               AND CAST(plot_number AS UNSIGNED) > CAST(? AS UNSIGNED)
                               ORDER BY CAST(plot_number AS UNSIGNED) ASC 
                               LIMIT 1";
            $next_stmt = mysqli_prepare($conn, $next_plot_query);
            $found_plot_id = null;
            
            if ($next_stmt) {
                mysqli_stmt_bind_param($next_stmt, "iis", $section_id, $row_number, $plot_number);
                mysqli_stmt_execute($next_stmt);
                $next_result = mysqli_stmt_get_result($next_stmt);
                $next_plot = mysqli_fetch_assoc($next_result);
                if ($next_plot) {
                    $found_plot_id = (int)$next_plot['plot_id'];
                }
                mysqli_stmt_close($next_stmt);
            }
            
            // If not found in same row, search in next rows of the same section
            if (!$found_plot_id) {
                $next_row_query = "SELECT plot_id FROM plots 
                                  WHERE section_id = ? 
                                  AND row_number > ? 
                                  AND status = 'available' 
                                  ORDER BY row_number ASC, CAST(plot_number AS UNSIGNED) ASC 
                                  LIMIT 1";
                $next_row_stmt = mysqli_prepare($conn, $next_row_query);
                if ($next_row_stmt) {
                    mysqli_stmt_bind_param($next_row_stmt, "ii", $section_id, $row_number);
                    mysqli_stmt_execute($next_row_stmt);
                    $next_row_result = mysqli_stmt_get_result($next_row_stmt);
                    $next_row_plot = mysqli_fetch_assoc($next_row_result);
                    if ($next_row_plot) {
                        $found_plot_id = (int)$next_row_plot['plot_id'];
                    }
                    mysqli_stmt_close($next_row_stmt);
                }
            }
            
            // If still not found, search from the beginning of the section
            if (!$found_plot_id) {
                $any_plot_query = "SELECT plot_id FROM plots 
                                  WHERE section_id = ? 
                                  AND status = 'available' 
                                  ORDER BY `row_number` ASC, CAST(plot_number AS UNSIGNED) ASC 
                                  LIMIT 1";
                $any_stmt = mysqli_prepare($conn, $any_plot_query);
                if ($any_stmt) {
                    mysqli_stmt_bind_param($any_stmt, "i", $section_id);
                    mysqli_stmt_execute($any_stmt);
                    $any_result = mysqli_stmt_get_result($any_stmt);
                    $any_plot = mysqli_fetch_assoc($any_result);
                    if ($any_plot) {
                        $found_plot_id = (int)$any_plot['plot_id'];
                    }
                    mysqli_stmt_close($any_stmt);
                }
            }
            
            // If we found an available plot, redirect to it
            if ($found_plot_id) {
                $archive_id = isset($_GET['archive_id']) ? (int)$_GET['archive_id'] : 0;
                $redirect_params = [
                    'id' => $found_plot_id,
                    'restore' => '1'
                ];
                if ($archive_id > 0) {
                    $redirect_params['archive_id'] = $archive_id;
                }
                header('Location: plot_details.php?' . http_build_query($redirect_params));
                exit();
            } else {
                // No available plot found - set error message to display to user
                $error = "The original plot is occupied and no available plot was found in this section. Please select a different plot manually.";
            }
        }
        
        // If plot is available or reserved, proceed with normal restore flow
        if ($current_plot && ($current_plot['status'] === 'available' || $current_plot['status'] === 'reserved')) {
            $reserve_query = "UPDATE plots SET status = 'reserved' WHERE plot_id = ? AND status = 'available'";
            if ($reserve_stmt = mysqli_prepare($conn, $reserve_query)) {
                mysqli_stmt_bind_param($reserve_stmt, "i", $plot_id);
                mysqli_stmt_execute($reserve_stmt);
                mysqli_stmt_close($reserve_stmt);
            }
        }
    }
}

// If we are in restore flow, fetch archived contract data to pre-fill the form
$archive_id = isset($_GET['archive_id']) ? (int)$_GET['archive_id'] : 0;
$restore_source = null;
if ($is_restore_flow) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_contracts'");
    $has_archived_table = $table_check && mysqli_num_rows($table_check) > 0;
    if ($has_archived_table) {
        if ($archive_id > 0) {
            $restore_sql = "SELECT 
                                archive_id,
                                deceased_name,
                                burial_date,
                                date_of_death,
                                contract_start_date,
                                contract_end_date,
                                contract_type,
                                contract_status,
                                contract_notes,
                                renewal_reminder_date
                            FROM archived_contracts
                            WHERE archive_id = ?
                            LIMIT 1";
            if ($restore_stmt = mysqli_prepare($conn, $restore_sql)) {
                mysqli_stmt_bind_param($restore_stmt, "i", $archive_id);
                if (mysqli_stmt_execute($restore_stmt)) {
                    $restore_result = mysqli_stmt_get_result($restore_stmt);
                    $restore_source = mysqli_fetch_assoc($restore_result) ?: null;
                }
                mysqli_stmt_close($restore_stmt);
            }
        }
        // Fallback: if no restore data yet (missing or invalid archive_id), use latest archive for this plot
        if (!$restore_source) {
            $fallback_sql = "SELECT 
                                archive_id,
                                deceased_name,
                                burial_date,
                                date_of_death,
                                contract_start_date,
                                contract_end_date,
                                contract_type,
                                contract_status,
                                contract_notes,
                                renewal_reminder_date
                            FROM archived_contracts
                            WHERE plot_id = ?
                            ORDER BY archived_at DESC
                            LIMIT 1";
            if ($fallback_stmt = mysqli_prepare($conn, $fallback_sql)) {
                mysqli_stmt_bind_param($fallback_stmt, "i", $plot_id);
                if (mysqli_stmt_execute($fallback_stmt)) {
                    $fallback_result = mysqli_stmt_get_result($fallback_stmt);
                    $restore_source = mysqli_fetch_assoc($fallback_result) ?: null;
                    if ($restore_source && !empty($restore_source['archive_id'])) {
                        $archive_id = (int)$restore_source['archive_id'];
                    }
                }
                mysqli_stmt_close($fallback_stmt);
            }
        }
    }
}

// Build current URL with query parameters for "from" parameter in contract links
$current_url_params = $_GET;
unset($current_url_params['success']); // Remove success message from URL
$current_plot_url = 'plot_details.php' . (!empty($current_url_params) ? '?' . http_build_query($current_url_params) : '');

// Determine which deceased table is available (new: deceased_records, legacy: deceased)
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;

if ($use_deceased_records) {
    // Use new deceased_records schema
    $query = "SELECT 
                p.*, 
                s.section_name, 
                s.section_code,
                d.record_id,
                d.full_name,
                d.date_of_birth,
                d.date_of_death,
                d.burial_date
              FROM plots p
              LEFT JOIN sections s ON p.section_id = s.section_id
              LEFT JOIN deceased_records d ON p.plot_id = d.plot_id
              WHERE p.plot_id = ?";
} else {
    // Fallback to legacy deceased table
    $query = "SELECT 
                p.*, 
                s.section_name, 
                s.section_code,
                d.deceased_id AS record_id,
                d.first_name,
                d.last_name,
                CONCAT(d.first_name, ' ', d.last_name) AS full_name,
                d.date_of_birth,
                d.date_of_death,
                d.date_of_burial AS burial_date
              FROM plots p
              LEFT JOIN sections s ON p.section_id = s.section_id
              LEFT JOIN deceased d ON p.plot_id = d.plot_id
              WHERE p.plot_id = ?";
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $plot_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$plot = mysqli_fetch_assoc($result);

// Check if exhumation/transfer feature is available (table exists)
$exhumation_enabled = false;
$exhum_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'exhumation_requests'");
if ($exhum_table_check && mysqli_num_rows($exhum_table_check) > 0) {
    $exhumation_enabled = true;
}

// Pre-load sections for target plot selection (used by exhumation section)
$sections = [];
if ($exhumation_enabled) {
    // Only show sections that still have plots (prevents showing "deleted" / empty sections)
    $sections_query = "SELECT s.section_id, s.section_name, s.section_code
                       FROM sections s
                       WHERE EXISTS (SELECT 1 FROM plots p WHERE p.section_id = s.section_id)
                       ORDER BY s.section_code, s.section_name";
    $sections_result = mysqli_query($conn, $sections_query);
    if ($sections_result) {
        while ($row = mysqli_fetch_assoc($sections_result)) {
            $sections[] = $row;
        }
    }
}

// Prepare name pieces for the edit form (supports both full_name and first/last schema)
$existing_first_name = $plot['first_name'] ?? '';
$existing_last_name = $plot['last_name'] ?? '';

if ($use_deceased_records && !empty($plot['full_name'])) {
    $name_parts = explode(' ', $plot['full_name'], 2);
    $existing_first_name = $name_parts[0];
    $existing_last_name = $name_parts[1] ?? '';
}

// Default form values for dates & contract fields from current plot/deceased data
$form_date_of_birth = $plot['date_of_birth'] ?? '';
$form_date_of_death = $plot['date_of_death'] ?? '';
$form_date_of_burial = $plot['date_of_burial'] ?? ($plot['burial_date'] ?? '');

$form_contract_status = $plot['contract_status'] ?? 'active';
$form_contract_start_date = $plot['contract_start_date'] ?? '';
$form_contract_end_date = $plot['contract_end_date'] ?? '';
$form_contract_notes = $plot['contract_notes'] ?? '';
$form_renewal_reminder_date = $plot['renewal_reminder_date'] ?? '';

// When restoring from an archived contract, prefer values from the archived record
if ($is_restore_flow && $restore_source) {
    if (!empty($restore_source['deceased_name'])) {
        $name_parts = explode(' ', $restore_source['deceased_name'], 2);
        $existing_first_name = $name_parts[0];
        $existing_last_name = $name_parts[1] ?? '';
    }

    if (!empty($restore_source['date_of_death']) && $restore_source['date_of_death'] !== '0000-00-00') {
        $form_date_of_death = $restore_source['date_of_death'];
    }

    if (!empty($restore_source['burial_date']) && $restore_source['burial_date'] !== '0000-00-00') {
        $form_date_of_burial = $restore_source['burial_date'];
    }

    if (!empty($restore_source['contract_status'])) {
        $form_contract_status = $restore_source['contract_status'];
    }

    if (!empty($restore_source['contract_start_date']) && $restore_source['contract_start_date'] !== '0000-00-00') {
        $form_contract_start_date = $restore_source['contract_start_date'];
    }

    if (!empty($restore_source['contract_end_date']) && $restore_source['contract_end_date'] !== '0000-00-00') {
        $form_contract_end_date = $restore_source['contract_end_date'];
    }

    if (array_key_exists('contract_notes', $restore_source)) {
        $form_contract_notes = $restore_source['contract_notes'];
    }

    if (!empty($restore_source['renewal_reminder_date']) && $restore_source['renewal_reminder_date'] !== '0000-00-00') {
        $form_renewal_reminder_date = $restore_source['renewal_reminder_date'];
    }
}

// Fetch all deceased records for this plot when using deceased_records table
$all_deceased = [];
if ($use_deceased_records) {
    $list_query = "SELECT record_id, full_name, date_of_birth, date_of_death, burial_date
                   FROM deceased_records
                   WHERE plot_id = ?
                   ORDER BY COALESCE(burial_date, date_of_death, date_of_birth) ASC, record_id ASC";
    $list_stmt = mysqli_prepare($conn, $list_query);
    mysqli_stmt_bind_param($list_stmt, "i", $plot_id);
    mysqli_stmt_execute($list_stmt);
    $list_result = mysqli_stmt_get_result($list_stmt);
    while ($row = mysqli_fetch_assoc($list_result)) {
        $all_deceased[] = $row;
    }
}

// Handle form submission
// Only initialize error if not already set (e.g., from restore flow)
if (!isset($error)) {
    $error = null;
}
$success_message = null;
$exhumation_success = false;
$has_pending_exhumation = false;

// Handle success messages from URL parameters
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'contract_updated') {
        $success_message = "Contract information updated successfully!";
    } elseif ($_GET['success'] === 'exhumation_requested') {
        $success_message = "Exhumation / transfer request submitted for admin approval.";
        $exhumation_success = true;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        // Handle status update
        $new_status = mysqli_real_escape_string($conn, $_POST['status']);
        $plot_id_post = intval($_POST['plot_id']);
        
        $update_query = "UPDATE plots SET status = ? WHERE plot_id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $plot_id_post);
        
        if (mysqli_stmt_execute($stmt)) {
            header("Location: plot_details.php?id=$plot_id");
            exit();
        } else {
            $error = "Error updating plot status: " . mysqli_error($conn);
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_contract') {
        // Handle contract information update
        $contract_start_date = $_POST['contract_start_date'] ?? null;
        $contract_end_date = $_POST['contract_end_date'] ?? null;
        $contract_type = 'temporary';
        $contract_status = $_POST['contract_status'] ?? 'active';
        $contract_notes = $_POST['contract_notes'] ?? null;
        $renewal_reminder_date = $_POST['renewal_reminder_date'] ?? null;
        
        $update_query = "UPDATE plots SET 
                         contract_start_date = ?, 
                         contract_end_date = ?, 
                         contract_type = ?, 
                         contract_status = ?, 
                         contract_notes = ?, 
                         renewal_reminder_date = ?
                         WHERE plot_id = ?";
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "ssssssi", 
            $contract_start_date, 
            $contract_end_date, 
            $contract_type, 
            $contract_status, 
            $contract_notes, 
            $renewal_reminder_date, 
            $plot_id
        );
        
        if (mysqli_stmt_execute($stmt)) {
            header("Location: plot_details.php?id=$plot_id&success=contract_updated");
            exit();
        } else {
            $error = "Error updating contract information: " . mysqli_error($conn);
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'request_exhumation') {
        // Handle exhumation / transfer request (requires admin approval)
        if (!$exhumation_enabled) {
            $error = "Exhumation feature is not available. Please contact an administrator.";
        } else {
            $source_plot_id = $plot_id;
            $target_plot_id = isset($_POST['target_plot_id']) ? intval($_POST['target_plot_id']) : 0;
            $notes = trim($_POST['exhumation_notes'] ?? '');

            $local_errors = [];

            if ($target_plot_id <= 0) {
                $local_errors[] = "Please provide a valid target plot ID.";
            }

            if ($target_plot_id === $source_plot_id) {
                $local_errors[] = "Target plot must be different from the current plot.";
            }

            // Validate and resolve deceased record to transfer
            $deceased_record_id = null;
            $deceased_id = null;

            if ($use_deceased_records) {
                $deceased_record_id = isset($_POST['selected_record_id']) ? intval($_POST['selected_record_id']) : 0;

                if ($deceased_record_id <= 0) {
                    $local_errors[] = "Please select which deceased record to transfer.";
                } else {
                    $check_stmt = mysqli_prepare($conn, "SELECT record_id FROM deceased_records WHERE record_id = ? AND plot_id = ?");
                    mysqli_stmt_bind_param($check_stmt, "ii", $deceased_record_id, $source_plot_id);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    if (!mysqli_fetch_assoc($check_result)) {
                        $local_errors[] = "Selected deceased record does not belong to this plot.";
                    }
                }
            } else {
                $deceased_id = isset($_POST['selected_deceased_id']) ? intval($_POST['selected_deceased_id']) : 0;

                if ($deceased_id <= 0) {
                    $local_errors[] = "Missing deceased record to transfer.";
                } else {
                    $check_stmt = mysqli_prepare($conn, "SELECT deceased_id FROM deceased WHERE deceased_id = ? AND plot_id = ?");
                    mysqli_stmt_bind_param($check_stmt, "ii", $deceased_id, $source_plot_id);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    if (!mysqli_fetch_assoc($check_result)) {
                        $local_errors[] = "Selected deceased record does not belong to this plot.";
                    }
                }
            }

            // Validate target plot (ensure it exists; allow any status including occupied so transfers can add to existing plots)
            if ($target_plot_id > 0) {
                $target_stmt = mysqli_prepare($conn, "SELECT plot_id, status FROM plots WHERE plot_id = ?");
                mysqli_stmt_bind_param($target_stmt, "i", $target_plot_id);
                mysqli_stmt_execute($target_stmt);
                $target_result = mysqli_stmt_get_result($target_stmt);
                $target_row = mysqli_fetch_assoc($target_result);

                if (!$target_row) {
                    $local_errors[] = "Target plot was not found.";
                }
                // Explicitly allow transfers to occupied plots (no status restriction)
            }

            if (empty($local_errors)) {
                // Resolve deceased name for logging and admin UI
                $deceased_name = '';
                if ($use_deceased_records) {
                    if (!empty($all_deceased)) {
                        foreach ($all_deceased as $dec) {
                            if ((int)$dec['record_id'] === $deceased_record_id) {
                                $deceased_name = $dec['full_name'];
                                break;
                            }
                        }
                    }
                    if ($deceased_name === '' && !empty($plot['full_name'])) {
                        $deceased_name = $plot['full_name'];
                    }
                } else {
                    $deceased_name = trim(($plot['first_name'] ?? '') . ' ' . ($plot['last_name'] ?? ''));
                }

                if ($deceased_name === '') {
                    $deceased_name = 'Unknown';
                }

                $requested_by = isset($_SESSION['staff_user_id']) ? intval($_SESSION['staff_user_id']) : null;

                $insert_sql = "INSERT INTO exhumation_requests (
                        source_plot_id,
                        target_plot_id,
                        deceased_record_id,
                        deceased_id,
                        use_deceased_records,
                        deceased_name,
                        requested_by,
                        status,
                        notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";

                $insert_stmt = mysqli_prepare($conn, $insert_sql);
                $use_flag = $use_deceased_records ? 1 : 0;
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "iiiisiss",
                    $source_plot_id,
                    $target_plot_id,
                    $deceased_record_id,
                    $deceased_id,
                    $use_flag,
                    $deceased_name,
                    $requested_by,
                    $notes
                );

                if (mysqli_stmt_execute($insert_stmt)) {
                    if (function_exists('log_action')) {
                        log_action(
                            'Alert',
                            "Exhumation request created for {$deceased_name} from plot ID {$source_plot_id} to plot ID {$target_plot_id}.",
                            $requested_by
                        );
                    }
                    header("Location: plot_details.php?id=$plot_id&success=exhumation_requested");
                    exit();
                } else {
                    $error = "Error creating exhumation request: " . mysqli_error($conn);
                }
            } else {
                $error = implode("<br>", $local_errors);
            }
        }
    } else {
        // Handle deceased information update
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $date_of_death = trim($_POST['date_of_death'] ?? '');
        $date_of_burial = trim($_POST['date_of_burial'] ?? '');

        // Convert empty date strings to NULL for proper database handling
        $date_of_birth = !empty($date_of_birth) ? mysqli_real_escape_string($conn, $date_of_birth) : null;
        $date_of_death = !empty($date_of_death) ? mysqli_real_escape_string($conn, $date_of_death) : null;
        $date_of_burial = !empty($date_of_burial) ? mysqli_real_escape_string($conn, $date_of_burial) : null;

        // Validate dates
        $errors = [];
        if (!empty($date_of_birth) && !empty($date_of_death) && strtotime($date_of_birth) > strtotime($date_of_death)) {
            $errors[] = "Date of birth cannot be after date of death";
        }
        if (!empty($date_of_death) && !empty($date_of_burial) && strtotime($date_of_death) > strtotime($date_of_burial)) {
            $errors[] = "Date of death cannot be after date of burial";
        }

        if (empty($errors)) {
            if ($use_deceased_records) {
                $check_query = "SELECT record_id FROM deceased_records WHERE plot_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);

                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $query = "UPDATE deceased_records SET 
                              full_name = ?, 
                              date_of_birth = ?, 
                              date_of_death = ?, 
                              burial_date = ? 
                              WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssssi", $full_name, $date_of_birth, $date_of_death, $date_of_burial, $plot_id);
                } else {
                    $query = "INSERT INTO deceased_records (full_name, date_of_birth, date_of_death, burial_date, plot_id) 
                              VALUES (?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssssi", $full_name, $date_of_birth, $date_of_death, $date_of_burial, $plot_id);
                }
            } else {
                $first_name_db = mysqli_real_escape_string($conn, $first_name);
                $last_name_db = mysqli_real_escape_string($conn, $last_name);

                $check_query = "SELECT deceased_id FROM deceased WHERE plot_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);

                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $query = "UPDATE deceased SET 
                              first_name = ?, 
                              last_name = ?, 
                              date_of_birth = ?, 
                              date_of_death = ?, 
                              date_of_burial = ? 
                              WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssssi", $first_name_db, $last_name_db, $date_of_birth, $date_of_death, $date_of_burial, $plot_id);
                } else {
                    $query = "INSERT INTO deceased (first_name, last_name, date_of_birth, date_of_death, date_of_burial, plot_id) 
                              VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssssi", $first_name_db, $last_name_db, $date_of_birth, $date_of_death, $date_of_burial, $plot_id);
                }
            }

            if (isset($stmt) && mysqli_stmt_execute($stmt)) {
                mysqli_query($conn, "UPDATE plots SET status = 'occupied' WHERE plot_id = " . intval($plot_id));

                if (isset($_POST['action']) && $_POST['action'] === 'update_all') {
                    $contract_start_date = $_POST['contract_start_date'] ?? null;
                    $contract_end_date = $_POST['contract_end_date'] ?? null;
                    $contract_type = 'temporary';
                    $contract_status = $_POST['contract_status'] ?? 'active';
                    $contract_notes = $_POST['contract_notes'] ?? null;
                    $renewal_reminder_date = $_POST['renewal_reminder_date'] ?? null;

                    $update_query = "UPDATE plots SET 
                                     contract_start_date = ?, 
                                     contract_end_date = ?, 
                                     contract_type = ?, 
                                     contract_status = ?, 
                                     contract_notes = ?, 
                                     renewal_reminder_date = ?
                                     WHERE plot_id = ?";

                    $contract_stmt = mysqli_prepare($conn, $update_query);
                    if ($contract_stmt) {
                        mysqli_stmt_bind_param($contract_stmt, "ssssssi", $contract_start_date, $contract_end_date, $contract_type, $contract_status, $contract_notes, $renewal_reminder_date, $plot_id);
                        if (!mysqli_stmt_execute($contract_stmt)) {
                            $error = "Error updating contract information: " . mysqli_error($conn);
                        }
                    }
                }

                if (!isset($error)) {
                    // If this update was triggered from restoring an archived contract,
                    // remove the archived copy now that the record is active again.
                    if ($is_restore_flow && $archive_id > 0) {
                        $delete_archive_sql = "DELETE FROM archived_contracts WHERE archive_id = ?";
                        $delete_archive_stmt = mysqli_prepare($conn, $delete_archive_sql);
                        if ($delete_archive_stmt) {
                            mysqli_stmt_bind_param($delete_archive_stmt, "i", $archive_id);
                            if (!mysqli_stmt_execute($delete_archive_stmt)) {
                                $error = "Record restored, but failed to remove archived contract: " . mysqli_error($conn);
                            }
                            mysqli_stmt_close($delete_archive_stmt);
                        } else {
                            $error = "Record restored, but could not prepare archive cleanup: " . mysqli_error($conn);
                        }
                    }

                    header("Location: plot_details.php?id=$plot_id");
                    exit();
                }
            } else {
                $error = "Error saving deceased information: " . mysqli_error($conn);
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Check if there is any pending exhumation request for this plot
if ($exhumation_enabled) {
    $pending_sql = "SELECT COUNT(*) AS cnt 
                    FROM exhumation_requests 
                    WHERE source_plot_id = ? AND status = 'pending'";
    $pending_stmt = mysqli_prepare($conn, $pending_sql);
    if ($pending_stmt) {
        mysqli_stmt_bind_param($pending_stmt, "i", $plot_id);
        mysqli_stmt_execute($pending_stmt);
        $pending_result = mysqli_stmt_get_result($pending_stmt);
        if ($pending_row = mysqli_fetch_assoc($pending_result)) {
            $has_pending_exhumation = ((int)$pending_row['cnt'] > 0);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <!-- Flatpickr datepicker for consistent MM/DD/YYYY date inputs -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        /* Page-specific styles */
        }
        .page-header {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: #000000;
            margin: 0;
            text-align: center;
            grid-column: 2;
        }
        .page-header .btn-back {
            grid-column: 1;
            justify-self: start;
        }
        .page-header .edit-inline-btn {
            grid-column: 3;
            justify-self: end;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            background: #f3f4f6;
            color: #374151;
        }
        .btn-back:hover {
            background: #e5e7eb;
            color: #1f2937;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            text-decoration: none;
        }
        .edit-inline-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            background: #2b4c7e;
            color: white;
        }
        .edit-inline-btn:hover {
            background: #1f3659;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            text-decoration: none;
        }
        .edit-inline-btn i {
            font-size: 20px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-available {
            background: #10b981; /* Solid green */
            color: white;
        }
        .status-reserved {
            background: #fbbf24; /* Solid yellow */
            color: white;
        }
        .status-occupied {
            background: #ef4444; /* Solid red */
            color: white;
        }
        .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: currentColor; /* Uses the color of the parent .status-badge */
        }
        .details-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        .details-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #222;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .detail-item {
            margin-bottom: 12px;
        }
        .detail-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }
        .detail-value {
            font-size: 16px;
            color: #222;
            font-weight: 500;
        }
        .contract-link-btn {
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            background: #e0f2f1;
            color: #047857;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .contract-link-btn:hover {
            background: #ccfbf1;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            color: #047857;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }
        .edit-modal-container .action-buttons {
            justify-content: flex-end;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #2b4c7e;
            color: #fff;
            border: none;
        }
        .btn-primary:hover {
            background: #1f3659;
        }
        .btn-secondary {
            background: #f8f9fa;
            color: #222;
            border: 1px solid #ddd;
        }
        .btn-secondary:hover {
            background: #e9ecef;
        }
        /* Full-screen Edit Modal */
        .edit-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            overflow-y: auto;
        }
        .edit-modal-overlay.show {
            display: block;
        }
        .edit-modal-container {
            min-height: 100vh;
            max-width: 95%;
            width: 100%;
            margin: 20px auto;
            padding: 40px;
            background: #f5f5f5;
            position: relative;
            border-radius: 8px;
        }
        .edit-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            position: relative;
        }
        .edit-modal-title {
            font-size: 24px;
            font-weight: 600;
            color: #222;
            margin: 0;
        }
        .edit-modal-close {
            position: absolute;
            right: 0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #e0e0e0;
            background: #ffffff;
            cursor: pointer;
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
        }
        .edit-modal-close:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            transform: translateY(-1px);
        }
        .form-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        .form-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #222;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .edit-modal-container .form-card .form-group {
            max-width: 600px;
        }
        .edit-modal-container .form-card .form-row .form-group {
            max-width: 100%;
        }
        /* Flatpickr datepicker positioning fix */
        .edit-modal-container {
            position: relative;
        }
        /* Ensure flatpickr calendar is positioned correctly */
        .flatpickr-calendar {
            z-index: 9999 !important;
        }
        .form-group {
            position: relative;
        }
        .form-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .form-control:focus {
            border-color: #2b4c7e;
            outline: none;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .alert-danger {
            background: #fee;
            color: #c00;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        .contract-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .contract-status.active { background: #d1edff; color: #0d6efd; }
        .contract-status.expired { background: #f8d7da; color: #dc3545; }
        .contract-status.renewal-needed { background: #fff3cd; color: #856404; }
        .contract-status.cancelled { background: #e2e3e5; color: #6c757d; }
        
        /* Notification bubble */
        .notification-bubble {
            position: fixed;
            top: 24px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            border-radius: 12px;
            color: #fff;
            font-weight: 500;
            font-size: 15px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            max-width: 500px;
            word-wrap: break-word;
        }
        
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .success-notification {
            background: linear-gradient(135deg, #00b894, #00a184);
        }
        
        .error-notification {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .notification-bubble i {
            font-size: 20px;
        }
        
        .notification-bubble span {
            display: inline-block;
        }
        
        /* Modal-specific notification bubble */
        .edit-modal-container .notification-bubble {
            position: relative;
            top: auto;
            right: auto;
            margin-bottom: 16px;
            max-width: 100%;
        }
        
        /* Confirmation Dialog */
        .confirmation-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        
        .confirmation-overlay.show {
            display: flex;
            opacity: 1;
        }
        
        .confirmation-dialog {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
            transform: scale(0.9);
            transition: transform 0.25s ease;
        }
        
        .confirmation-overlay.show .confirmation-dialog {
            transform: scale(1);
        }
        
        .confirmation-title {
            font-size: 18px;
            font-weight: 600;
            color: #222;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .confirmation-title i {
            font-size: 20px;
            color: #2b4c7e;
        }
        
        .confirmation-message {
            font-size: 14px;
            color: #555;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        
        .confirmation-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .confirmation-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        
        .confirmation-btn-cancel {
            background: #f3f4f6;
            color: #374151;
        }
        
        .confirmation-btn-cancel:hover {
            background: #e5e7eb;
        }
        
        .confirmation-btn-confirm {
            background: #2b4c7e;
            color: white;
        }
        
        .confirmation-btn-confirm:hover {
            background: #1f3659;
        }
    </style>
</head>
<body>
    <!-- Notification bubble for exhumation request -->
    <div id="exhumationNotification" class="notification-bubble success-notification" style="display: none;">
        <i class="bi bi-check-circle-fill"></i>
        <span></span>
    </div>
    
    <!-- Notification bubble for errors -->
    <div id="errorNotification" class="notification-bubble error-notification" style="display: none;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span></span>
    </div>
    
    <!-- Confirmation Dialog -->
    <div id="confirmationDialog" class="confirmation-overlay">
        <div class="confirmation-dialog">
            <div class="confirmation-title">
                <i class="bi bi-question-circle-fill"></i>
                <span>Confirm Action</span>
            </div>
            <div class="confirmation-message" id="confirmationMessage"></div>
            <div class="confirmation-buttons">
                <button type="button" class="confirmation-btn confirmation-btn-cancel" onclick="closeConfirmationDialog()">
                    Cancel
                </button>
                <button type="button" class="confirmation-btn confirmation-btn-confirm" id="confirmationConfirmBtn">
                    Confirm
                </button>
            </div>
        </div>
    </div>
    
    <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
        <div class="main">
            <div class="page-header">
                <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-back">
                    <i class="bx bx-arrow-back"></i> Back
                </a>
                <div class="page-title">Plot Details</div>
                <div style="width: 120px;"></div>
            </div>
            
            <div class="details-card">
                <div class="details-title d-flex justify-content-between align-items-center">
                    <span>Plot Information</span>
                    <?php if ($plot['status'] === 'reserved' || $plot['status'] === 'available'): ?>
                        <button onclick="openEditModal()" class="edit-inline-btn">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                    <?php endif; ?>
                </div>
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Section</div>
                        <div class="detail-value">
                            <?php 
                                $sectionDisplay = '—';
                                if (!empty($plot['section_name']) || !empty($plot['section_code'])) {
                                    $name = $plot['section_name'] ?? '';
                                    $code = $plot['section_code'] ?? '';
                                    $sectionDisplay = trim($name . ($code ? " ($code)" : ""));
                                }
                                echo htmlspecialchars($sectionDisplay);
                            ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Row</div>
                        <div class="detail-value">
                            <?php 
                                $rowLetter = chr(64 + (int)($plot['row_number'] ?? 1));
                                echo htmlspecialchars($rowLetter ? $rowLetter : '—');
                            ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Plot Number</div>
                        <div class="detail-value"><?php echo htmlspecialchars($plot['plot_number']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Plot Status</div>
                        <div class="detail-value">
                            <?php 
                                $plot_status = $plot['status'] ?? 'available';
                                $status_class = 'status-' . $plot_status;
                                $status_display = ucfirst($plot_status);
                            ?>
                            <span class="status-badge <?php echo htmlspecialchars($status_class); ?>">
                                <span class="status-dot"></span>
                                <?php echo htmlspecialchars($status_display); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($plot['record_id']) || !empty($plot['full_name']) || (!empty($all_deceased))): ?>
            <div class="details-card">
                <div class="details-title d-flex justify-content-between align-items-center">
                    <span>Deceased Information</span>
                    <?php if ($use_deceased_records && count($all_deceased) <= 1 && !empty($plot['record_id'])): ?>
                        <a
                            href="contract_management.php?record_id=<?php echo (int)$plot['record_id']; ?>&plot_id=<?php echo (int)$plot['plot_id']; ?>&from=<?php echo urlencode($current_plot_url); ?>"
                            class="contract-link-btn"
                            title="Manage Contract"
                        >
                            <i class="bi bi-file-text"></i> Contract
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ($use_deceased_records && count($all_deceased) > 1): ?>
                    <div class="details-grid">
                        <?php foreach ($all_deceased as $index => $dec): ?>
                            <div class="detail-item" style="grid-column: span 2;">
                                <div class="detail-label d-flex justify-content-between align-items-center">
                                    <span><?php echo 'Deceased #' . ($index + 1); ?></span>
                                    <a
                                        href="contract_management.php?record_id=<?php echo (int)$dec['record_id']; ?>&plot_id=<?php echo (int)$plot['plot_id']; ?>&from=<?php echo urlencode($current_plot_url); ?>"
                                        class="contract-link-btn"
                                        title="Manage Contract"
                                    >
                                        <i class="bi bi-file-text"></i> Contract
                                    </a>
                                </div>
                                <div class="detail-value" style="font-weight:600;">
                                    <?php echo htmlspecialchars($dec['full_name']); ?>
                                </div>
                                <div style="font-size:14px; color:#555; margin-top:4px;">
                                    <strong>Born:</strong>
                                    <?php 
                                        $dob = $dec['date_of_birth'] ?? '';
                                        $dob_timestamp = !empty($dob) ? strtotime($dob) : false;
                                        echo ($dob_timestamp && $dob_timestamp > 0 && date('Y', $dob_timestamp) > 1900) ? date('M d, Y', $dob_timestamp) : 'N/A';
                                    ?>
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <strong>Died:</strong>
                                    <?php 
                                        $dod = $dec['date_of_death'] ?? '';
                                        $dod_timestamp = !empty($dod) ? strtotime($dod) : false;
                                        echo ($dod_timestamp && $dod_timestamp > 0 && date('Y', $dod_timestamp) > 1900) ? date('M d, Y', $dod_timestamp) : 'N/A';
                                    ?>
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <strong>Burial:</strong>
                                    <?php echo !empty($dec['burial_date']) ? date('M d, Y', strtotime($dec['burial_date'])) : '—'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="details-grid">
                        <div class="detail-item" style="grid-column: span 2;">
                            <div class="detail-label">Deceased</div>
                            <div class="detail-value" style="font-weight:600;">
                                <?php echo htmlspecialchars($plot['full_name']); ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date of Birth</div>
                            <div class="detail-value">
                                <?php 
                                    $dob = $plot['date_of_birth'] ?? '';
                                    $dob_timestamp = !empty($dob) ? strtotime($dob) : false;
                                    echo ($dob_timestamp && $dob_timestamp > 0 && date('Y', $dob_timestamp) > 1900) ? date('M d, Y', $dob_timestamp) : 'N/A';
                                ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date of Death</div>
                            <div class="detail-value">
                                <?php 
                                    $dod = $plot['date_of_death'] ?? '';
                                    $dod_timestamp = !empty($dod) ? strtotime($dod) : false;
                                    echo ($dod_timestamp && $dod_timestamp > 0 && date('Y', $dod_timestamp) > 1900) ? date('M d, Y', $dod_timestamp) : 'N/A';
                                ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date of Burial</div>
                            <div class="detail-value">
                                <?php 
                                    $burial = $plot['burial_date'] ?? ($plot['date_of_burial'] ?? null);
                                    echo !empty($burial) ? date('M d, Y', strtotime($burial)) : '—';
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick access card/button to full Deceased Records page -->
                <div style="margin-top: 16px; border-top: 1px solid #eee; padding-top: 12px;">
                    <p style="font-size: 14px; color: #555; margin-bottom: 10px;">
                        View and manage all deceased records in the dedicated records page.
                    </p>
                    <div class="action-buttons" style="margin-top: 0;">
                        <?php
                            // Determine a primary deceased record to open in the modal
                            $target_record_id = 0;
                            if (!empty($plot['record_id'])) {
                                $target_record_id = (int)$plot['record_id'];
                            } elseif (!empty($all_deceased)) {
                                // Fallback to the first deceased entry for this plot
                                $first_dec = reset($all_deceased);
                                if (!empty($first_dec['record_id'])) {
                                    $target_record_id = (int)$first_dec['record_id'];
                                }
                            }

                            $deceased_records_url = 'deceased_records.php?search_plot=' . urlencode($plot['plot_number'] ?? '');
                            if ($target_record_id > 0) {
                                // Pass the record ID so the target page can auto-open the correct modal
                                $deceased_records_url .= '&id=' . $target_record_id;
                            }
                        ?>
                        <a
                            href="<?php echo htmlspecialchars($deceased_records_url); ?>"
                            class="btn btn-secondary"
                        >
                            <i class="bi bi-journal-bookmark"></i> Go to Deceased Records
                        </a>
                    </div>
                </div>

                <?php if ($exhumation_enabled && ($plot['status'] === 'occupied' || $plot['status'] === 'reserved')): ?>
                <!-- Exhumation / Transfer request section -->
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($use_deceased_records && $plot['status'] === 'occupied'): ?>
            <div class="details-card">
                <div class="details-title">Add Another Deceased</div>
                <p style="font-size:14px; color:#555; margin-bottom:12px;">
                    This plot already has at least one deceased record. You can add another deceased/urn to the same plot
                    (for example, multiple family members or urns in a single lot).
                </p>
                <div class="action-buttons">
                    <a href="add_deceased_record.php?plot_id=<?php echo (int)$plot_id; ?>" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Add Another Deceased to This Plot
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($exhumation_enabled && $plot['status'] === 'occupied'): ?>
            <!-- Exhumation / Transfer request section (moved below Add Another Deceased / Urn) -->
            <div class="details-card">
                <div class="details-title">Exhumation / Transfer Request</div>
                <?php if (!empty($success_message) && $exhumation_success): ?>
                    <div id="exhumationSuccessMessage" data-message="<?php echo htmlspecialchars($success_message); ?>" style="display: none;"></div>
                <?php endif; ?>

                <?php if ($has_pending_exhumation): ?>
                    <div class="alert alert-info" style="margin-top: 4px; margin-bottom: 12px; font-size: 13px;">
                        <strong>Pending request:</strong> An exhumation / transfer request for this plot is currently awaiting admin approval.
                    </div>
                <?php endif; ?>
                <p style="font-size: 14px; color: #555; margin-bottom: 10px;">
                    Request an <strong>exhumation / transfer</strong> of this deceased to another plot. Requests are sent
                    to an administrator for approval before any changes are applied.
                </p>
                <form method="POST" class="row g-2" style="display:flex; flex-direction:column; gap:12px;">
                    <input type="hidden" name="action" value="request_exhumation">
                    <input type="hidden" name="source_plot_id" value="<?php echo (int)$plot_id; ?>">

                    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                        <?php if ($use_deceased_records): ?>
                            <?php if (count($all_deceased) > 1): ?>
                                <div style="flex:1; min-width:220px;">
                                    <div class="detail-label" style="font-size: 13px; color:#555; margin-bottom:4px;">
                                        Select Deceased to Transfer
                                    </div>
                                    <select name="selected_record_id" class="form-control" required>
                                        <option value="">Choose deceased...</option>
                                        <?php foreach ($all_deceased as $dec): ?>
                                            <option value="<?php echo (int)$dec['record_id']; ?>">
                                                <?php echo htmlspecialchars($dec['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="selected_record_id" value="<?php echo (int)($plot['record_id'] ?? 0); ?>">
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="hidden" name="selected_deceased_id" value="<?php echo (int)($plot['record_id'] ?? 0); ?>">
                        <?php endif; ?>

                        <div style="flex:1; min-width:220px;">
                            <div class="detail-label" style="font-size: 13px; color:#555; margin-bottom:4px;">
                                Target Section
                            </div>
                            <select
                                name="target_section_id"
                                id="targetSectionSelect"
                                class="form-control"
                                required
                                data-default-section-id="<?php echo isset($plot['section_id']) ? (int)$plot['section_id'] : 0; ?>"
                            >
                                <option value="">Select section...</option>
                                <?php foreach ($sections as $section): ?>
                                    <option
                                        value="<?php echo (int)$section['section_id']; ?>"
                                        <?php echo (isset($plot['section_id']) && (int)$plot['section_id'] === (int)$section['section_id']) ? 'selected' : ''; ?>
                                    >
                                        <?php
                                            $label = trim(($section['section_code'] ?? '') . ' - ' . ($section['section_name'] ?? ''));
                                            echo htmlspecialchars($label ?: ('Section #' . $section['section_id']));
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="flex:1; min-width:160px;">
                            <div class="detail-label" style="font-size: 13px; color:#555; margin-bottom:4px;">
                                Target Row
                            </div>
                            <select
                                name="target_row_number"
                                id="targetRowSelect"
                                class="form-control"
                                required
                                data-default-row-number="<?php echo isset($plot['row_number']) ? (int)$plot['row_number'] : 0; ?>"
                            >
                                <option value="">Select row...</option>
                            </select>
                        </div>

                        <div style="flex:1; min-width:200px;">
                            <div class="detail-label" style="font-size: 13px; color:#555; margin-bottom:4px;">
                                Target Plot
                            </div>
                            <select
                                name="target_plot_id"
                                id="targetPlotSelect"
                                class="form-control"
                                required
                                data-current-plot-id="<?php echo (int)$plot_id; ?>"
                            >
                                <option value="">Select plot...</option>
                            </select>
                          
                        </div>
                    </div>

                    <div>
                        <div class="detail-label" style="font-size: 13px; color:#555; margin-bottom:4px;">
                            Reason / Notes (optional)
                        </div>
                        <textarea
                            class="form-control"
                            name="exhumation_notes"
                            rows="3"
                            maxlength="255"
                            placeholder="Explain why this exhumation / transfer is being requested..."
                        ></textarea>
                    </div>

                    <div class="action-buttons" style="margin-top: 0;">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-arrow-left-right"></i> Request Exhumation / Transfer
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Full-screen Edit Modal -->
    <div id="editModal" class="edit-modal-overlay">
        <div class="edit-modal-container">
            <div class="edit-modal-header">
                <div class="edit-modal-title"><?php echo (!empty($plot['record_id']) || !empty($plot['full_name'])) ? 'Edit' : 'Add'; ?> Deceased Information</div>
                <button class="edit-modal-close" onclick="handleCloseModal(<?php echo (int)$plot_id; ?>)">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>
            
            <!-- Error notification bubble inside modal -->
            <div id="modalErrorNotification" class="notification-bubble error-notification" style="display: none;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span></span>
            </div>
            
            <?php if (isset($error)): ?>
            <div id="errorMessage" data-error="<?php echo htmlspecialchars($error, ENT_QUOTES); ?>" style="display: none;"></div>
            <?php endif; ?>
            
            <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="form-title">Plot Information</div>
                <div class="form-group">
                    <div class="form-label">Section</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <?php 
                            $sectionDisplay = '—';
                            if (!empty($plot['section_name']) || !empty($plot['section_code'])) {
                                $name = $plot['section_name'] ?? '';
                                $code = $plot['section_code'] ?? '';
                                $sectionDisplay = trim($name . ($code ? " ($code)" : ""));
                            }
                            echo htmlspecialchars($sectionDisplay);
                        ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-label">Location</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <?php 
                            $rowLetter = chr(64 + (int)($plot['row_number'] ?? 1));
                            $displayPlot = $rowLetter . $plot['plot_number'];
                            echo htmlspecialchars($displayPlot); 
                        ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-label">Plot Status</div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="form-control" style="background: #f8f9fa; flex: 1;">
                            <?php 
                                $plot_status = $plot['status'] ?? 'available';
                                $status_class = 'status-' . $plot_status;
                                $status_display = ucfirst($plot_status);
                            ?>
                            <span class="status-badge <?php echo htmlspecialchars($status_class); ?>">
                                <span class="status-dot"></span>
                                <?php echo htmlspecialchars($status_display); ?>
                            </span>
                        </div>
                        <?php if ($plot_status === 'reserved' || $plot_status === 'occupied'): ?>
                            <form method="POST" id="setAvailableForm" style="display: inline; margin: 0;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="plot_id" value="<?php echo (int)$plot_id; ?>">
                                <input type="hidden" name="status" value="available">
                                <button type="button" class="btn btn-primary" style="white-space: nowrap;" onclick="showStatusChangeConfirmation()">
                                    <i class="bi bi-check-circle"></i> Set to Available
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($plot['status'] === 'occupied' || $plot['status'] === 'reserved'): ?>
            <form method="POST" id="editPlotForm">
                <input type="hidden" name="action" value="update_all">

                <div class="form-card">
                    <div class="form-title">Deceased Information</div>
                
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($existing_first_name ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($existing_last_name ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date of Birth</label>
                        <input 
                            type="text" 
                            class="form-control date-mdY" 
                            name="date_of_birth" 
                            placeholder="mm/dd/yyyy"
                            value="<?php echo htmlspecialchars($form_date_of_birth ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date of Death</label>
                        <input 
                            type="text" 
                            class="form-control date-mdY" 
                            name="date_of_death" 
                            placeholder="mm/dd/yyyy"
                            value="<?php echo htmlspecialchars($form_date_of_death ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date of Burial</label>
                        <input 
                            type="text" 
                            class="form-control date-mdY" 
                            name="date_of_burial" 
                            placeholder="mm/dd/yyyy"
                            value="<?php echo htmlspecialchars($form_date_of_burial ?? ''); ?>" 
                            required>
                    </div>
                </div>
            
                <div class="form-card">
                    <div class="form-title">Contract Management</div>
                
                    <div class="form-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Duration</label>
                            <div class="form-control" style="background: #f8f9fa;">
                                5 years (fixed)
                            </div>
                            <input type="hidden" name="contract_type" value="temporary">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Status</label>
                            <select class="form-control" name="contract_status" required>
                                <option value="active" <?php echo ($form_contract_status ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo ($form_contract_status ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="renewal_needed" <?php echo ($form_contract_status ?? '') === 'renewal_needed' ? 'selected' : ''; ?>>Renewal Needed</option>
                                <option value="cancelled" <?php echo ($form_contract_status ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                
                    <div class="form-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Start Date</label>
                            <input 
                                type="text" 
                                class="form-control date-mdY" 
                                name="contract_start_date" 
                                placeholder="mm/dd/yyyy"
                                value="<?php echo htmlspecialchars($form_contract_start_date ?? ''); ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract End Date</label>
                            <input 
                                type="text" 
                                class="form-control date-mdY" 
                                name="contract_end_date" 
                                placeholder="mm/dd/yyyy"
                                value="<?php echo htmlspecialchars($form_contract_end_date ?? ''); ?>">
                        </div>
                    </div>
                
                    <div class="form-group">
                        <label class="form-label">Renewal Reminder Date</label>
                        <input 
                            type="text" 
                            class="form-control date-mdY" 
                            name="renewal_reminder_date" 
                            placeholder="mm/dd/yyyy"
                            value="<?php echo htmlspecialchars($form_renewal_reminder_date ?? ''); ?>">
                    </div>
                
                    <div class="form-group">
                        <label class="form-label">Contract Notes</label>
                        <textarea class="form-control" name="contract_notes" rows="3" placeholder="Enter any additional contract notes..."><?php echo htmlspecialchars($form_contract_notes ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="handleCloseModal(<?php echo (int)$plot_id; ?>)">
                        <i class="bi bi-x"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Information
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="form-card">
                <div class="form-title">Plot Actions</div>
                <p>This plot is currently available. You can:</p>
                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                        <i class="bi bi-x"></i> Close
                    </button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="plot_id" value="<?php echo $plot_id; ?>">
                        <button type="submit" name="status" value="reserved" class="btn btn-primary">
                            <i class="bi bi-bookmark"></i> Mark as Reserved
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Store Flatpickr instances for contract dates
        let contractStartDatePicker = null;
        let contractEndDatePicker = null;
        let renewalReminderDatePicker = null;

        // Helper functions for date calculations
        function addYears(dateObj, years) {
            const d = new Date(dateObj);
            if (isNaN(d.getTime())) return null;
            d.setFullYear(d.getFullYear() + years);
            return d;
        }

        function subtractDays(dateObj, days) {
            const d = new Date(dateObj);
            if (isNaN(d.getTime())) return null;
            d.setDate(d.getDate() - days);
            return d;
        }

        // Flatpickr setup: show MM/DD/YYYY to users, submit ISO YYYY-MM-DD to backend
        document.addEventListener('DOMContentLoaded', function () {
            const dateInputs = document.querySelectorAll('input.date-mdY');
            if (!dateInputs.length || !window.flatpickr) return;

            // Find the scrollable container (edit-modal-container)
            const modalContainer = document.querySelector('.edit-modal-container');
            
            dateInputs.forEach(function (input) {
                const config = {
                    dateFormat: "Y-m-d",      // value sent to PHP / DB
                    altInput: true,           // pretty display input
                    altFormat: "m/d/Y",       // what user sees (month-first)
                    allowInput: true,
                    // Append calendar to modal container for proper scrolling behavior
                    appendTo: modalContainer || document.body,
                    // Use static positioning relative to input
                    static: false
                };
                
                // Function to update calendar position relative to the visible input (alt input if present)
                function updateCalendarPosition(pickerInstance) {
                    if (!pickerInstance || !pickerInstance.calendarContainer) return;
                    
                    const calendar = pickerInstance.calendarContainer;
                    const anchorInput = pickerInstance.altInput || input; // use the visible alt input
                    const inputRect = anchorInput.getBoundingClientRect();
                    
                    if (modalContainer) {
                        // Get container position and scroll
                        const containerRect = modalContainer.getBoundingClientRect();
                        const scrollTop = modalContainer.scrollTop;
                        const scrollLeft = modalContainer.scrollLeft;
                        
                        // Calculate position relative to modal container's content area
                        // getBoundingClientRect() gives viewport coordinates, so we need to:
                        // 1. Get input position relative to container's viewport position
                        // 2. Add scroll offset to get position in content area
                        let top = (inputRect.bottom - containerRect.top) + scrollTop;
                        let left = (inputRect.left - containerRect.left) + scrollLeft;
                        
                        // Ensure calendar doesn't go off-screen horizontally
                        const calendarWidth = calendar.offsetWidth || 300;
                        const containerWidth = modalContainer.offsetWidth;
                        if (left + calendarWidth > containerWidth) {
                            left = containerWidth - calendarWidth - 10;
                        }
                        if (left < scrollLeft) {
                            left = scrollLeft + 10;
                        }
                        
                        // Check if calendar should appear above input
                        const calendarHeight = calendar.offsetHeight || 300;
                        const spaceBelow = containerRect.bottom - inputRect.bottom;
                        const spaceAbove = inputRect.top - containerRect.top;
                        
                        if (spaceBelow < calendarHeight && spaceAbove > calendarHeight) {
                            // Show above input
                            top = (inputRect.top - containerRect.top) + scrollTop - calendarHeight;
                        }
                        
                        // Ensure calendar doesn't go above container
                        if (top < scrollTop) {
                            top = scrollTop + 10;
                        }
                        
                        // Set position relative to modal container
                        calendar.style.position = 'absolute';
                        calendar.style.top = top + 'px';
                        calendar.style.left = left + 'px';
                        calendar.style.margin = '0';
                    } else {
                        // Fallback: use fixed positioning relative to viewport
                        let top = inputRect.bottom;
                        let left = inputRect.left;
                        
                        const calendarHeight = calendar.offsetHeight || 300;
                        const viewportHeight = window.innerHeight;
                        
                        // If calendar would go below viewport, show it above the input
                        if (inputRect.bottom + calendarHeight > viewportHeight && inputRect.top > calendarHeight) {
                            top = inputRect.top - calendarHeight;
                        }
                        
                        calendar.style.position = 'fixed';
                        calendar.style.top = top + 'px';
                        calendar.style.left = left + 'px';
                        calendar.style.margin = '0';
                    }
                }
                
                // Store references to contract date pickers before creating picker
                const isContractStart = input.name === 'contract_start_date';
                const isContractEnd = input.name === 'contract_end_date';
                const isRenewalReminder = input.name === 'renewal_reminder_date';
                
                // Add onChange callback for contract start date
                if (isContractStart) {
                    config.onChange = function(selectedDates, dateStr, instance) {
                        if (selectedDates.length > 0 && contractEndDatePicker) {
                            const startDate = selectedDates[0];
                            const endDate = addYears(startDate, 5);
                            if (endDate) {
                                contractEndDatePicker.setDate(endDate, false);
                                
                                // Update renewal reminder date
                                if (renewalReminderDatePicker) {
                                    const reminderDate = subtractDays(endDate, 30);
                                    if (reminderDate) {
                                        renewalReminderDatePicker.setDate(reminderDate, false);
                                    }
                                }
                                
                                // Update contract status
                                const statusSelect = document.querySelector('select[name="contract_status"]');
                                if (statusSelect) {
                                    const today = new Date();
                                    today.setHours(0, 0, 0, 0);
                                    const endDateOnly = new Date(endDate);
                                    endDateOnly.setHours(0, 0, 0, 0);
                                    
                                    if (endDateOnly < today) {
                                        statusSelect.value = 'expired';
                                    } else {
                                        const daysUntilEnd = (endDateOnly.getTime() - today.getTime()) / (1000 * 60 * 60 * 24);
                                        if (daysUntilEnd <= 30) {
                                            statusSelect.value = 'renewal_needed';
                                        } else {
                                            statusSelect.value = 'active';
                                        }
                                    }
                                }
                            }
                        }
                    };
                }
                
                // Add onChange callback for contract end date
                if (isContractEnd) {
                    config.onChange = function(selectedDates, dateStr, instance) {
                        if (selectedDates.length > 0 && renewalReminderDatePicker) {
                            const endDate = selectedDates[0];
                            const reminderDate = subtractDays(endDate, 30);
                            if (reminderDate) {
                                renewalReminderDatePicker.setDate(reminderDate, false);
                            }
                        }
                        
                        // Update contract status
                        if (selectedDates.length > 0) {
                            const statusSelect = document.querySelector('select[name="contract_status"]');
                            if (statusSelect) {
                                const endDate = selectedDates[0];
                                const today = new Date();
                                today.setHours(0, 0, 0, 0);
                                const endDateOnly = new Date(endDate);
                                endDateOnly.setHours(0, 0, 0, 0);
                                
                                if (endDateOnly < today) {
                                    statusSelect.value = 'expired';
                                } else {
                                    const daysUntilEnd = (endDateOnly.getTime() - today.getTime()) / (1000 * 60 * 60 * 24);
                                    if (daysUntilEnd <= 30) {
                                        statusSelect.value = 'renewal_needed';
                                    } else {
                                        statusSelect.value = 'active';
                                    }
                                }
                            }
                        }
                    };
                }
                
                // Add onOpen callback to position calendar correctly
                config.onOpen = function(selectedDates, dateStr, instance) {
                    // Override flatpickr's default positioning
                    setTimeout(function() {
                        updateCalendarPosition(instance);
                        // Prevent flatpickr from repositioning
                        if (instance.calendarContainer) {
                            instance.calendarContainer.style.position = modalContainer ? 'absolute' : 'fixed';
                        }
                    }, 10);
                };
                
                // Add onReady callback to set up positioning
                config.onReady = function(selectedDates, dateStr, instance) {
                    // Ensure calendar is positioned correctly when ready
                    if (instance.calendarContainer && modalContainer) {
                        instance.calendarContainer.style.position = 'absolute';
                    }
                };
                
                const picker = flatpickr(input, config);
                
                // Override flatpickr's _positionCalendar method to use our custom positioning
                if (picker && picker._positionCalendar) {
                    const originalPositionCalendar = picker._positionCalendar.bind(picker);
                    picker._positionCalendar = function() {
                        originalPositionCalendar();
                        updateCalendarPosition(picker);
                    };
                }
                
                // Update position on scroll
                if (modalContainer) {
                    const scrollHandler = function() {
                        if (picker && picker.isOpen && picker.calendarContainer) {
                            updateCalendarPosition(picker);
                        }
                    };
                    modalContainer.addEventListener('scroll', scrollHandler, { passive: true });
                    // Also listen to window scroll as fallback
                    window.addEventListener('scroll', scrollHandler, { passive: true, capture: true });
                }
                
                // Update position on window resize
                window.addEventListener('resize', function() {
                    if (picker && picker.isOpen) {
                        updateCalendarPosition(picker);
                    }
                }, { passive: true });
                
                // Store references to contract date pickers
                if (isContractStart) {
                    contractStartDatePicker = picker;
                } else if (isContractEnd) {
                    contractEndDatePicker = picker;
                } else if (isRenewalReminder) {
                    renewalReminderDatePicker = picker;
                }
            });
        });

        function openEditModal() {
            document.getElementById('editModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        function handleCloseModal(plotId) {
            const url = new URL(window.location.href);
            const isRestoreFlow = url.searchParams.get('restore') === '1';
            const currentStatus = '<?php echo htmlspecialchars($plot['status'] ?? 'available', ENT_QUOTES); ?>';
            
            // Always show confirmation if in restore flow (to ensure status reversion)
            // Also show confirmation if plot is reserved and we're editing
            if (isRestoreFlow) {
                showCloseConfirmation(plotId, true);
            } else if (currentStatus === 'reserved') {
                // Show confirmation for reserved plots to prevent accidental changes
                showCloseConfirmation(plotId, false);
            } else {
                // For available plots, close directly without confirmation
                closeEditModal();
            }
        }

        function handleCancelEdit(plotId) {
            const url = new URL(window.location.href);
            if (url.searchParams.get('restore') === '1') {
                // If this page was opened from a restore action, safely revert the plot status
                url.searchParams.set('cancel_restore', '1');
                url.searchParams.set('id', plotId);
                window.location.href = url.toString();
            } else {
                closeEditModal();
            }
        }

        function showCloseConfirmation(plotId, isRestoreFlow) {
            const confirmationDialog = document.getElementById('confirmationDialog');
            const confirmationMessage = document.getElementById('confirmationMessage');
            const confirmBtn = document.getElementById('confirmationConfirmBtn');
            
            if (!confirmationDialog || !confirmationMessage || !confirmBtn) return;
            
            let message = 'Are you sure you want to close without saving?';
            if (isRestoreFlow) {
                message = 'Are you sure you want to close? The plot status will be reverted to Available (if no deceased records exist).';
            } else {
                message = 'Are you sure you want to close? Any unsaved changes will be lost.';
            }
            
            confirmationMessage.textContent = message;
            
            // Remove any existing event listeners by cloning and replacing
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            // Set up the confirmation handler
            newConfirmBtn.addEventListener('click', function() {
                if (isRestoreFlow) {
                    // Cancel restore and revert status
                    handleCancelEdit(plotId);
                } else {
                    // Just close the modal
                    closeEditModal();
                }
                closeConfirmationDialog();
            });
            
            // Show the dialog
            setTimeout(() => {
                confirmationDialog.classList.add('show');
            }, 10);
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                const plotId = <?php echo (int)$plot_id; ?>;
                handleCloseModal(plotId);
            }
        });

        // Auto-calculate contract dates when modal opens with existing start date
        document.addEventListener('DOMContentLoaded', function() {
            // Trigger calculation if start date exists when modal opens
            const startDateInput = document.querySelector('input[name="contract_start_date"]');
            if (startDateInput && startDateInput.value && contractStartDatePicker) {
                setTimeout(function() {
                    if (contractStartDatePicker.selectedDates.length > 0) {
                        const startDate = contractStartDatePicker.selectedDates[0];
                        if (startDate && contractEndDatePicker) {
                            const endDate = addYears(startDate, 5);
                            if (endDate) {
                                contractEndDatePicker.setDate(endDate, false);
                                
                                if (renewalReminderDatePicker) {
                                    const reminderDate = subtractDays(endDate, 30);
                                    if (reminderDate) {
                                        renewalReminderDatePicker.setDate(reminderDate, false);
                                    }
                                }
                            }
                        }
                    }
                }, 300);
            }
        });

        // Exhumation target location cascading selects (section -> row -> plot)
        document.addEventListener('DOMContentLoaded', function() {
            const sectionSelect = document.getElementById('targetSectionSelect');
            const rowSelect = document.getElementById('targetRowSelect');
            const plotSelect = document.getElementById('targetPlotSelect');

            if (!sectionSelect || !rowSelect || !plotSelect) {
                return;
            }

            const defaultSectionId = parseInt(sectionSelect.getAttribute('data-default-section-id') || '0', 10);
            const defaultRowNumber = parseInt(rowSelect.getAttribute('data-default-row-number') || '0', 10);

            function clearSelect(selectEl, placeholder) {
                if (!selectEl) return;
                selectEl.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = placeholder;
                selectEl.appendChild(opt);
            }

            function setErrorOption(selectEl, message) {
                if (!selectEl) return;
                clearSelect(selectEl, message);
            }

            function loadRows(sectionId, preselectRow) {
                clearSelect(rowSelect, 'Select row...');
                clearSelect(plotSelect, 'Select plot...');
                if (!sectionId) return;

                fetch('get_section_rows.php?section_id=' + encodeURIComponent(sectionId))
                    .then(resp => resp.json())
                    .then(data => {
                        if (!data || !data.success) {
                            setErrorOption(rowSelect, 'Unable to load rows');
                            console.error('Failed to load rows', data);
                            return;
                        }
                        data.rows.forEach(row => {
                            const opt = document.createElement('option');
                            opt.value = row.row_number;
                            opt.textContent = row.display_name;
                            rowSelect.appendChild(opt);
                        });
                        if (preselectRow) {
                            rowSelect.value = String(preselectRow);
                            if (rowSelect.value === String(preselectRow)) {
                                loadPlots(sectionId, preselectRow);
                            }
                        }
                    })
                    .catch((err) => {
                        setErrorOption(rowSelect, 'Unable to load rows');
                        console.error('Error loading rows', err);
                    });
            }

            function loadPlots(sectionId, rowNumber) {
                clearSelect(plotSelect, 'Select plot...');
                if (!sectionId || !rowNumber) return;

                const currentPlotId = parseInt(plotSelect.getAttribute('data-current-plot-id') || '0', 10);
                let url = 'get_available_plots.php?section_id=' + encodeURIComponent(sectionId) + '&row_number=' + encodeURIComponent(rowNumber);
                if (currentPlotId > 0) {
                    url += '&exclude_plot_id=' + encodeURIComponent(currentPlotId);
                }

                fetch(url)
                    .then(async resp => {
                        const text = await resp.text();
                        let data = null;
                        try {
                            data = JSON.parse(text);
                        } catch (parseErr) {
                            console.error('Plot load parse error', parseErr, text);
                            setErrorOption(plotSelect, 'Unable to load plots');
                            return null;
                        }
                        return data;
                    })
                    .then(data => {
                        if (!data || !data.success) {
                            setErrorOption(plotSelect, 'Unable to load plots');
                            console.error('Failed to load plots', data);
                            return;
                        }
                        if (!data.plots || data.plots.length === 0) {
                            const opt = document.createElement('option');
                            opt.value = '';
                            opt.textContent = 'No available plots in this row';
                            plotSelect.appendChild(opt);
                            return;
                        }
                        data.plots.forEach(plot => {
                            const opt = document.createElement('option');
                            opt.value = plot.plot_id;
                            opt.textContent = plot.plot_number + ' (' + plot.status + ')';
                            plotSelect.appendChild(opt);
                        });
                    })
                    .catch((err) => {
                        setErrorOption(plotSelect, 'Unable to load plots');
                        console.error('Error loading plots', err);
                    });
            }

            sectionSelect.addEventListener('change', function() {
                const sectionId = parseInt(this.value || '0', 10);
                loadRows(sectionId, 0);
            });

            rowSelect.addEventListener('change', function() {
                const sectionId = parseInt(sectionSelect.value || '0', 10);
                const rowNumber = parseInt(this.value || '0', 10);
                loadPlots(sectionId, rowNumber);
            });

            // Initial population using current plot's section and row
            if (defaultSectionId) {
                sectionSelect.value = String(defaultSectionId);
                loadRows(defaultSectionId, defaultRowNumber || 0);
            }
        });
        
        // Show notification bubble after exhumation request
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('exhumationNotification');
            const successMessageElement = document.getElementById('exhumationSuccessMessage');
            
            if (successMessageElement && notification) {
                const message = successMessageElement.getAttribute('data-message');
                if (message && message.includes('Exhumation / transfer request submitted')) {
                    const span = notification.querySelector('span');
                    if (span) {
                        span.textContent = message;
                    }
                    
                    // Show notification
                    notification.style.display = 'flex';
                    notification.style.pointerEvents = 'auto';
                    setTimeout(() => {
                        notification.classList.add('show');
                    }, 10);
                    
                    // Hide notification after 4 seconds
                    setTimeout(() => {
                        notification.classList.remove('show');
                        notification.classList.add('hide');
                        setTimeout(() => {
                            notification.style.display = 'none';
                            notification.style.pointerEvents = 'none';
                            notification.classList.remove('hide');
                        }, 250);
                    }, 4000);

                }
            }
        });
        
        // Show error notification bubble inside modal
        function showModalErrorNotification(message) {
            const modalErrorNotification = document.getElementById('modalErrorNotification');
            if (!modalErrorNotification) return;
            
            const span = modalErrorNotification.querySelector('span');
            if (span) {
                // Replace <br> tags with newlines for better display
                span.textContent = message.replace(/<br\s*\/?>/gi, ' ');
            }
            
            // Show notification
            modalErrorNotification.style.display = 'flex';
            modalErrorNotification.style.pointerEvents = 'auto';
            setTimeout(() => {
                modalErrorNotification.classList.add('show');
            }, 10);
            
            // Scroll to top of modal to show the error
            const modalContainer = document.querySelector('.edit-modal-container');
            if (modalContainer) {
                modalContainer.scrollTop = 0;
            }
            
            // Hide notification after 5 seconds (longer than success for errors)
            setTimeout(() => {
                modalErrorNotification.classList.remove('show');
                modalErrorNotification.classList.add('hide');
                setTimeout(() => {
                    modalErrorNotification.style.display = 'none';
                    modalErrorNotification.style.pointerEvents = 'none';
                    modalErrorNotification.classList.remove('hide');
                }, 250);
            }, 5000);
        }
        
        // Display error notification on page load if error exists
        document.addEventListener('DOMContentLoaded', function() {
            const errorMessageElement = document.getElementById('errorMessage');
            if (errorMessageElement) {
                const errorMessage = errorMessageElement.getAttribute('data-error');
                if (errorMessage) {
                    // Open modal if it's not already open and show error
                    openEditModal();
                    // Show error notification after a brief delay to ensure modal is open
                    setTimeout(() => {
                        showModalErrorNotification(errorMessage);
                    }, 100);
                }
            }
        });
        
        // Handle form submission to show errors inside modal
        document.addEventListener('DOMContentLoaded', function() {
            const editPlotForm = document.getElementById('editPlotForm');
            if (editPlotForm) {
                // Check if there's an error after page reload (form submission)
                const errorMessageElement = document.getElementById('errorMessage');
                if (errorMessageElement) {
                    const errorMessage = errorMessageElement.getAttribute('data-error');
                    if (errorMessage) {
                        // Ensure modal is open
                        openEditModal();
                        // Show error notification inside modal
                        setTimeout(() => {
                            showModalErrorNotification(errorMessage);
                        }, 100);
                    }
                }
            }
        });

        // Automatically open the edit modal when coming from a contract restore
        document.addEventListener('DOMContentLoaded', function() {
            const url = new URL(window.location.href);
            if (url.searchParams.get('restore') === '1') {
                openEditModal();
            }
        });
        
        // Confirmation Dialog Functions
        function showStatusChangeConfirmation() {
            const confirmationDialog = document.getElementById('confirmationDialog');
            const confirmationMessage = document.getElementById('confirmationMessage');
            const confirmBtn = document.getElementById('confirmationConfirmBtn');
            
            if (!confirmationDialog || !confirmationMessage || !confirmBtn) return;
            
            confirmationMessage.textContent = 'Are you sure you want to change this plot status to Available?';
            
            // Remove any existing event listeners by cloning and replacing
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            // Set up the confirmation handler
            newConfirmBtn.addEventListener('click', function() {
                const form = document.getElementById('setAvailableForm');
                if (form) {
                    form.submit();
                }
                closeConfirmationDialog();
            });
            
            // Show the dialog
            setTimeout(() => {
                confirmationDialog.classList.add('show');
            }, 10);
        }
        
        function closeConfirmationDialog() {
            const confirmationDialog = document.getElementById('confirmationDialog');
            if (confirmationDialog) {
                confirmationDialog.classList.remove('show');
            }
        }
        
        // Close confirmation dialog when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            const confirmationDialog = document.getElementById('confirmationDialog');
            if (confirmationDialog) {
                confirmationDialog.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeConfirmationDialog();
                    }
                });
            }
        });
        
        // Close confirmation dialog with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const confirmationDialog = document.getElementById('confirmationDialog');
                if (confirmationDialog && confirmationDialog.classList.contains('show')) {
                    closeConfirmationDialog();
                }
            }
        });
    </script>
</body>
</html> 