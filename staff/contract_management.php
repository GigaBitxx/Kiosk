<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

/**
 * Normalize DB date values for display or further processing.
 */
function normalizeDateValue($date) {
    return ($date && $date !== '0000-00-00') ? $date : '';
}

/**
 * Convert a numeric row index (1 = A) into its letter representation.
 * Supports numbers beyond 26 (27 = AA, 28 = AB, etc.)
 */
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }

    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1) {
        return '';
    }

    $letter = '';
    while ($rowNumber > 0) {
        $remainder = ($rowNumber - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $rowNumber = intval(($rowNumber - 1) / 26);
    }
    return $letter;
}

/**
 * Format plot location as "SECTION-ROWPLOT" (e.g., "AION-A1")
 */
function formatPlotLocation($sectionName, $rowNumber, $plotNumber) {
    if (empty($sectionName)) {
        return '';
    }
    
    $rowLetter = rowNumberToLetter($rowNumber ?? 1);
    $plotNum = $plotNumber ?? '';
    
    return $sectionName . '-' . $rowLetter . $plotNum;
}

/**
 * Fetch deceased/plot details needed for contract management.
 */
function fetchContractRecord($conn, $record_id, $plot_id) {
    // Check available tables
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
    $use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
    if ($table_check) {
        mysqli_free_result($table_check);
    }
    $archive_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_deceased_records'");
    $has_archive_table = $archive_check && mysqli_num_rows($archive_check) > 0;
    if ($archive_check) {
        mysqli_free_result($archive_check);
    }

    // 1) Try to load from active deceased_records / deceased
    if ($use_deceased_records) {
        $query = "SELECT d.*, p.*, s.section_name 
                  FROM deceased_records d 
                  JOIN plots p ON d.plot_id = p.plot_id 
                  LEFT JOIN sections s ON p.section_id = s.section_id
                  WHERE d.record_id = ? AND p.plot_id = ?";
    } else {
        $query = "SELECT d.*, p.*, s.section_name,
                         CONCAT(d.first_name, ' ', d.last_name) as full_name,
                         d.date_of_death as date_of_death,
                         d.date_of_burial as burial_date
                  FROM deceased d 
                  JOIN plots p ON d.plot_id = p.plot_id 
                  LEFT JOIN sections s ON p.section_id = s.section_id
                  WHERE d.deceased_id = ? AND p.plot_id = ?";
    }

    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $record_id, $plot_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result && ($row = $result->fetch_assoc())) {
            $row['_source'] = 'active';
            return $row;
        }
    }

    // 2) Fallback: load from archived_deceased_records if available
    if ($has_archive_table && $use_deceased_records) {
        $archive_query = "SELECT 
                            ad.*,
                            p.*,
                            s.section_name,
                            CONCAT(ad.first_name, ' ', ad.last_name) AS full_name,
                            ad.date_of_death AS date_of_death,
                            ad.date_of_burial AS burial_date
                          FROM archived_deceased_records ad
                          JOIN plots p ON ad.plot_id = p.plot_id
                          LEFT JOIN sections s ON p.section_id = s.section_id
                          WHERE ad.deceased_id = ? AND p.plot_id = ?";
        $archive_stmt = mysqli_prepare($conn, $archive_query);
        if ($archive_stmt) {
            mysqli_stmt_bind_param($archive_stmt, "ii", $record_id, $plot_id);
            mysqli_stmt_execute($archive_stmt);
            $archive_result = mysqli_stmt_get_result($archive_stmt);
            if ($archive_result && ($row = $archive_result->fetch_assoc())) {
                $row['_source'] = 'archived';
                return $row;
            }
        }
    }

    return null;
}

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

$record_id = isset($_GET['record_id']) ? (int) $_GET['record_id'] : null;
$plot_id = isset($_GET['plot_id']) ? (int) $_GET['plot_id'] : null;

if (!$record_id || !$plot_id) {
    header('Location: contracts.php');
    exit();
}

$record = fetchContractRecord($conn, $record_id, $plot_id);

if (!$record) {
    header('Location: contracts.php');
    exit();
}

