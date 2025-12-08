<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

if (!isset($_GET['id'])) {
    header('Location: plots.php');
    exit();
}

$plot_id = intval($_GET['id']);

// Detect which deceased table to use (new unified deceased_records vs legacy deceased)
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;

// Get plot and deceased information with contract details
if ($use_deceased_records) {
    $query = "SELECT 
                p.*, 
                d.record_id AS deceased_id, 
                d.full_name,
                d.date_of_birth, 
                d.date_of_death, 
                d.burial_date AS date_of_burial,
                s.section_name,
                s.section_code
              FROM plots p 
              LEFT JOIN deceased_records d ON p.plot_id = d.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.plot_id = ?";
} else {
    $query = "SELECT 
                p.*, 
                d.deceased_id, 
                d.first_name, 
                d.last_name, 
                d.date_of_birth, 
                d.date_of_death, 
                d.date_of_burial,
                s.section_name,
                s.section_code
              FROM plots p 
              LEFT JOIN deceased d ON p.plot_id = d.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.plot_id = ?";
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $plot_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$plot = mysqli_fetch_assoc($result);

// Check if plot exists
if (!$plot) {
    header('Location: plots.php');
    exit();
}

// Prepare name pieces for the form (supports both full_name and first/last schema)
$existing_first_name = $plot['first_name'] ?? '';
$existing_last_name = $plot['last_name'] ?? '';

if ($use_deceased_records && !empty($plot['full_name'])) {
    $name_parts = explode(' ', $plot['full_name'], 2);
    $existing_first_name = $name_parts[0];
    $existing_last_name = $name_parts[1] ?? '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        // Handle status update
        $new_status = mysqli_real_escape_string($conn, $_POST['status']);
        $plot_id = intval($_POST['plot_id']);
        
        $update_query = "UPDATE plots SET status = ? WHERE plot_id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $plot_id);
        
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
        // Fixed contract type for 5-year contracts (no user classification)
        $contract_type = 'temporary';
        $contract_status = $_POST['contract_status'] ?? 'active';
        $contract_notes = $_POST['contract_notes'] ?? null;
        $renewal_reminder_date = $_POST['renewal_reminder_date'] ?? null;
        
        // Update plot contract information
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
            $success_message = "Contract information updated successfully!";
        } else {
            $error = "Error updating contract information: " . mysqli_error($conn);
        }
    } else {
        // Handle deceased information update
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $date_of_birth = $_POST['date_of_birth'] ?? '';
        $date_of_death = $_POST['date_of_death'] ?? '';
        $date_of_burial = $_POST['date_of_burial'] ?? '';

        // Sanitize for safety (even though we use prepared statements)
        $date_of_birth = mysqli_real_escape_string($conn, $date_of_birth);
        $date_of_death = mysqli_real_escape_string($conn, $date_of_death);
        $date_of_burial = mysqli_real_escape_string($conn, $date_of_burial);

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
                // Use unified deceased_records table so data shows in Plots & Contracts
                $check_query = "SELECT record_id FROM deceased_records WHERE plot_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);

                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    // Update existing record
                    $query = "UPDATE deceased_records SET 
                              full_name = ?, 
                              date_of_birth = ?, 
                              date_of_death = ?, 
                              burial_date = ? 
                              WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssssi",
                        $full_name,
                        $date_of_birth,
                        $date_of_death,
                        $date_of_burial,
                        $plot_id
                    );
                } else {
                    // Insert new record
                    $query = "INSERT INTO deceased_records (full_name, date_of_birth, date_of_death, burial_date, plot_id) 
                              VALUES (?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssssi",
                        $full_name,
                        $date_of_birth,
                        $date_of_death,
                        $date_of_burial,
                        $plot_id
                    );
                }
            } else {
                // Legacy path: use older deceased table (fallback)
                $first_name_db = mysqli_real_escape_string($conn, $first_name);
                $last_name_db = mysqli_real_escape_string($conn, $last_name);

                // Check if deceased record already exists
                $check_query = "SELECT deceased_id FROM deceased WHERE plot_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);

                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    // Update existing record
                    $query = "UPDATE deceased SET 
                              first_name = ?, 
                              last_name = ?, 
                              date_of_birth = ?, 
                              date_of_death = ?, 
                              date_of_burial = ? 
                              WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssssi",
                        $first_name_db,
                        $last_name_db,
                        $date_of_birth,
                        $date_of_death,
                        $date_of_burial,
                        $plot_id
                    );
                } else {
                    // Insert new record
                    $query = "INSERT INTO deceased (first_name, last_name, date_of_birth, date_of_death, date_of_burial, plot_id) 
                              VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssssi",
                        $first_name_db,
                        $last_name_db,
                        $date_of_birth,
                        $date_of_death,
                        $date_of_burial,
                        $plot_id
                    );
                }
            }

            if (isset($stmt) && mysqli_stmt_execute($stmt)) {
                // Update plot status to occupied so it appears in overviews & contracts
                mysqli_query($conn, "UPDATE plots SET status = 'occupied' WHERE plot_id = " . intval($plot_id));

                // If this submission also includes contract fields, update them here
                if (isset($_POST['action']) && $_POST['action'] === 'update_all') {
                    $contract_start_date = $_POST['contract_start_date'] ?? null;
                    $contract_end_date = $_POST['contract_end_date'] ?? null;
                    // Fixed contract type for 5-year contracts (no user classification)
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
                        mysqli_stmt_bind_param(
                            $contract_stmt,
                            "ssssssi",
                            $contract_start_date,
                            $contract_end_date,
                            $contract_type,
                            $contract_status,
                            $contract_notes,
                            $renewal_reminder_date,
                            $plot_id
                        );

                        if (!mysqli_stmt_execute($contract_stmt)) {
                            $error = "Error updating contract information: " . mysqli_error($conn);
                        }
                    } else {
                        $error = "Error preparing contract update statement: " . mysqli_error($conn);
                    }
                }

                // Only redirect if no contract update error occurred
                if (!isset($error)) {
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
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
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
        .page-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #222;
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
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
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
    </style>
</head>
<body>
    <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
        <div class="main">
            <div class="page-title"><?php echo $plot['deceased_id'] ? 'Edit' : 'Add'; ?> Deceased Information</div>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
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
                    <div class="form-label">Row</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <?php 
                            $rowLetter = chr(64 + (int)($plot['row_number'] ?? 1));
                            echo htmlspecialchars($rowLetter ? $rowLetter : '—');
                        ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-label">Plot Number</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <?php echo htmlspecialchars($plot['plot_number'] ?? '—'); ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-label">Current Status</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <?php echo ucfirst(htmlspecialchars($plot['status'])); ?>
                    </div>
                </div>
                <?php if (!empty($plot['contract_status'])): ?>
                <div class="form-group">
                    <div class="form-label">Contract Status</div>
                    <div class="form-control" style="background: #f8f9fa;">
                        <span class="contract-status <?php echo str_replace('_', '-', $plot['contract_status']); ?>">
                            <?php echo ucwords(str_replace('_', ' ', $plot['contract_status'])); ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($plot['status'] === 'occupied' || $plot['status'] === 'reserved'): ?>
            <form method="POST">
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
                        <input type="text" class="form-control date-mdY" name="date_of_birth" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['date_of_birth'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date of Death</label>
                        <input type="text" class="form-control date-mdY" name="date_of_death" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['date_of_death'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date of Burial</label>
                        <input type="text" class="form-control date-mdY" name="date_of_burial" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['date_of_burial'] ?? ''); ?>" required>
                    </div>
                </div>
            
            <!-- Contract Management Section (combined with deceased form) -->
                <div class="form-card">
                    <div class="form-title">Contract Management</div>
                
                    <div class="form-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Duration</label>
                            <div class="form-control" style="background: #f8f9fa;">
                                5 years (fixed)
                            </div>
                            <!-- Keep a hidden field for backend compatibility, but users cannot change type -->
                            <input type="hidden" name="contract_type" value="temporary">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Status</label>
                            <select class="form-control" name="contract_status" required>
                                <option value="active" <?php echo ($plot['contract_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo ($plot['contract_status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="renewal_needed" <?php echo ($plot['contract_status'] ?? '') === 'renewal_needed' ? 'selected' : ''; ?>>Renewal Needed</option>
                                <option value="cancelled" <?php echo ($plot['contract_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                
                    <div class="form-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract Start Date</label>
                            <input type="text" class="form-control date-mdY" name="contract_start_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['contract_start_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Contract End Date</label>
                            <input type="text" class="form-control date-mdY" name="contract_end_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['contract_end_date'] ?? ''); ?>">
                        </div>
                    </div>
                
                    <div class="form-group">
                        <label class="form-label">Renewal Reminder Date</label>
                        <input type="text" class="form-control date-mdY" name="renewal_reminder_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($plot['renewal_reminder_date'] ?? ''); ?>">
                    </div>
                
                    <div class="form-group">
                        <label class="form-label">Contract Notes</label>
                        <textarea class="form-control" name="contract_notes" rows="3" placeholder="Enter any additional contract notes..."><?php echo htmlspecialchars($plot['contract_notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="plot_details.php?id=<?php echo $plot_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Information &amp; Contract
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="form-card">
                <div class="form-title">Plot Actions</div>
                <p>This plot is currently available. You can:</p>
                <div class="action-buttons">
                    <a href="plot_details.php?id=<?php echo $plot_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Plot Details
                    </a>
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

            <div class="action-buttons">
                <a href="plots.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Plots
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-update contract status based on end date and derive renewal reminder date
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.querySelector('input[name="contract_start_date"]');
            const endDateInput = document.querySelector('input[name="contract_end_date"]');
            const statusSelect = document.querySelector('select[name="contract_status"]');
            const reminderInput = document.querySelector('input[name="renewal_reminder_date"]');

            function formatForInput(dateObj) {
                const yyyy = dateObj.getFullYear();
                const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
                const dd = String(dateObj.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            }

            function addYears(dateString, years) {
                const d = new Date(dateString);
                if (isNaN(d.getTime())) return null;
                d.setFullYear(d.getFullYear() + years);
                return d;
            }

            function updateEndDateFromStart() {
                if (!startDateInput || !endDateInput || !startDateInput.value) return;
                const endDate = addYears(startDateInput.value, 5); // fixed 5-year contracts
                if (!endDate) return;
                endDateInput.value = formatForInput(endDate);
                
                // Set renewal reminder date (30 days before end date)
                if (reminderInput) {
                    const reminderDate = new Date(endDate);
                    reminderDate.setDate(reminderDate.getDate() - 30);
                    reminderInput.value = formatForInput(reminderDate);
                }

                // Trigger status recalculation based on new end date
                endDateInput.dispatchEvent(new Event('change'));
            }

            if (endDateInput && statusSelect) {
                endDateInput.addEventListener('change', function() {
                    const endDate = new Date(this.value);
                    const today = new Date();
                    
                    if (this.value && endDate < today) {
                        statusSelect.value = 'expired';
                    } else if (this.value && (endDate.getTime() - today.getTime() <= 30 * 24 * 60 * 60 * 1000)) { // 30 days
                        statusSelect.value = 'renewal_needed';
                    } else {
                        statusSelect.value = 'active';
                    }

                    // Keep renewal reminder date 30 days before the (possibly edited) end date
                    if (this.value && reminderInput) {
                        const reminderDate = new Date(endDate);
                        reminderDate.setDate(reminderDate.getDate() - 30);
                        reminderInput.value = formatForInput(reminderDate);
                    }
                });
            }

            if (startDateInput) {
                // Auto-derive end date when start date changes
                startDateInput.addEventListener('change', updateEndDateFromStart);

                // If there is an initial start date but no end date, populate it on load
                if (startDateInput.value && (!endDateInput || !endDateInput.value)) {
                    updateEndDateFromStart();
                }
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Flatpickr setup for all date fields on this page
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.flatpickr) return;
            
            const dateInputs = document.querySelectorAll('input.date-mdY');
            if (!dateInputs.length) return;

            dateInputs.forEach(function (input) {
                // Skip if already initialized
                if (input._flatpickr) {
                    return;
                }
                
                flatpickr(input, {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "m/d/Y",
                    allowInput: true,
                    static: false  // Position relative to input, not static
                });
            });
        });
    </script>
</body>
</html> 