<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Initialize messages
$success_message = '';
$error_message = '';

// Handle success messages from URL parameters
if (isset($_GET['success']) && $_GET['success'] === 'plots_deleted') {
    $count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
    $success_message = "Successfully deleted $count plot(s)";
}

// Handle plot deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_plots') {
    if (isset($_POST['plot_ids']) && is_array($_POST['plot_ids'])) {
        $plot_ids = array_map('intval', $_POST['plot_ids']);
        
        // Check if any selected plots are occupied
        $check_query = "SELECT plot_id, plot_number FROM plots WHERE plot_id IN (" . implode(',', $plot_ids) . ") AND status = 'occupied'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $occupied_plots = [];
            while ($row = mysqli_fetch_assoc($check_result)) {
                $occupied_plots[] = $row['plot_number'];
            }
            $error_message = "Cannot delete occupied plots: " . implode(', ', $occupied_plots);
        } else {
            // Check if any selected plots have exhumation requests referencing them
            // First, check for active (pending/approved) requests - these block deletion
            $active_exhumation_check_query = "SELECT DISTINCT p.plot_id, p.plot_number 
                                              FROM plots p 
                                              WHERE p.plot_id IN (" . implode(',', $plot_ids) . ")
                                              AND (p.plot_id IN (
                                                       SELECT source_plot_id 
                                                       FROM exhumation_requests 
                                                       WHERE status IN ('pending','approved')
                                                   )
                                                   OR p.plot_id IN (
                                                       SELECT target_plot_id 
                                                       FROM exhumation_requests 
                                                       WHERE status IN ('pending','approved')
                                                   ))";
            $active_exhumation_check_result = mysqli_query($conn, $active_exhumation_check_query);
            
            if (mysqli_num_rows($active_exhumation_check_result) > 0) {
                $exhumation_plots = [];
                while ($row = mysqli_fetch_assoc($active_exhumation_check_result)) {
                    $exhumation_plots[] = $row['plot_number'];
                }
                $error_message = "Cannot delete plots that are referenced in active exhumation requests: " . implode(', ', $exhumation_plots);
            } else {
                // Check for rejected requests - these should be deleted automatically before plot deletion
                $rejected_check_query = "SELECT request_id FROM exhumation_requests 
                                         WHERE status = 'rejected' 
                                         AND (source_plot_id IN (" . implode(',', $plot_ids) . ")
                                              OR target_plot_id IN (" . implode(',', $plot_ids) . "))";
                $rejected_check_result = mysqli_query($conn, $rejected_check_query);
                
                if (mysqli_num_rows($rejected_check_result) > 0) {
                    // Delete rejected exhumation requests that reference these plots
                    $rejected_ids = [];
                    while ($row = mysqli_fetch_assoc($rejected_check_result)) {
                        $rejected_ids[] = (int)$row['request_id'];
                    }
                    if (!empty($rejected_ids)) {
                        $delete_rejected_query = "DELETE FROM exhumation_requests WHERE request_id IN (" . implode(',', $rejected_ids) . ")";
                        mysqli_query($conn, $delete_rejected_query);
                    }
                }
                
                // Now delete plots that are not occupied and have no active exhumation requests
                $delete_query = "DELETE FROM plots WHERE plot_id IN (" . implode(',', $plot_ids) . ") AND status != 'occupied'";
                if (mysqli_query($conn, $delete_query)) {
                    $affected_rows = mysqli_affected_rows($conn);
                    header('Location: existing_plots.php?success=plots_deleted&count=' . urlencode($affected_rows));
                    exit();
                } else {
                    $error_message = "Error deleting plots: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Get existing plots grouped by section
$plots_query = "SELECT p.*, s.section_name, d.full_name 
                FROM plots p 
                JOIN sections s ON p.section_id = s.section_id 
                LEFT JOIN deceased_records d ON p.plot_id = d.plot_id 
                ORDER BY s.section_name, p.row_number ASC, CAST(p.plot_number AS UNSIGNED) ASC, p.level_number ASC";
$plots_result = mysqli_query($conn, $plots_query);

// Group plots by section
$sections = [];
while ($plot = mysqli_fetch_assoc($plots_result)) {
    $sections[$plot['section_name']][] = $plot;
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
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        /* Page-specific styles */
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 32px;
            letter-spacing: 1px;
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 999px;
            border: 1px solid #e0e0e0;
            color: #4b5563;
            background: #ffffff;
            text-decoration: none;
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease, color 0.15s ease;
        }

        .back-button span {
            font-size: 18px;
            line-height: 1;
        }

        .back-button:hover {
            background: #f3f4f6;
            color: #111827;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            transition: opacity 0.5s ease-out;
        }
        .alert-success.fade-out {
            opacity: 0;
            pointer-events: none;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .existing-plots {
            background: white;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 12px;
            margin: 0 -24px 16px -24px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 500;
        }
        
        .select-all-btn {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .select-all-btn:hover {
            background: #5a6268;
        }

        .select-all-btn i {
            font-size: 14px;
        }
        
        .plot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .plot-item {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .plot-item.occupied {
            background: #fff3e0;
            border-color: #ffe0b2;
        }
        
        .plot-item.reserved {
            background: #e3f2fd;
            border-color: #bbdefb;
        }
        
        .section-filter-tabs {
            margin-bottom: 0;
        }
        
        .section-filter-tabs .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            width: 100%;
            justify-content: flex-start;
            align-items: flex-start;
        }
        
        .section-filter-tabs .btn-group .btn {
            flex: 0 1 auto;
            margin: 0;
            border-radius: 0.375rem !important;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
            font-size: 14px;
            padding: 8px 16px;
            transition: all 0.2s ease;
        }
        
        .section-filter-tabs .btn-outline-primary {
            border-color: #2b4c7e;
            color: #2b4c7e;
        }
        
        .section-filter-tabs .btn-outline-primary:hover {
            background-color: #e8ecf3;
            border-color: #2b4c7e;
            color: #2b4c7e;
        }
        
        .section-filter-tabs .btn-outline-primary.active {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: #fff;
        }
        
        .section-filter-tabs .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .section-filter-tabs .btn-outline-secondary:hover {
            background-color: #e9ecef;
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .section-filter-tabs .btn-outline-secondary.active {
            background-color: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }
        
        .section-filter-tabs .badge {
            font-size: 0.7em;
            padding: 0.35em 0.65em;
            border-radius: 0.5rem;
            font-weight: 600;
            background-color: rgba(255, 255, 255, 0.9) !important;
            color: #1d2a38 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .section-filter-tabs .btn.active .badge {
            background-color: rgba(255, 255, 255, 0.25) !important;
            color: #fff !important;
        }
        
        .section-block {
            margin-bottom: 32px;
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .notification-bubble i {
            font-size: 20px;
        }
        
        .notification-bubble span {
            display: inline-block;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #00b894, #00a184);
        }
        
        .error-notification {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
        
        /* Override Bootstrap primary button color for consistency */
        .btn-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
        }
        
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #1f3659;
            border-color: #1f3659;
        }
        
        .btn-danger {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
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
            
            .table-card {
                padding: 18px 16px;
                overflow-x: auto;
            }
        }
        
            
            .table-card {
                padding: 12px 8px;
                overflow-x: auto;
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
    </style>
    <script src="../assets/js/flash_clean_query.js"></script>
</head>
<body>
    <!-- Notification Bubble -->
    <div id="notificationBubble" class="notification-bubble">
        <i></i>
        <span></span>
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
                <p style="margin: 0; font-weight: 500; margin-bottom: 12px;">Are you sure you want to delete the selected plot(s)?</p>
                <p style="margin: 0; font-size: 14px; color: #6c757d;">
                    <strong>Reminder:</strong> This action permanently removes the selected plot(s) from the system, including all their details, capacity, and availability status. Only proceed if the plot(s) are incorrect or should not be part of the cemetery layout.
                </p>
            </div>
            <div class="delete-modal-footer">
                <button type="button" class="delete-modal-btn delete-modal-btn-cancel" onclick="closeDeleteConfirmModal()">Cancel</button>
                <button type="button" class="delete-modal-btn delete-modal-btn-confirm" onclick="confirmDeletePlots()">Confirm Delete</button>
            </div>
        </div>
    </div>
    
    <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
        <div class="main">
            <div class="page-header">
                <div style="flex: 1;">
                    <a href="plots.php" class="back-button" title="Back to Plots Management">
                        <span>←</span>
                    </a>
                </div>
                <div style="flex: 1; text-align: center;">
                    <h1 class="page-title">Existing Plots</h1>
                </div>
                <div style="flex: 1;">
                    <!-- spacer to keep title centered -->
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <div class="existing-plots">
                <!-- Global filters for deleting plots -->
                <div class="row mb-3">
                    <div class="col-md-4 mb-2">
                        <label class="form-label mb-1">Filter by Row</label>
                        <select id="rowFilter" class="form-select form-select-sm">
                            <option value="all">All Rows</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" id="plotSearch" class="form-control form-control-sm" placeholder="Search plot number...">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label mb-1">Plot Status</label>
                        <select id="statusFilter" class="form-select form-select-sm">
                            <option value="all">All Status</option>
                            <option value="available">Available</option>
                            <option value="reserved">Reserved</option>
                            <option value="occupied">Occupied</option>
                        </select>
                    </div>
                </div>

                <!-- Section Filter Tabs -->
                <div class="card mb-4" style="border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div class="card-body" style="padding: 20px;">
                        <h6 class="card-title mb-3" style="font-weight: 600; color: #333; margin: 0 0 16px 0;">Filter by Section</h6>
                        <div class="section-filter-tabs">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary active" onclick="filterBySection('all')" data-section="all">
                                    <i class="bi bi-grid-3x3-gap"></i> All Sections
                                </button>
                                <?php foreach ($sections as $section_name => $plots): ?>
                                <button type="button" class="btn btn-outline-secondary" onclick="filterBySection('<?php echo htmlspecialchars($section_name); ?>')" data-section="<?php echo htmlspecialchars($section_name); ?>">
                                    <?php echo htmlspecialchars($section_name); ?>
                                    <span class="badge bg-light text-dark ms-1"><?php echo count($plots); ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" id="deletePlotsForm">
                    <input type="hidden" name="action" value="delete_plots">
                    
                    <?php foreach ($sections as $section_name => $plots): ?>
                    <div class="section-block mb-4" data-section-block="<?php echo htmlspecialchars($section_name); ?>">
                        <div class="section-header">
                            <span><?php echo htmlspecialchars($section_name); ?></span>
                        </div>
                        <div style="padding: 12px 24px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                            <div class="d-flex align-items-center gap-3">
                                <button type="button" class="select-all-btn" onclick="toggleSectionPlots('<?php echo htmlspecialchars($section_name, ENT_QUOTES); ?>')" data-section="<?php echo htmlspecialchars($section_name, ENT_QUOTES); ?>">
                                    <i class="bi bi-check-square"></i> Select All Available
                                </button>
                                <span class="section-selection-count" data-section="<?php echo htmlspecialchars($section_name, ENT_QUOTES); ?>" style="font-weight: 500; color: #6c757d; font-size: 13px;">0 plots selected</span>
                            </div>
                            <button type="button" class="btn btn-danger btn-sm section-delete-btn" onclick="showDeleteConfirmModal()" style="display: none;">
                                <i class="bi bi-trash"></i> Delete Selected Plots
                            </button>
                        </div>
                        <div class="plot-grid" data-section-grid="<?php echo htmlspecialchars($section_name); ?>">
                            <?php foreach ($plots as $plot): ?>
                            <div class="plot-item <?php echo $plot['status']; ?>" data-row="<?php echo (int)$plot['row_number']; ?>" data-plot-number="<?php echo htmlspecialchars($plot['plot_number'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars($plot['status']); ?>">
                                <input type="checkbox" name="plot_ids[]" value="<?php echo $plot['plot_id']; ?>"
                                       <?php echo $plot['status'] === 'occupied' ? 'disabled' : ''; ?>
                                       class="plot-checkbox"
                                       data-section="<?php echo htmlspecialchars($section_name); ?>">
                                <span>
                                    <?php 
                                    echo htmlspecialchars($plot['plot_number']); 
                                    if (!empty($plot['full_name'])) {
                                        echo ' - ' . htmlspecialchars($plot['full_name']);
                                    }
                                    ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Delete confirmation modal functions
        function showDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.add('show');
        }
        
        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
        }
        
        function confirmDeletePlots() {
            const form = document.getElementById('deletePlotsForm');
            if (form) {
                form.submit();
            }
        }
        
        // Notification bubble function
        function showNotification(message, type = 'success') {
            const bubble = document.getElementById('notificationBubble');
            const icon = bubble.querySelector('i');
            const text = bubble.querySelector('span');
            
            // Remove existing classes
            bubble.classList.remove('success-notification', 'error-notification', 'show', 'hide');
            
            // Set icon and message
            if (type === 'success') {
                icon.className = 'bi bi-check-circle-fill';
                bubble.classList.add('success-notification');
            } else {
                icon.className = 'bi bi-x-circle-fill';
                bubble.classList.add('error-notification');
            }
            
            text.textContent = message;
            
            // Show notification
            bubble.classList.add('show');
            bubble.style.pointerEvents = 'auto';
            
            // Hide after 3 seconds
            setTimeout(() => {
                bubble.classList.remove('show');
                bubble.classList.add('hide');
                setTimeout(() => {
                    bubble.style.pointerEvents = 'none';
                }, 250);
            }, 3000);
        }
        
        
        // Enhanced plot management functions
        let selectedSections = new Set(['all']); // Track multiple selected sections
        let currentRowFilter = 'all';
        let currentStatusFilter = 'all';
        let currentSearchQuery = '';
        
        // Filter plots by section (allows multiple selections)
        window.filterBySection = function(sectionName) {
            const button = document.querySelector(`.section-filter-tabs [data-section="${sectionName}"]`);
            
            if (sectionName === 'all') {
                // If "All Sections" is clicked, clear all other selections
                selectedSections.clear();
                selectedSections.add('all');
                document.querySelectorAll('.section-filter-tabs [data-section]').forEach(btn => {
                    btn.classList.remove('active');
                });
                if (button) button.classList.add('active');
            } else {
                // Remove "all" if a specific section is selected
                selectedSections.delete('all');
                const allButton = document.querySelector('.section-filter-tabs [data-section="all"]');
                if (allButton) allButton.classList.remove('active');
                
                // Toggle the clicked section
                if (selectedSections.has(sectionName)) {
                    selectedSections.delete(sectionName);
                    if (button) button.classList.remove('active');
                } else {
                    selectedSections.add(sectionName);
                    if (button) button.classList.add('active');
                }
                
                // If no sections are selected, default to "all"
                if (selectedSections.size === 0) {
                    selectedSections.add('all');
                    const allBtn = document.querySelector('.section-filter-tabs [data-section="all"]');
                    if (allBtn) allBtn.classList.add('active');
                }
            }

            applyPlotFilters();
        };

        // Apply combined filters (section, row, search) to plot items
        function applyPlotFilters() {
            const allSections = document.querySelectorAll('.section-block');
            allSections.forEach(section => {
                const plots = section.querySelectorAll('.plot-item');
                let hasVisible = false;

                plots.forEach(item => {
                    const checkbox = item.querySelector('.plot-checkbox');
                    const itemSection = checkbox ? checkbox.dataset.section : '';
                    const itemRow = item.dataset.row || '';
                    const itemStatus = item.dataset.status || '';
                    const plotNumber = (item.dataset.plotNumber || '').toLowerCase();

                    // Check if section matches any of the selected sections
                    const matchesSection = selectedSections.has('all') || selectedSections.has(itemSection);
                    const matchesRow = (currentRowFilter === 'all' || String(itemRow) === String(currentRowFilter));
                    const matchesStatus = (currentStatusFilter === 'all' || itemStatus === currentStatusFilter);
                    const matchesSearch = (!currentSearchQuery || plotNumber === currentSearchQuery);

                    const isVisible = matchesSection && matchesRow && matchesStatus && matchesSearch;
                    item.style.display = isVisible ? '' : 'none';
                    if (isVisible) {
                        hasVisible = true;
                    }
                });

                // Hide entire section block if no plots are visible
                section.style.display = hasVisible ? '' : 'none';
            });
        }
        
        // Select all available plots across all sections (toggles if all are selected)
        window.selectAllAvailable = function() {
            const availableCheckboxes = document.querySelectorAll('.plot-checkbox:not(:disabled)');
            const checkedBoxes = document.querySelectorAll('.plot-checkbox:not(:disabled):checked');
            const allSelected = availableCheckboxes.length > 0 && checkedBoxes.length === availableCheckboxes.length;
            
            // Toggle: if all are selected, unselect all; otherwise select all
            const shouldSelect = !allSelected;
            availableCheckboxes.forEach(checkbox => {
                checkbox.checked = shouldSelect;
            });
            updateSelectionInfo();
        };
        
        // Clear all selections
        window.clearAllSelections = function() {
            const allCheckboxes = document.querySelectorAll('.plot-checkbox');
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectionInfo();
        };
        
        // Update selection info display
        function updateSelectionInfo() {
            // Count only enabled checkboxes that are checked
            const checkedBoxes = document.querySelectorAll('.plot-checkbox:not(:disabled):checked');
            const count = checkedBoxes.length;
            
            // Update all section-specific Select All buttons and selection counts
            document.querySelectorAll('.select-all-btn[data-section]').forEach(button => {
                const sectionName = button.dataset.section;
                const sectionCheckboxes = document.querySelectorAll(`.plot-checkbox[data-section="${sectionName}"]:not(:disabled)`);
                const sectionChecked = document.querySelectorAll(`.plot-checkbox[data-section="${sectionName}"]:not(:disabled):checked`);
                const allChecked = sectionCheckboxes.length > 0 && sectionChecked.length === sectionCheckboxes.length;
                const sectionCount = sectionChecked.length;
                
                // Update button text
                if (allChecked && sectionCheckboxes.length > 0) {
                    button.innerHTML = '<i class="bi bi-check-square-fill"></i> Unselect All';
                } else {
                    button.innerHTML = '<i class="bi bi-check-square"></i> Select All Available';
                }
                
                // Update section selection count
                const sectionCounter = document.querySelector(`.section-selection-count[data-section="${sectionName}"]`);
                if (sectionCounter) {
                    sectionCounter.textContent = `${sectionCount} plot${sectionCount === 1 ? '' : 's'} selected`;
                }
            });
            
            // Show/hide all section delete buttons based on selection
            const sectionDeleteButtons = document.querySelectorAll('.section-delete-btn');
            sectionDeleteButtons.forEach(btn => {
                if (count > 0) {
                    btn.style.display = '';
                } else {
                    btn.style.display = 'none';
                }
            });
        }
        
        // Toggle section plots
        window.toggleSectionPlots = function(sectionName) {
            const sectionCheckboxes = document.querySelectorAll(`.plot-checkbox[data-section="${sectionName}"]:not(:disabled)`);
            const button = document.querySelector(`.select-all-btn[data-section="${sectionName}"]`);
            const allChecked = sectionCheckboxes.length > 0 && Array.from(sectionCheckboxes).every(cb => cb.checked);
            
            sectionCheckboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
            
            updateSelectionInfo();
        };
        
        // Auto-hide success messages after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successAlerts = document.querySelectorAll('.alert-success');
            successAlerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('fade-out');
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 3000);
            });
        });

        // Initialize enhanced functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Populate row filter options from existing plots
            const rowFilterSelect = document.getElementById('rowFilter');
            if (rowFilterSelect) {
                const rowSet = new Set();
                document.querySelectorAll('.plot-item').forEach(item => {
                    const row = item.dataset.row;
                    if (row) {
                        rowSet.add(row);
                    }
                });

                // Function to convert row number to letter (1=A, 2=B, 3=C, 4=D, 5=E)
                function rowNumberToLetter(rowNum) {
                    const rowMap = {
                        '1': 'A',
                        '2': 'B',
                        '3': 'C',
                        '4': 'D',
                        '5': 'E'
                    };
                    return rowMap[rowNum] || rowNum;
                }

                Array.from(rowSet)
                    .sort((a, b) => Number(a) - Number(b))
                    .forEach(row => {
                        const opt = document.createElement('option');
                        opt.value = row;
                        opt.textContent = 'ROW ' + rowNumberToLetter(row);
                        rowFilterSelect.appendChild(opt);
                    });

                rowFilterSelect.addEventListener('change', function() {
                    currentRowFilter = this.value || 'all';
                    applyPlotFilters();
                });
            }

            // Wire up status filter dropdown
            const statusFilterSelect = document.getElementById('statusFilter');
            if (statusFilterSelect) {
                statusFilterSelect.addEventListener('change', function() {
                    currentStatusFilter = this.value || 'all';
                    applyPlotFilters();
                });
            }

            // Wire up search box for plot numbers
            const plotSearchInput = document.getElementById('plotSearch');
            if (plotSearchInput) {
                plotSearchInput.addEventListener('input', function() {
                    currentSearchQuery = this.value.trim().toLowerCase();
                    applyPlotFilters();
                });
            }

            // Add change event listeners to all checkboxes
            document.querySelectorAll('.plot-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function(e) {
                    // Update selection count and button states
                    updateSelectionInfo();
                });
            });
            
            // Also add click event listener as a backup to ensure count updates
            document.querySelectorAll('.plot-checkbox').forEach(checkbox => {
                checkbox.addEventListener('click', function() {
                    // Use setTimeout to ensure the checkbox state is updated before counting
                    setTimeout(function() {
                        updateSelectionInfo();
                    }, 0);
                });
            });
            
            // Initialize with all sections visible and filters applied
            filterBySection('all');
            applyPlotFilters();
            
            // Show notifications for PHP messages
            <?php if (!empty($success_message)): ?>
            showNotification('<?php echo addslashes($success_message); ?>', 'success');
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            showNotification('<?php echo addslashes($error_message); ?>', 'error');
            <?php endif; ?>
            
            // Handle form submission - show confirmation modal
            const deleteForm = document.getElementById('deletePlotsForm');
            if (deleteForm) {
                deleteForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const checkedBoxes = document.querySelectorAll('.plot-checkbox:not(:disabled):checked');
                    if (checkedBoxes.length === 0) {
                        showNotification('Please select at least one plot to delete', 'error');
                        return false;
                    }
                    // Show confirmation modal
                    showDeleteConfirmModal();
                    return false;
                });
            }
            
            // Close modal when clicking outside
            const deleteModal = document.getElementById('deleteConfirmModal');
            if (deleteModal) {
                deleteModal.addEventListener('click', function(e) {
                    if (e.target === deleteModal) {
                        closeDeleteConfirmModal();
                    }
                });
            }
            
            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('deleteConfirmModal');
                    if (modal && modal.classList.contains('show')) {
                        closeDeleteConfirmModal();
                    }
                }
            });
        });
    </script>
</body>
</html>