$derived_contract_start_date = normalizeDateValue($record['date_acquired'] ?? ($record['contract_start_date'] ?? ''));
$derived_contract_end_date = normalizeDateValue($record['due_date'] ?? ($record['contract_end_date'] ?? ''));
$derived_reminder_date = normalizeDateValue($record['renewal_reminder_date'] ?? '');

if (!$derived_reminder_date && $derived_contract_end_date) {
    $reminder_timestamp = strtotime($derived_contract_end_date . ' -30 days');
    if ($reminder_timestamp !== false) {
        $derived_reminder_date = date('Y-m-d', $reminder_timestamp);
    }
}

$record['contract_start_date'] = $derived_contract_start_date;
$record['contract_end_date'] = $derived_contract_end_date;
$record['renewal_reminder_date'] = $derived_reminder_date;

// Determine if this record can still be archived (only when it exists in deceased_records)
$can_archive = false;
if ($record['_source'] === 'active') {
    $check_sql = "SELECT 1 FROM deceased_records WHERE record_id = ? AND plot_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "ii", $record_id, $plot_id);
        mysqli_stmt_execute($check_stmt);
        $check_res = mysqli_stmt_get_result($check_stmt);
        $can_archive = $check_res && mysqli_fetch_assoc($check_res);
    }
}

// Handle success messages from URL parameters
$success_message = null;
$error_message = null;
if (isset($_GET['success']) && $_GET['success'] === 'contract_updated') {
    $success_message = "Contract information updated successfully!";
}

