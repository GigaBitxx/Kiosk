<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

$message = '';
$error = '';
$system_version = 'v1.0.0';

// Handle Change Password
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $admin_id = $_SESSION['user_id'];

    // Fetch current password hash
    $result = mysqli_query($conn, "SELECT password FROM users WHERE user_id = $admin_id AND role = 'admin'");
    $row = mysqli_fetch_assoc($result);
    if ($row && password_verify($current_password, $row['password'])) {
        if (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters long.';
            log_action('Warning', 'Admin password change failed: new password too short', $admin_id);
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
            log_action('Warning', 'Admin password change failed: new passwords do not match', $admin_id);
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE users SET password = '" . mysqli_real_escape_string($conn, $new_hash) . "' WHERE user_id = $admin_id");
            $message = 'Password changed successfully!';
            log_action('Info', 'Admin changed their password', $admin_id);
        }
    } else {
        $error = 'Current password is incorrect.';
        log_action('Warning', 'Admin password change failed: current password incorrect', $admin_id);
    }
}

// Handle Admin Registration Code Update
if (isset($_POST['update_admin_code'])) {
    // Check if column exists first
    $check_column = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'admin_registration_code'");
    if (!$check_column || mysqli_num_rows($check_column) == 0) {
        $error = 'Admin registration code column does not exist. Please run the database migration: database/add_admin_code_to_settings.sql';
        log_action('Warning', 'Admin registration code update failed: column does not exist', $_SESSION['user_id']);
    } else {
        $new_code = trim($_POST['admin_registration_code']);
        if (empty($new_code)) {
            $error = 'Admin registration code cannot be empty.';
            log_action('Warning', 'Admin registration code update failed: empty code', $_SESSION['user_id']);
        } elseif (strlen($new_code) < 8) {
            $error = 'Admin registration code must be at least 8 characters long.';
            log_action('Warning', 'Admin registration code update failed: code too short', $_SESSION['user_id']);
        } else {
            $sql = "UPDATE settings SET admin_registration_code = '" . mysqli_real_escape_string($conn, $new_code) . "' WHERE id = 1";
            if (mysqli_query($conn, $sql)) {
                $message = 'Admin registration code updated successfully!';
                log_action('Info', 'Admin registration code updated', $_SESSION['user_id']);
                $admin_registration_code = $new_code; // Update local variable
            } else {
                $error = 'Failed to update admin registration code: ' . mysqli_error($conn);
                log_action('Warning', 'Failed to update admin registration code: ' . mysqli_error($conn), $_SESSION['user_id']);
            }
        }
    }
}

// Check if admin_registration_code column exists
$column_exists = false;
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'admin_registration_code'");
if ($check_column && mysqli_num_rows($check_column) > 0) {
    $column_exists = true;
}

