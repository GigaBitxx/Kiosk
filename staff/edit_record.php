<?php
// Prevent any output before headers
ob_start();

require_once '../includes/auth_check.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    ob_end_clean();
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

/**
 * Convert a numeric row index (1 = A) into its letter representation.
 */
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }

    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1 || $rowNumber > 26) {
        return '';
    }

    return chr(64 + $rowNumber);
}

$record_id = $_GET['id'] ?? null;
$record = null;
$success_message = '';
$error_message = '';

if (!$record_id) {
    ob_end_clean();
    header('Location: deceased_records.php');
    exit();
}

// Capture filter parameters from URL to preserve them when going back
$return_params = [];
$filter_params = ['search_name', 'search_plot', 'search_section', 'search_row', 'sort_by', 'sort_order', 'page'];

// First, try to get from GET parameters (when coming from deceased_records.php)
// If not in GET, try to restore from session (after POST redirect)
foreach ($filter_params as $param) {
    if (isset($_GET[$param]) && $_GET[$param] !== '') {
        $return_params[$param] = $_GET[$param];
        // Store in session for POST redirect
        $_SESSION['edit_record_filters'][$param] = $_GET[$param];
    } elseif (isset($_SESSION['edit_record_filters'][$param]) && $_SESSION['edit_record_filters'][$param] !== '') {
        // Restore from session after POST redirect
        $return_params[$param] = $_SESSION['edit_record_filters'][$param];
    }
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    // Ensure we have filter params (from session if not in current $return_params)
    if (empty($return_params) && isset($_SESSION['edit_record_filters'])) {
        $return_params = $_SESSION['edit_record_filters'];
    }
    
    $record_id_to_delete = intval($_POST['record_id']);
    
    if ($record_id_to_delete > 0) {
        // First get the plot_id to update plot status (if needed)
        $plot_query = "SELECT plot_id FROM deceased_records WHERE record_id = ?";
        $stmt = mysqli_prepare($conn, $plot_query);
        mysqli_stmt_bind_param($stmt, "i", $record_id_to_delete);
        mysqli_stmt_execute($stmt);
        $plot_result = mysqli_stmt_get_result($stmt);
        $plot_data = mysqli_fetch_assoc($plot_result);
        
        if ($plot_data) {
            $plot_id = (int)$plot_data['plot_id'];
            
            // Delete the deceased record
            $delete_query = "DELETE FROM deceased_records WHERE record_id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $record_id_to_delete);
            
            if (mysqli_stmt_execute($stmt)) {
                // Only mark plot as available if there are no more deceased records for this plot
                $count_query = "SELECT COUNT(*) AS cnt FROM deceased_records WHERE plot_id = ?";
                $count_stmt = mysqli_prepare($conn, $count_query);
                mysqli_stmt_bind_param($count_stmt, "i", $plot_id);
                mysqli_stmt_execute($count_stmt);
                $count_result = mysqli_stmt_get_result($count_stmt);
                $count_row = mysqli_fetch_assoc($count_result);
                $remaining = (int)($count_row['cnt'] ?? 0);

                if ($remaining === 0) {
                    $update_plot = "UPDATE plots SET status = 'available' WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $update_plot);
                    mysqli_stmt_bind_param($stmt, "i", $plot_id);
                    mysqli_stmt_execute($stmt);
                }
                
                // Redirect back to deceased_records.php with success message
                $redirect_url = 'deceased_records.php?success=record_deleted';
                if (!empty($return_params)) {
                    $redirect_url .= '&' . http_build_query($return_params);
                }
                header('Location: ' . $redirect_url);
                exit();
            } else {
                $_SESSION['edit_record_error'] = "Error deleting record: " . mysqli_error($conn);
                $redirect_url = 'edit_record.php?id=' . $record_id;
                if (!empty($return_params)) {
                    $redirect_url .= '&' . http_build_query($return_params);
                }
                header('Location: ' . $redirect_url);
                exit();
            }
        } else {
            $_SESSION['edit_record_error'] = "Record not found.";
            $redirect_url = 'edit_record.php?id=' . $record_id;
            if (!empty($return_params)) {
                $redirect_url .= '&' . http_build_query($return_params);
            }
            header('Location: ' . $redirect_url);
            exit();
        }
    } else {
        $_SESSION['edit_record_error'] = "Invalid record ID.";
        $redirect_url = 'edit_record.php?id=' . $record_id;
        if (!empty($return_params)) {
            $redirect_url .= '&' . http_build_query($return_params);
        }
        header('Location: ' . $redirect_url);
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure we have filter params (from session if not in current $return_params)
    if (empty($return_params) && isset($_SESSION['edit_record_filters'])) {
        $return_params = $_SESSION['edit_record_filters'];
    }
    
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $date_of_birth = $_POST['date_of_birth'];
    $date_of_death = $_POST['date_of_death'];
    $burial_date = $_POST['burial_date'];
    $next_of_kin = mysqli_real_escape_string($conn, $_POST['next_of_kin']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number']);

    // ---- Server-side date validation ----
    $validation_errors = [];

    // Normalize empty strings to null for easier checks
    $dob = !empty($date_of_birth) ? $date_of_birth : null;
    $dod = !empty($date_of_death) ? $date_of_death : null;
    $burial = !empty($burial_date) ? $burial_date : null;

    if ($dob && $dod) {
        // Ensure date of death is NOT before date of birth
        if (strtotime($dod) < strtotime($dob)) {
            $validation_errors[] = "Date of death cannot be earlier than date of birth.";
        }
    }

    if ($dod && $burial) {
        // Ensure burial date is NOT before date of death
        if (strtotime($burial) < strtotime($dod)) {
            $validation_errors[] = "Burial date cannot be earlier than date of death.";
        }
    }

    if (!empty($validation_errors)) {
        // Store validation error(s) in session and redirect back without saving
        $_SESSION['edit_record_error'] = implode(' ', $validation_errors);
        $redirect_url = 'edit_record.php?id=' . $record_id;
        if (!empty($return_params)) {
            $redirect_url .= '&' . http_build_query($return_params);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    $query = "UPDATE deceased_records SET 
              full_name = ?, 
              date_of_birth = ?, 
              date_of_death = ?, 
              burial_date = ?, 
              next_of_kin = ?, 
              address = ?,
              contact_number = ?
              WHERE record_id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sssssssi", $full_name, $date_of_birth, $date_of_death, $burial_date, $next_of_kin, $address, $contact_number, $record_id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Store success message in session and redirect to prevent form resubmission
        $_SESSION['edit_record_success'] = "Record updated successfully!";
        // Preserve filter parameters in redirect
        $redirect_url = 'edit_record.php?id=' . $record_id;
        if (!empty($return_params)) {
            $redirect_url .= '&' . http_build_query($return_params);
        }
        header('Location: ' . $redirect_url);
        exit();
    } else {
        // Store error message in session and redirect
        $_SESSION['edit_record_error'] = "Error updating record: " . mysqli_error($conn);
        // Preserve filter parameters in redirect
        $redirect_url = 'edit_record.php?id=' . $record_id;
        if (!empty($return_params)) {
            $redirect_url .= '&' . http_build_query($return_params);
        }
        header('Location: ' . $redirect_url);
        exit();
    }
}

// Check for session messages (from redirect after POST)
if (isset($_SESSION['edit_record_success'])) {
    $success_message = $_SESSION['edit_record_success'];
    unset($_SESSION['edit_record_success']);
}
if (isset($_SESSION['edit_record_error'])) {
    $error_message = $_SESSION['edit_record_error'];
    unset($_SESSION['edit_record_error']);
}

// Fetch record details
$query = "SELECT d.*, p.plot_number, p.row_number, s.section_name 
          FROM deceased_records d 
          JOIN plots p ON d.plot_id = p.plot_id 
          LEFT JOIN sections s ON p.section_id = s.section_id
          WHERE d.record_id = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    $_SESSION['edit_record_error'] = "Database error: " . mysqli_error($conn);
    header('Location: deceased_records.php');
    exit();
}

mysqli_stmt_bind_param($stmt, "i", $record_id);
if (!mysqli_stmt_execute($stmt)) {
    $_SESSION['edit_record_error'] = "Error executing query: " . mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    header('Location: deceased_records.php');
    exit();
}

$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    $_SESSION['edit_record_error'] = "Error getting result: " . mysqli_error($conn);
    mysqli_stmt_close($stmt);
    header('Location: deceased_records.php');
    exit();
}

$record = $result->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$record) {
    ob_end_clean();
    header('Location: deceased_records.php');
    exit();
}