// Handle form submission
if ($_POST) {
    // If delete button was clicked, archive contract and optionally archive deceased record
    if (isset($_POST['delete_record'])) {
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
        $use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
        if ($table_check) {
            mysqli_free_result($table_check);
        }

        // Check if archived_contracts table exists, create if not
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_contracts'");
        $table_exists = $table_check && mysqli_num_rows($table_check) > 0;
        if ($table_check) {
            mysqli_free_result($table_check);
        }

        if (!$table_exists) {
            // Create the table if it doesn't exist
            $create_table_sql = "CREATE TABLE IF NOT EXISTS archived_contracts (
                archive_id INT PRIMARY KEY AUTO_INCREMENT,
                plot_id INT NOT NULL,
                record_id INT DEFAULT NULL,
                deceased_name VARCHAR(255) NOT NULL,
                section_name VARCHAR(100) DEFAULT NULL,
                plot_number VARCHAR(20) DEFAULT NULL,
                row_number INT DEFAULT NULL,
                contract_start_date DATE DEFAULT NULL,
                contract_end_date DATE DEFAULT NULL,
                contract_type ENUM('perpetual', 'temporary', 'lease') DEFAULT 'temporary',
                contract_status ENUM('active', 'expired', 'renewal_needed', 'cancelled') DEFAULT 'active',
                contract_notes TEXT DEFAULT NULL,
                renewal_reminder_date DATE DEFAULT NULL,
                burial_date DATE DEFAULT NULL,
                date_of_death DATE DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                next_of_kin VARCHAR(255) DEFAULT NULL,
                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                archived_by INT DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                INDEX idx_plot_id (plot_id),
                INDEX idx_record_id (record_id),
                INDEX idx_archived_at (archived_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            mysqli_query($conn, $create_table_sql);
        }

        // Get current user ID for archived_by
        $archived_by = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $reason = 'Contract deleted via contract management';

        // Archive contract data before clearing
        $archive_query = "INSERT INTO archived_contracts (
            plot_id, record_id, deceased_name, section_name, plot_number, row_number,
            contract_start_date, contract_end_date, contract_type, contract_status,
            contract_notes, renewal_reminder_date, burial_date, date_of_death,
            address, next_of_kin, archived_by, reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $archive_stmt = mysqli_prepare($conn, $archive_query);
        $archive_success = false;
        
        if ($archive_stmt) {
            $burial_date = normalizeDateValue($record['burial_date'] ?? '');
            $date_of_death = normalizeDateValue($record['date_of_death'] ?? '');
            
            // Assign all values to variables before binding (required for mysqli_stmt_bind_param)
            $full_name = $record['full_name'] ?? '';
            $section_name = $record['section_name'] ?? null;
            $plot_number = $record['plot_number'] ?? null;
            $row_number = $record['row_number'] ?? null;
            $contract_start_date = $record['contract_start_date'] ?? null;
            $contract_end_date = $record['contract_end_date'] ?? null;
            $contract_type = $record['contract_type'] ?? 'temporary';
            $contract_status = $record['contract_status'] ?? 'active';
            $contract_notes = $record['contract_notes'] ?? null;
            $renewal_reminder_date = $record['renewal_reminder_date'] ?? null;
            $burial_date_value = $burial_date ?: null;
            $date_of_death_value = $date_of_death ?: null;
            $address = $record['address'] ?? null;
            $next_of_kin = $record['next_of_kin'] ?? null;
            
            mysqli_stmt_bind_param(
                $archive_stmt,
                "iisssissssssssssss",
                $plot_id,
                $record_id,
                $full_name,
                $section_name,
                $plot_number,
                $row_number,
                $contract_start_date,
                $contract_end_date,
                $contract_type,
                $contract_status,
                $contract_notes,
                $renewal_reminder_date,
                $burial_date_value,
                $date_of_death_value,
                $address,
                $next_of_kin,
                $archived_by,
                $reason
            );
            
            $archive_success = mysqli_stmt_execute($archive_stmt);
        }

        if ($archive_success) {
            // Clear contract fields from plots table
            $clear_query = "UPDATE plots SET 
                contract_start_date = NULL,
                contract_end_date = NULL,
                contract_type = 'perpetual',
                contract_status = 'active',
                contract_notes = NULL,
                renewal_reminder_date = NULL
                WHERE plot_id = ?";
            
            $clear_stmt = mysqli_prepare($conn, $clear_query);
            if ($clear_stmt) {
                mysqli_stmt_bind_param($clear_stmt, "i", $plot_id);
                if (mysqli_stmt_execute($clear_stmt)) {
                    header('Location: contracts.php?success=contract_archived');
                    exit();
                } else {
                    $error_message = 'Failed to clear contract fields.';
                }
            } else {
                $error_message = 'Failed to prepare clear statement.';
            }
        } else {
            $error_message = 'Failed to archive contract data.';
        }
    } else {
        // Normal update flow
        $contract_start_date = normalizeDateValue($_POST['contract_start_date'] ?? $record['contract_start_date']);
        $contract_end_date = normalizeDateValue($_POST['contract_end_date'] ?? $record['contract_end_date']);
        $contract_type = 'temporary'; // 5-year contracts
        $contract_status = $_POST['contract_status'] ?? 'active';
        $contract_notes = trim($_POST['contract_notes'] ?? '');
        $renewal_reminder_date = normalizeDateValue($_POST['renewal_reminder_date'] ?? $record['renewal_reminder_date']);

        if ($contract_end_date && !$renewal_reminder_date) {
            $reminder_timestamp = strtotime($contract_end_date . ' -30 days');
            if ($reminder_timestamp !== false) {
                $renewal_reminder_date = date('Y-m-d', $reminder_timestamp);
                $record['renewal_reminder_date'] = $renewal_reminder_date;
            }
        }

        // Server-side validation
        $validation_errors = [];
        if ($contract_start_date !== '' && $contract_end_date !== '' && strtotime($contract_end_date) < strtotime($contract_start_date)) {
            $validation_errors[] = 'Contract end date cannot be before start date.';
        }

        if (empty($validation_errors)) {
            // Update plot contract information, convert empty strings to NULL in DB
            $update_query = "UPDATE plots SET 
                             contract_start_date = NULLIF(?, ''), 
                             contract_end_date = NULLIF(?, ''), 
                             contract_type = ?, 
                             contract_status = ?, 
                             contract_notes = NULLIF(?, ''), 
                             renewal_reminder_date = NULLIF(?, '')
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
                // If contract_start_date was provided, also update date_acquired and burial_date in deceased records
                if ($contract_start_date && $record['_source'] === 'active') {
                    // Check which table structure is being used
                    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
                    $use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
                    if ($table_check) {
                        mysqli_free_result($table_check);
                    }
                    
                    if ($use_deceased_records) {
                        // Check if date_acquired column exists in deceased_records table
                        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM deceased_records LIKE 'date_acquired'");
                        $has_date_acquired = $column_check && mysqli_num_rows($column_check) > 0;
                        if ($column_check) {
                            mysqli_free_result($column_check);
                        }
                        
                        if ($has_date_acquired) {
                            // Update date_acquired in deceased_records table
                            $update_date_acquired_query = "UPDATE deceased_records SET date_acquired = ? WHERE record_id = ? AND plot_id = ?";
                            $date_acquired_stmt = mysqli_prepare($conn, $update_date_acquired_query);
                            if ($date_acquired_stmt) {
                                mysqli_stmt_bind_param($date_acquired_stmt, "sii", $contract_start_date, $record_id, $plot_id);
                                mysqli_stmt_execute($date_acquired_stmt);
                                mysqli_stmt_close($date_acquired_stmt);
                            }
                        }
                        
                        // Update burial_date in deceased_records table to match contract_start_date
                        $update_burial_date_query = "UPDATE deceased_records SET burial_date = ? WHERE record_id = ? AND plot_id = ?";
                        $burial_date_stmt = mysqli_prepare($conn, $update_burial_date_query);
                        if ($burial_date_stmt) {
                            mysqli_stmt_bind_param($burial_date_stmt, "sii", $contract_start_date, $record_id, $plot_id);
                            mysqli_stmt_execute($burial_date_stmt);
                            mysqli_stmt_close($burial_date_stmt);
                        }
                    } else {
                        // Legacy deceased table: update date_of_burial
                        $update_burial_date_query = "UPDATE deceased SET date_of_burial = ? WHERE deceased_id = ? AND plot_id = ?";
                        $burial_date_stmt = mysqli_prepare($conn, $update_burial_date_query);
                        if ($burial_date_stmt) {
                            mysqli_stmt_bind_param($burial_date_stmt, "sii", $contract_start_date, $record_id, $plot_id);
                            mysqli_stmt_execute($burial_date_stmt);
                            mysqli_stmt_close($burial_date_stmt);
                        }
                    }
                }
                
                // Redirect back to the referring page, or contracts.php if not specified
                $redirect_url = isset($_GET['from']) ? $_GET['from'] : 'contracts.php';
                $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'success=contract_updated';
                header('Location: ' . $redirect_url);
                exit();
            } else {
                $error_message = "Error updating contract information: " . mysqli_error($conn);
            }
        } else {
            $error_message = implode(' ', $validation_errors);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="../assets/js/flash_clean_query.js"></script>
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        /* Page-specific styles */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            position: relative;
        }
        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: #000000;
            margin: 0;
            letter-spacing: 1px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
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
        .form-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        .form-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 24px;
            color: #222;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            transition: border-color 0.2s;
            box-sizing: border-box;
            background-color: #fff;
            cursor: text;
            min-height: 40px;
        }
        .form-group input[type="date"] {
            cursor: pointer;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }
        .form-group input:hover {
            border-color: #bbb;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-save {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.02em;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        }
        .btn-save:hover {
            background: #1f3659;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(43, 76, 126, 0.25);
        }
        .btn-save:active {
            transform: translateY(0);
            box-shadow: none;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-cancel:hover {
            background: #5c636a;
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        .alert-error {
            background: #f8d7da;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        /* Notification Bubble Styles */
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-bubble span {
            flex: 1;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            border-left: 4px solid #065f46;
        }
        
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-left: 4px solid #991b1b;
        }
        
        .alert {
            display: none;
        }
        .record-info {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .record-info h3 {
            margin: 0 0 12px 0;
            color: #333;
            font-size: 16px;
        }
        .record-info p {
            margin: 4px 0;
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .record-info p strong {
            display: inline-block;
            min-width: 140px;
            font-weight: 600;
            color: #333;
        }
        /* Responsive Design - Standard Breakpoints */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .main {
                padding: 24px 20px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .form-card {
                padding: 18px 16px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100vw;
                min-height: 60px;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }
            
            .sidebar.collapsed {
                width: 100vw;
                min-height: 60px;
            }
            
            .main {
                padding: 16px 12px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .form-card {
                padding: 12px 10px;
            }
            
            /* Prevent horizontal scroll */
            body, html {
                overflow-x: hidden;
                max-width: 100vw;
            }
            
            .layout {
                overflow-x: hidden;
                max-width: 100vw;
            }
        }
        
        /* Delete Confirmation Modal Styles */
        .delete-confirm-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }
        
        .delete-confirm-modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .delete-confirm-modal {
            background: #fff;
            border-radius: 12px;
            padding: 0;
            max-width: 550px;
            width: 90%;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            transform: scale(0.9);
            transition: transform 0.25s ease;
            overflow: hidden;
        }
        
        .delete-confirm-modal-overlay.show .delete-confirm-modal {
            transform: scale(1);
        }
        
        .delete-modal-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
            padding: 20px 24px;
            font-weight: 600;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .delete-modal-close-btn {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s ease;
        }
        
        .delete-modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .delete-modal-body {
            padding: 24px;
            color: #1d2a38;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .delete-modal-body .warning-icon {
            font-size: 32px;
            margin-bottom: 12px;
            display: block;
        }
        
        .delete-modal-footer {
            padding: 16px 24px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e0e0e0;
        }
        
        .delete-modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .delete-modal-btn-cancel {
            background: #fff;
            color: #6c757d;
            border: 1px solid #e0e0e0;
        }
        
        .delete-modal-btn-cancel:hover {
            background: #f8f9fa;
            border-color: #d0d0d0;
        }
        
        .delete-modal-btn-confirm {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
        }
        
        .delete-modal-btn-confirm:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-header">
            <a href="<?php echo isset($_GET['from']) ? htmlspecialchars($_GET['from']) : 'contracts.php'; ?>" class="btn-back">
                <i class="bx bx-arrow-back"></i> Back
            </a>
            <div class="page-title">Contract Details</div>
            <div style="width: 120px;"></div>
        </div>
        
        <div class="form-card">
            <div class="record-info">
                <h3>Deceased Information</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($record['full_name']); ?></p>
                <p><strong>Name of Lessee:</strong> <?php echo htmlspecialchars($record['next_of_kin'] ?? '—'); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($record['address'] ?? '—'); ?></p>
                <p><strong>Plot:</strong> <?php echo htmlspecialchars(formatPlotLocation($record['section_name'] ?? '', $record['row_number'] ?? 1, $record['plot_number'] ?? '')); ?></p>
                <p><strong>Date of Death:</strong> <?php echo !empty($record['date_of_death']) && $record['date_of_death'] !== '0000-00-00' ? date('M j Y', strtotime($record['date_of_death'])) : '—'; ?></p>
                <p><strong>Burial Date:</strong> <span id="burial_date_display"><?php echo !empty($record['burial_date']) && $record['burial_date'] !== '0000-00-00' ? date('M j Y', strtotime($record['burial_date'])) : '—'; ?></span></p>
                <p><strong>Date Acquired:</strong> <span id="date_acquired_display"><?php $date_acquired = normalizeDateValue($record['date_acquired'] ?? ''); echo $date_acquired ? date('M j Y', strtotime($date_acquired)) : '—'; ?></span></p>
                <p><strong>Due Date:</strong> <?php $due_date = normalizeDateValue($record['due_date'] ?? ''); echo $due_date ? date('M j Y', strtotime($due_date)) : '—'; ?></p>
            </div>
            
            <!-- Display Messages (hidden, used for JS) -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success" data-message="<?php echo htmlspecialchars($success_message); ?>"></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error" data-message="<?php echo htmlspecialchars($error_message); ?>"></div>
            <?php endif; ?>
            
            <div class="form-title">Contract Information</div>
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="contract_status">Contract Status</label>
                        <select id="contract_status" name="contract_status" required>
                            <option value="active" <?php echo ($record['contract_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="expired" <?php echo ($record['contract_status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="renewal_needed" <?php echo ($record['contract_status'] ?? '') === 'renewal_needed' ? 'selected' : ''; ?>>Renewal Needed</option>
                            <option value="cancelled" <?php echo ($record['contract_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="contract_start_date">Contract Start Date</label>
                        <input type="text" class="date-mdY" id="contract_start_date" name="contract_start_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars(normalizeDateValue($record['contract_start_date'] ?? '')); ?>">
                        <?php if (!empty($record['date_acquired']) && $record['date_acquired'] !== '0000-00-00'): ?>
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Originally linked to Date Acquired: <?php echo date('M j Y', strtotime($record['date_acquired'])); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="contract_end_date">Contract End Date</label>
                        <input type="text" class="date-mdY" id="contract_end_date" name="contract_end_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars(normalizeDateValue($record['contract_end_date'] ?? '')); ?>" required>
                        <?php if (!empty($record['due_date']) && $record['due_date'] !== '0000-00-00'): ?>
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Originally linked to Due Date: <?php echo date('M j Y', strtotime($record['due_date'])); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="renewal_reminder_date">Renewal Reminder Date</label>
                        <input type="text" class="date-mdY" id="renewal_reminder_date" name="renewal_reminder_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars(normalizeDateValue($record['renewal_reminder_date'] ?? '')); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="contract_notes">Contract Notes</label>
                    <textarea id="contract_notes" name="contract_notes" placeholder="Enter any additional contract notes..."><?php echo htmlspecialchars($record['contract_notes'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" class="btn-save">Save</button>
                    <button type="button" class="btn-cancel" style="background:#dc3545; color: white;" onclick="openDeleteConfirmModal()">Delete</button>
                    <a href="contracts.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="delete-confirm-modal-overlay">
    <div class="delete-confirm-modal">
        <div class="delete-modal-header">
            <span>⚠️ Notice</span>
            <button type="button" class="delete-modal-close-btn" onclick="closeDeleteConfirmModal()">&times;</button>
        </div>
        <div class="delete-modal-body">
            <span class="warning-icon">⚠️</span>
            <p style="margin: 0; font-weight: 500; margin-bottom: 12px;">Are you sure you want to delete this contract?</p>
            <p style="margin: 0; font-size: 14px; color: #6c757d;">
                <strong>Reminder:</strong> This action will archive the contract information, including all contract details, dates, and status. The contract data will be preserved in the archive for future reference.
            </p>
        </div>
        <div class="delete-modal-footer">
            <button type="button" class="delete-modal-btn delete-modal-btn-cancel" onclick="closeDeleteConfirmModal()">Cancel</button>
            <button type="button" class="delete-modal-btn delete-modal-btn-confirm" onclick="confirmDeleteContract()">Confirm Delete</button>
        </div>
    </div>
</div>

<script>
    // Delete confirmation modal functions
    function openDeleteConfirmModal() {
        const modal = document.getElementById('deleteConfirmModal');
        modal.classList.add('show');
    }
    
    function closeDeleteConfirmModal() {
        const modal = document.getElementById('deleteConfirmModal');
        modal.classList.remove('show');
    }
    
    function confirmDeleteContract() {
        // Create a hidden form to submit the delete action
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_record';
        deleteInput.value = '1';
        form.appendChild(deleteInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('deleteConfirmModal');
        if (e.target === modal) {
            closeDeleteConfirmModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDeleteConfirmModal();
        }
    });
    
    const contractStartInput = document.getElementById('contract_start_date');
    const contractEndInput = document.getElementById('contract_end_date');
    const renewalReminderInput = document.getElementById('renewal_reminder_date');
    const contractStatusSelect = document.getElementById('contract_status');

    // Function to format date as YYYY-MM-DD for date input
    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Function to format date as "Mon DD YYYY" for display
    function formatDateForDisplay(date) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = months[date.getMonth()];
        const day = date.getDate();
        const year = date.getFullYear();
        return `${month} ${day} ${year}`;
    }

    // Auto-calculate end date, renewal reminder, and update burial date when start date changes
    if (contractStartInput) {
        contractStartInput.addEventListener('change', function () {
            if (!this.value) {
                return;
            }

            const startDate = new Date(this.value + 'T00:00:00');
            
            // Calculate end date (start date + 5 years)
            const endDate = new Date(startDate);
            endDate.setFullYear(endDate.getFullYear() + 5);
            
            // Calculate renewal reminder date (end date - 30 days)
            const reminderDate = new Date(endDate);
            reminderDate.setDate(reminderDate.getDate() - 30);
            
            // Update the fields
            if (contractEndInput) {
                contractEndInput.value = formatDateForInput(endDate);
                // Trigger change event to update contract status
                contractEndInput.dispatchEvent(new Event('change'));
            }
            
            if (renewalReminderInput) {
                renewalReminderInput.value = formatDateForInput(reminderDate);
            }
            
            // Update burial date display to match contract start date
            const burialDateDisplay = document.getElementById('burial_date_display');
            if (burialDateDisplay) {
                burialDateDisplay.textContent = formatDateForDisplay(startDate);
            }
            
            // Update date acquired display to match contract start date
            const dateAcquiredDisplay = document.getElementById('date_acquired_display');
            if (dateAcquiredDisplay) {
                dateAcquiredDisplay.textContent = formatDateForDisplay(startDate);
            }
        });
    }

    // Update contract status based on end date
    if (contractEndInput) {
        const handleEndDateChange = function () {
            if (!this.value || !contractStatusSelect) {
                return;
            }

            const endDate = new Date(this.value + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (endDate < today) {
                contractStatusSelect.value = 'expired';
            } else {
                const daysUntilExpiry = Math.ceil((endDate - today) / (1000 * 60 * 60 * 24));
                if (daysUntilExpiry <= 30) {
                    contractStatusSelect.value = 'renewal_needed';
                } else {
                    contractStatusSelect.value = 'active';
                }
            }
        };

        // Handle both picker changes and manual typing
        contractEndInput.addEventListener('change', handleEndDateChange);
        contractEndInput.addEventListener('input', function () {
            if (this.value && this.value.length === 10) {
                handleEndDateChange.call(this);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Ensure contract end date and renewal reminder date are editable
        const contractEndDate = document.getElementById('contract_end_date');
        const renewalReminderDate = document.getElementById('renewal_reminder_date');
        
        if (contractEndDate) {
            contractEndDate.removeAttribute('readonly');
            contractEndDate.removeAttribute('disabled');
            if (contractEndDate.value) {
                contractEndDate.dispatchEvent(new Event('change'));
            }
        }
        
        if (renewalReminderDate) {
            renewalReminderDate.removeAttribute('readonly');
            renewalReminderDate.removeAttribute('disabled');
        }
        
        // Notification bubble handling
        const successNotification = document.getElementById('successNotification');
        const errorNotification = document.getElementById('errorNotification');
        const successAlert = document.querySelector('.alert-success');
        const errorAlert = document.querySelector('.alert-error');
        
        const showNotification = (notification, message) => {
            if (!notification) return;
            const span = notification.querySelector('span');
            if (span) span.textContent = message;
            notification.style.display = 'flex';
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => {
                    notification.style.display = 'none';
                    notification.classList.remove('hide');
                }, 250);
            }, 4000);
        };
        
        if (successAlert) {
            const message = successAlert.getAttribute('data-message');
            if (message) {
                showNotification(successNotification, message);
            }
        }
        
        if (errorAlert) {
            const message = errorAlert.getAttribute('data-message');
            if (message) {
                showNotification(errorNotification, message);
            }
        }
    });
</script>

<!-- Notification Bubbles -->
<div id="successNotification" class="notification-bubble success-notification" style="display: none;">
    <i class="bx bx-check-circle"></i>
    <span></span>
</div>
<div id="errorNotification" class="notification-bubble error-notification" style="display: none;">
    <i class="bx bx-error-alt"></i>
    <span></span>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Flatpickr setup for contract date fields
    document.addEventListener('DOMContentLoaded', function () {
        const dateInputs = document.querySelectorAll('input.date-mdY');
        if (!dateInputs.length || !window.flatpickr) return;

        dateInputs.forEach(function (input) {
            flatpickr(input, {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "m/d/Y",
                allowInput: true
            });
        });
    });
</script>
</body>
</html>