// Load admin registration code if column exists
$admin_registration_code = '';
if ($column_exists) {
    $res = mysqli_query($conn, "SELECT admin_registration_code FROM settings WHERE id = 1");
if ($row = mysqli_fetch_assoc($res)) {
        $admin_registration_code = $row['admin_registration_code'] ?? '';
    }
}
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
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <?php include 'includes/styles.php'; ?>
    <style>
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 32px;
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
            color: #2b4c7e;
            font-weight: 500;
            border-bottom-color: #2b4c7e;
            background: #f8f9fa;
        }
        
        /* Form Container Box */
        .form-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 40px;
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
        }
        
        .form-group {
            margin-bottom: 24px;
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
            background: #fafafa;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper input {
            padding-right: 48px;
            background: #fafafa;
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
        
        input[type="text"]:focus, 
        input[type="email"]:focus, 
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: #2b4c7e;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(43, 76, 126, 0.1);
        }
        
        button[type="submit"] {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 14px 32px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            margin-top: 8px;
        }
        
        button[type="submit"]:hover {
            background: #1e3a5e;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(43, 76, 126, 0.3);
        }
        
        .system-version {
            margin-top: 32px;
            color: #888;
            font-size: 15px;
            text-align: center;
        }
        
        .message {
            background: #e6f4ea;
            color: #217a3c;
            border: 1px solid #b7e0c2;
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 24px;
        }
        
        .error {
            background: #fdeaea;
            color: #b94a48;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 24px;
        }
        
        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 15px;
        }
        .alert-success {
            background: #e6f4ea;
            color: #218838;
        }
        .alert-danger {
            background: #f8d7da;
            color: #842029;
        }
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
        
        /* Main content area adjustments */
        .main {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 60px);
            padding: 48px 40px 32px 40px !important;
            width: 100%;
            max-width: 100%;
        }
        
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                padding: 48px 60px 24px 60px !important;
            }
            .centered-container {
                max-width: 1400px;
                padding: 0 40px;
            }
            .form-container {
                padding: 40px;
            }
            .page-title {
                font-size: 2.25rem;
            }
        }
        
        @media (min-width: 1600px) {
            .main {
                padding: 48px 80px 24px 80px !important;
            }
            .centered-container {
                max-width: 1600px;
                padding: 0 60px;
            }
            .form-container {
                padding: 48px;
            }
            .page-title {
                font-size: 2.5rem;
            }
        }
        
        @media (min-width: 1920px) {
            .main {
                padding: 48px 120px 24px 120px !important;
            }
            .centered-container {
                max-width: 1800px;
                padding: 0 80px;
            }
            .form-container {
                padding: 56px;
            }
            .page-title {
                font-size: 2.75rem;
            }
        }
        
        @media (max-width: 1200px) {
            .main {
                padding: 40px 32px 24px 32px !important;
            }
        }
        
        @media (max-width: 1100px) {
            .main { 
                padding: 24px 20px !important; 
            }
            .centered-container {
                max-width: calc(100% - 40px);
                padding: 0 20px;
            }
        }
        
        @media (max-width: 768px) {
            .main { 
                padding: 20px 16px !important; 
                margin-left: 0 !important;
            }
            .centered-container {
                max-width: calc(100% - 32px);
                padding: 0 16px;
            }
            .form-container {
                padding: 24px;
            }
            .tree-navigation {
                flex-direction: column;
            }
            .tree-nav-item {
                border-bottom: 1px solid #e9ecef;
                border-right: none;
                padding: 14px 20px;
            }
            .tree-nav-item.active {
                border-bottom-color: #2b4c7e;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .header-actions {
                flex-wrap: wrap;
            }
        }
        
        @media (max-width: 700px) {
            .main { 
                padding: 16px 12px !important; 
            }
            .centered-container {
                max-width: calc(100% - 24px);
                padding: 0 12px;
            }
            .form-container {
                padding: 20px 16px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .page-title {
                font-size: 1.25rem;
            }
        }
        
        @media (max-width: 576px) {
            .main {
                padding: 12px 8px !important;
            }
            .centered-container {
                max-width: calc(100% - 16px);
                padding: 0 8px;
            }
            .form-container {
                padding: 16px 12px;
            }
            .page-title {
                font-size: 1.1rem;
            }
            .tree-nav-item {
                padding: 12px 16px;
                font-size: 14px;
            }
            label {
                font-size: 14px;
            }
            input[type="text"], 
            input[type="email"], 
            input[type="password"],
            select {
                font-size: 14px;
                padding: 10px 14px;
            }
            button[type="submit"] {
                padding: 12px 24px;
                font-size: 15px;
            }
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .checkbox-group {
            margin-top: 8px;
        }
        
        .form-check {
            margin-bottom: 12px;
        }
        
        .form-check-input {
            margin-right: 8px;
        }
        
        .form-check-label {
            font-size: 15px;
            color: #333;
        }

        /* UI Settings Styles */
        body {
            transition: all 0.3s ease;
        }

        /* Font Size Classes */
        body.font-small {
            font-size: 14px;
        }
        body.font-medium {
            font-size: 16px;
        }
        body.font-large {
            font-size: 18px;
        }
        body.font-xlarge {
            font-size: 20px;
        }

        /* High Contrast Mode */
        body.high-contrast {
            background: #000 !important;
            color: #fff !important;
        }
        body.high-contrast .settings-section,
        body.high-contrast .sidebar,
        body.high-contrast input,
        body.high-contrast select {
            background: #000 !important;
            color: #fff !important;
            border-color: #fff !important;
        }
        body.high-contrast .sidebar a {
            color: #fff !important;
        }
        body.high-contrast .sidebar a:hover,
        body.high-contrast .sidebar a.active {
            background: #333 !important;
        }

        /* Brightness Mode */
        body.brightness {
            filter: brightness(1.2);
        }
    </style>
</head>
<body class="<?php 
    echo 'font-' . $ui_settings['font_size'] . ' ';
    echo $ui_settings['high_contrast'] ? 'high-contrast ' : '';
    echo $ui_settings['brightness'] ? 'brightness' : '';
?>">
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
                <div class="page-title" style="margin: 0; flex: 1; text-align: center;">Settings</div>
                <div class="header-actions" style="flex: 1;">
                </div>
            </div>
            
            <?php if ($message): ?>
                <div id="successNotification" class="notification-bubble success-notification">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div id="errorNotification" class="notification-bubble error-notification">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Navigation Container Box -->
            <div class="navigation-container">
                <div class="tree-navigation">
                    <button class="tree-nav-item active" data-tab="password">Change Admin Password</button>
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
            </div>

            </div>
            
            <div class="system-version">
                <strong>System Version:</strong> <?php echo $system_version; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
<script>
// Tree navigation switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const treeNavItems = document.querySelectorAll('.tree-nav-item');
    const tabContents = document.querySelectorAll('.tab-content');

    treeNavItems.forEach(item => {
        item.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');

            treeNavItems.forEach(navItem => navItem.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            this.classList.add('active');
            document.getElementById('tab-' + targetTab).classList.add('active');
        });
    });
});

// Notification + password toggle handling
document.addEventListener('DOMContentLoaded', function() {
    const successNotification = document.getElementById('successNotification');
    const errorNotification = document.getElementById('errorNotification');

    const showNotification = (notification) => {
        if (!notification) return;
        setTimeout(() => notification.classList.add('show'), 100);
        setTimeout(() => {
            notification.classList.remove('show');
            notification.classList.add('hide');
        }, 4000);
    };

    showNotification(successNotification);
    showNotification(errorNotification);

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