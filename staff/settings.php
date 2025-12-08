<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

$message = '';
$error = '';
$system_version = 'v1.0.0';
$success_message = '';
$error_message = '';

// Handle backup error messages from URL parameters
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars(urldecode($_GET['error']));
}

// Handle Change Password
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $staff_id = $_SESSION['user_id'];

    // Fetch current password hash
    $result = mysqli_query($conn, "SELECT password FROM users WHERE user_id = $staff_id AND role = 'staff'");
    $row = mysqli_fetch_assoc($result);
    if ($row && password_verify($current_password, $row['password'])) {
        if (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE users SET password = '" . mysqli_real_escape_string($conn, $new_hash) . "' WHERE user_id = $staff_id");
            $message = 'Password changed successfully!';
        }
    } else {
        $error = 'Current password is incorrect.';
    }
}

// Bulk import, delete records, and backup/restore functionality has been moved to deceased_records.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
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
            
            .centered-container {
                max-width: calc(100% - 40px);
                padding: 0 20px;
            }
            .form-container {
                padding: 32px 24px;
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
                padding: 16px 0 !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .centered-container {
                max-width: calc(100% - 20px);
                padding: 0 15px;
            }
            .form-container {
                padding: 24px 18px;
            }
            .settings-section {
                padding: 0;
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
            font-size: 2rem;
            font-weight: 500;
            margin-bottom: 0;
            letter-spacing: 1px;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .back-button {
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
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
        }
        
        .back-button:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .back-button span {
            font-size: 20px;
        }
        
        /* Centered Container Wrapper */
        .centered-container {
            max-width: calc(100% - 80px);
            width: 100%;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Navigation Container Box */
        .navigation-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 0;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        /* Tree Navigation */
        .tree-navigation {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .tree-nav-item {
            flex: 1;
            padding: 16px 24px;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 15px;
            font-weight: 400;
            cursor: pointer;
            border: none;
            background: none;
            text-align: center;
            position: relative;
            border-bottom: 3px solid transparent;
        }
        
        .tree-nav-item:hover {
            background: #f8f9fa;
            color: #212529;
        }
        
        .tree-nav-item.active {
            color: #0d6efd;
            font-weight: 500;
            border-bottom-color: #0d6efd;
            background: #f8f9fa;
        }
        
        /* Form Container Box */
        .form-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 40px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .settings-section {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            box-shadow: none;
            width: 100%;
        }
        
        .settings-section form {
            width: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .settings-section h3 {
            display: none;
        }
        .settings-section h3 {
            font-size: 20px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
        }
        .form-group {
            margin-bottom: 24px;
            width: 100%;
        }
        label {
            display: block;
            font-size: 15px;
            color: #333;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input[type="text"], 
        input[type="email"], 
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            background: #fafafa;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper input {
            padding-right: 48px;
        }
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #5b6c86;
            font-size: 1.2rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .password-toggle:hover,
        .password-toggle:focus {
            color: #2b4c7e;
            outline: none;
        }
        
        input[type="file"] {
            width: 100%;
            padding: 8px 12px;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus, 
        input[type="email"]:focus, 
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: #0d6efd;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }
        
        button[type="submit"] {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        button[type="submit"]:hover {
            background: #1f3659;
        }
        .system-version {
            margin-top: 32px;
            color: #888;
            font-size: 15px;
            text-align: center;
        }
        @media (max-width: 700px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100vw; min-height: 60px; height: 60px; position: static; }
            .sidebar.collapsed { width: 100px; }
            .main { margin-left: 0 !important; }
            .sidebar.collapsed + .main { margin-left: 0 !important; }
        }
        .archive-section {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            box-shadow: none;
        }
        
        .archive-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .archive-table th,
        .archive-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .archive-table th {
            background: #f8f9fa;
            font-weight: 500;
        }
        
        .archive-table tr:hover {
            background: #f8f9fa;
        }
        
        .archive-info {
            color: #666;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .btn-primary {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s;
            margin: 0;
        }
        
        .btn-primary:hover {
            background: #1f3659;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        
        .modal.show {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
        }
        
        .modal-content {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 500;
            color: #222;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            margin: 0;
        }
        
        .modal-body {
            margin-bottom: 24px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .detail-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .detail-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 15px;
            color: #222;
            font-weight: 500;
        }
        
        .view-btn {
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 4px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .view-btn:hover {
            background: #0b5ed7;
        }
        
        /* Notification Bubble Styles */
        .notification-bubble {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            font-weight: 500;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            z-index: 9999;
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 400px;
            word-wrap: break-word;
        }
        
        .notification-bubble.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification-bubble.hide {
            transform: translateX(400px);
            opacity: 0;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-left: 4px solid #065f46;
        }
        
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-left: 4px solid #991b1b;
        }
        
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-bubble span {
            flex: 1;
        }
        
        /* Backup & Restore Styles */
        #backup_sections {
            min-height: 120px;
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary i {
            font-size: 16px;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="centered-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
                <div style="flex: 1;">
                    <a href="profile.php" class="back-button">
                        <span>←</span>
                    </a>
                </div>
                <div style="flex: 1; text-align: center;">
                    <div class="page-title" style="margin: 0;">Settings</div>
                </div>
                <div style="flex: 1;">
                    <!-- Empty div to maintain centering -->
                </div>
            </div>
        <?php if ($message): ?>
            <div id="generalSuccessNotification" class="notification-bubble success-notification">
                <i class="bi bi-check-circle-fill"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div id="generalErrorNotification" class="notification-bubble error-notification">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div id="successNotification" class="notification-bubble success-notification">
                <i class="bi bi-check-circle-fill"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div id="errorNotification" class="notification-bubble error-notification">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Navigation Container Box -->
        <div class="navigation-container">
            <div class="tree-navigation">
                <button class="tree-nav-item active" data-tab="password">Change Password</button>
                <button class="tree-nav-item" data-tab="backup">Backup & Restore</button>
            </div>
        </div>

        <!-- Form Container Box -->
        <div class="form-container">
            <div id="tab-password" class="tab-content active">
                <div class="settings-section">
                    <form method="post" action="">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                                        <button type="button" class="password-toggle" data-target="current_password" aria-label="Show password">
                                            <i class='bx bx-hide'></i>
                                        </button>
                                    </div>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password">
                                            <i class='bx bx-hide'></i>
                                        </button>
                                    </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                            <i class='bx bx-hide'></i>
                                        </button>
                                    </div>
                        </div>
                        <button type="submit" name="change_password">Change Password</button>
                    </form>
            </div>
            
            <div id="tab-backup" class="tab-content">
                <div class="settings-section">
                    <h3>Backup & Restore</h3>
                    <p style="color: #666; margin-bottom: 24px; font-size: 14px;">
                        Download CSV backup files of deceased records. Select sections and optionally filter by rows to export specific data.
                    </p>
                    <form method="get" action="backup_download.php" id="backupForm">
                        <div class="form-group">
                            <label for="backup_sections">Select Sections <span style="color: #dc3545;">*</span> <span id="section-count" style="color: #666; font-weight: normal; font-size: 13px;"></span></label>
                            <select id="backup_sections" name="sections[]" multiple required style="min-height: 120px;">
                                <?php
                                $sections_query = "SELECT section_id, section_name FROM sections ORDER BY section_name";
                                $sections_result = mysqli_query($conn, $sections_query);
                                if ($sections_result && mysqli_num_rows($sections_result) > 0) {
                                    while ($section = mysqli_fetch_assoc($sections_result)) {
                                        echo '<option value="' . htmlspecialchars($section['section_id']) . '">' . htmlspecialchars($section['section_name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <small style="color: #666; font-size: 13px; margin-top: 4px; display: block;">
                                Hold Ctrl (Windows) or Cmd (Mac) to select multiple sections
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="backup_row_filter">Filter by Row (Optional)</label>
                            <select id="backup_row_filter" name="row_filter">
                                <option value="">All rows</option>
                            </select>
                            <small style="color: #666; font-size: 13px; margin-top: 4px; display: block;">
                                Select a section first to see available rows, or leave as "All rows" to export all
                            </small>
                        </div>
                        <button type="submit" class="btn-primary" style="margin-top: 8px;">
                            <i class="bi bi-download"></i>
                            Download CSV Backup
                        </button>
                    </form>
                </div>
            </div>
        </div>

            <div class="system-version">
                <strong>System Version:</strong> <?php echo $system_version; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Tree navigation switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const treeNavItems = document.querySelectorAll('.tree-nav-item');
    const tabContents = document.querySelectorAll('.tab-content');

    const setActiveTab = (tabName) => {
        if (!treeNavItems.length || !tabContents.length) {
            return;
        }

        let matchedItem = null;
        treeNavItems.forEach(item => {
            if (item.getAttribute('data-tab') === tabName) {
                matchedItem = item;
            }
        });

        if (!matchedItem) {
            matchedItem = treeNavItems[0];
            tabName = matchedItem.getAttribute('data-tab');
        }

        treeNavItems.forEach(item => {
            item.classList.toggle('active', item === matchedItem);
        });

        tabContents.forEach(content => {
            content.classList.toggle('active', content.id === `tab-${tabName}`);
        });
    };

    treeNavItems.forEach(item => {
        item.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');
            setActiveTab(targetTab);

            try {
                const url = new URL(window.location);
                url.searchParams.set('tab', targetTab);
                window.history.replaceState({}, '', url);
            } catch (error) {
                console.warn('Unable to update URL for tab switch:', error);
            }
        });
    });

    const params = new URLSearchParams(window.location.search);
    const requestedTab = params.get('tab');
    setActiveTab(requestedTab || 'password');
});

// Handle backup section selection change to populate row dropdown
document.addEventListener('DOMContentLoaded', function() {
    const backupSections = document.getElementById('backup_sections');
    const backupRowFilter = document.getElementById('backup_row_filter');
    const sectionCount = document.getElementById('section-count');
    
    function updateSectionCount() {
        if (sectionCount && backupSections) {
            const count = backupSections.selectedOptions.length;
            if (count > 0) {
                sectionCount.textContent = `(${count} selected)`;
            } else {
                sectionCount.textContent = '';
            }
        }
    }
    
    if (backupSections && backupRowFilter) {
        // Update count on initial load
        updateSectionCount();
        
        backupSections.addEventListener('change', function() {
            updateSectionCount();
            const selectedSections = Array.from(this.selectedOptions).map(opt => opt.value);
            
            // Reset row dropdown
            backupRowFilter.innerHTML = '<option value="">Loading rows...</option>';
            backupRowFilter.disabled = true;
            
            if (selectedSections.length > 0) {
                // Fetch available rows for all selected sections
                const sectionIds = selectedSections.join(',');
                fetch(`get_section_rows.php?section_ids=${sectionIds}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.rows.length > 0) {
                            // Populate row dropdown with available rows
                            backupRowFilter.innerHTML = '<option value="">All rows</option>';
                            data.rows.forEach(row => {
                                const option = document.createElement('option');
                                option.value = row.row_number;
                                option.textContent = row.display_name;
                                backupRowFilter.appendChild(option);
                            });
                            backupRowFilter.disabled = false;
                        } else {
                            // No available rows found
                            backupRowFilter.innerHTML = '<option value="">All rows</option>';
                            backupRowFilter.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching rows:', error);
                        backupRowFilter.innerHTML = '<option value="">All rows</option>';
                        backupRowFilter.disabled = false;
                    });
            } else {
                // No sections selected
                backupRowFilter.innerHTML = '<option value="">All rows</option>';
                backupRowFilter.disabled = true;
            }
        });
    }
});

// Handle notification bubbles and page refresh
document.addEventListener('DOMContentLoaded', function() {
    const successNotification = document.getElementById('successNotification');
    const errorNotification = document.getElementById('errorNotification');
    const generalSuccessNotification = document.getElementById('generalSuccessNotification');
    const generalErrorNotification = document.getElementById('generalErrorNotification');
    
    // Function to show notification bubble
    function showNotification(notification) {
        if (notification) {
            // Show the notification with animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Check if it's a success notification for imports/deletions
            const message = notification.textContent;
            const isImportSuccess = message.includes('Successfully imported');
            const isDeletionSuccess = message.includes('Successfully deleted');
            const isPasswordChange = message.includes('Password changed successfully');
            
            if (isImportSuccess || isDeletionSuccess) {
                // Hide notification after 3 seconds and refresh page
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hide');
                    
                    // Refresh page after animation completes
                    setTimeout(() => {
                        window.location.href = window.location.pathname;
                    }, 500);
                }, 3000);
            } else if (isPasswordChange) {
                // For password changes, show longer and don't refresh
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hide');
                }, 5000);
            } else {
                // For other notifications, just hide after 4 seconds
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hide');
                }, 4000);
            }
        }
    }
    
    // Show all notification types if present
    showNotification(successNotification);
    showNotification(errorNotification);
    showNotification(generalSuccessNotification);
    showNotification(generalErrorNotification);
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleButtons = document.querySelectorAll('.password-toggle');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            if (!targetInput) return;
            if (targetInput.type === 'password') {
                targetInput.type = 'text';
                icon.classList.remove('bx-hide');
                icon.classList.add('bx-show');
                this.setAttribute('aria-label', 'Hide password');
            } else {
                targetInput.type = 'password';
                icon.classList.remove('bx-show');
                icon.classList.add('bx-hide');
                this.setAttribute('aria-label', 'Show password');
            }
        });
    });
});
</script>
</body>
</html> 