// Clean output buffer before HTML
ob_end_clean();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        /* Page-specific styles */
        
        /* Responsive Design - Page Specific */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .form-card {
                padding: 18px 16px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .form-card {
                padding: 12px 10px;
            }
                max-width: 100vw;
            }
        }
        .page-header-container {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
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
        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: #000000;
            margin: 0;
            letter-spacing: 1px;
            text-align: center;
            grid-column: 2;
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
            margin-bottom: 18px;
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
            min-height: 40px;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }
        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }
        .btn-save {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-save:hover {
            background: #1f3659;
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
            transition: background 0.2s;
        }
        .btn-cancel:hover {
            background: #5c636a;
        }
        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-delete:hover {
            background: #c82333;
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
            z-index: 100000;
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
            position: relative;
            z-index: 100001;
            pointer-events: auto;
            cursor: default;
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
            pointer-events: auto;
            position: relative;
            z-index: 100001;
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
            pointer-events: auto;
            position: relative;
            z-index: 100002;
            -webkit-user-select: none;
            user-select: none;
        }
        
        .delete-modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .delete-modal-body {
            padding: 24px;
            color: #1d2a38;
            font-size: 15px;
            line-height: 1.6;
            pointer-events: auto;
            position: relative;
            z-index: 100001;
        }
        
        .delete-modal-body .warning-icon {
            font-size: 32px;
            margin-bottom: 12px;
            display: block;
            color: #e74c3c;
        }
        
        .delete-modal-footer {
            padding: 16px 24px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e0e0e0;
            pointer-events: auto;
            position: relative;
            z-index: 100001;
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
            pointer-events: auto;
            position: relative;
            z-index: 100002;
            -webkit-user-select: none;
            user-select: none;
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
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-header-container">
            <?php
            // Build back URL with preserved filter parameters
            $back_url = 'deceased_records.php';
            if (!empty($return_params)) {
                $back_url .= '?' . http_build_query($return_params);
            }
            ?>
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn-back">
                <i class="bx bx-arrow-back"></i> Back
            </a>
            <div class="page-title">Edit Deceased Record</div>
            <div style="width: 120px;"></div>
        </div>
        
        <!-- Notification Bubbles -->
        <div id="successNotification" class="notification-bubble success-notification" style="display: none;">
            <i class="bi bi-check-circle-fill"></i>
            <span></span>
        </div>
        <div id="errorNotification" class="notification-bubble error-notification" style="display: none;">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span></span>
        </div>
        
        <!-- Display Messages (hidden, used for JS) -->
        <?php if ($success_message): ?>
            <div class="alert alert-success" data-message="<?php echo htmlspecialchars($success_message); ?>"></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error" data-message="<?php echo htmlspecialchars($error_message); ?>"></div>
        <?php endif; ?>
        
        <div class="form-card">
            <div class="form-title">Record for <?php echo htmlspecialchars($record['full_name']); ?></div>
            
            <div class="record-info">
                <h3>Plot Information</h3>
                <?php 
                    $rowLetter = rowNumberToLetter($record['row_number'] ?? 1);
                    $sectionName = htmlspecialchars($record['section_name'] ?? '—');
                    $rowDisplay = htmlspecialchars($rowLetter ? $rowLetter : '—');
                    $plotNumber = htmlspecialchars($record['plot_number'] ?? '—');
                ?>
                <p><strong>Section:</strong> <?php echo $sectionName; ?></p>
                <p><strong>Row:</strong> <?php echo $rowDisplay; ?></p>
                <p><strong>Plot Number:</strong> <?php echo $plotNumber; ?></p>
            </div>
            
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($record['full_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="text" class="date-mdY" id="date_of_birth" name="date_of_birth" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($record['date_of_birth']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_of_death">Date of Death</label>
                        <input type="text" class="date-mdY" id="date_of_death" name="date_of_death" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($record['date_of_death']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="burial_date">Burial Date</label>
                        <input type="text" class="date-mdY" id="burial_date" name="burial_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($record['burial_date']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="next_of_kin">Name of Lessee</label>
                        <input type="text" id="next_of_kin" name="next_of_kin" value="<?php echo htmlspecialchars($record['next_of_kin'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($record['address'] ?? ''); ?>" placeholder="Enter complete address...">
                    </div>
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($record['contact_number'] ?? ''); ?>" placeholder="Enter phone number...">
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn-delete" id="deleteBtn">Delete Record</button>
                    <button type="submit" class="btn-save">Save Changes</button>
                    <?php
                    // Build cancel URL with preserved filter parameters
                    $cancel_url = 'deceased_records.php';
                    if (!empty($return_params)) {
                        $cancel_url .= '?' . http_build_query($return_params);
                    }
                    ?>
                    <button type="button" class="btn-cancel" onclick="window.location.href='<?php echo htmlspecialchars($cancel_url); ?>'">Cancel</button>
                </div>
            </form>
            
            <!-- Hidden form for delete action -->
            <form id="deleteForm" method="POST" action="" style="display: none;">
                <input type="hidden" name="action" value="delete_record">
                <input type="hidden" name="record_id" value="<?php echo htmlspecialchars($record_id); ?>">
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="delete-confirm-modal-overlay" onclick="if(event.target===this) closeDeleteConfirmModal();">
    <div class="delete-confirm-modal" onclick="event.stopPropagation();">
        <div class="delete-modal-header">
            <span>Delete Record</span>
            <button type="button" class="delete-modal-close-btn" onclick="closeDeleteConfirmModal(); event.stopPropagation();">&times;</button>
        </div>
        <div class="delete-modal-body">
            <i class="bi bi-exclamation-triangle-fill warning-icon"></i>
            <p id="deleteModalMessage"></p>
        </div>
        <div class="delete-modal-footer">
            <button type="button" class="delete-modal-btn delete-modal-btn-cancel" onclick="closeDeleteConfirmModal(); event.stopPropagation();">Cancel</button>
            <button type="button" class="delete-modal-btn delete-modal-btn-confirm" onclick="confirmDeleteRecord(); event.stopPropagation();">Confirm Delete</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Flatpickr setup for this page
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

    // Notification bubble handling + form validation
    document.addEventListener('DOMContentLoaded', function() {
        const successNotification = document.getElementById('successNotification');
        const errorNotification = document.getElementById('errorNotification');
        const successAlert = document.querySelector('.alert-success');
        const errorAlert = document.querySelector('.alert-error');
        const form = document.querySelector('form');
        const dobInput = document.getElementById('date_of_birth');
        const dodInput = document.getElementById('date_of_death');
        const burialInput = document.getElementById('burial_date');
        
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

        // Client-side date validation before submit
        if (form) {
            form.addEventListener('submit', function (e) {
                const dobVal = dobInput ? dobInput.value : '';
                const dodVal = dodInput ? dodInput.value : '';
                const burialVal = burialInput ? burialInput.value : '';

                let errorMsg = '';

                if (dobVal && dodVal) {
                    const dobDate = new Date(dobVal);
                    const dodDate = new Date(dodVal);
                    if (dodDate < dobDate) {
                        errorMsg = 'Date of death cannot be earlier than date of birth.';
                    }
                }

                if (!errorMsg && dodVal && burialVal) {
                    const dodDate = new Date(dodVal);
                    const burialDate = new Date(burialVal);
                    if (burialDate < dodDate) {
                        errorMsg = 'Burial date cannot be earlier than date of death.';
                    }
                }

                if (errorMsg) {
                    e.preventDefault();
                    showNotification(errorNotification, errorMsg);
                }
            });
        }
        
        // Delete confirmation modal functions (make them global)
        window.openDeleteConfirmModal = function() {
            const modal = document.getElementById('deleteConfirmModal');
            const recordName = '<?php echo htmlspecialchars(addslashes($record['full_name'])); ?>';
            const messageEl = document.getElementById('deleteModalMessage');
            
            if (messageEl) {
                messageEl.innerHTML = `Are you sure you want to delete the record for <strong>"${recordName}"</strong>?<br><br>This action cannot be undone and will make the plot available.`;
            }
            
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        };
        
        window.closeDeleteConfirmModal = function() {
            const modal = document.getElementById('deleteConfirmModal');
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
            }
        };
        
        window.confirmDeleteRecord = function() {
            const deleteForm = document.getElementById('deleteForm');
            if (deleteForm) {
                deleteForm.submit();
            }
        };
        
        // Delete button handler
        const deleteBtn = document.getElementById('deleteBtn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function() {
                openDeleteConfirmModal();
            });
        }
        
        // Modal click handling is now done via inline onclick handlers
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteConfirmModal();
            }
        });
    });
</script>
</body>
</html>
