<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../config/password_reset_helper.php';
require_once 'includes/logging.php';

// Removed automatic update of "Head Administrator" - users can now set their own full_name

// Function to renumber all users sequentially starting from 1
function renumber_all_users($conn) {
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
    
    // Delete users with problematic IDs (>= 1000)
    mysqli_query($conn, "DELETE FROM users WHERE user_id >= 1000");
    
    // Get all users ordered by user_id
    $all_users = mysqli_query($conn, "SELECT user_id FROM users ORDER BY user_id ASC");
    $user_list = [];
    while ($row = mysqli_fetch_assoc($all_users)) {
        $user_list[] = $row['user_id'];
    }
    
    // Create mapping: old_id -> new_id (sequential starting from 1)
    $id_mapping = [];
    $new_id = 1;
    foreach ($user_list as $old_id) {
        if ($old_id != $new_id) {
            $id_mapping[$old_id] = $new_id;
        }
        $new_id++;
    }
    
    // If no renumbering needed, just reset auto increment
    if (empty($id_mapping)) {
        $max_id_result = mysqli_query($conn, "SELECT MAX(user_id) AS max_id FROM users");
        $max_id = 1;
        if ($max_id_result && $row = mysqli_fetch_assoc($max_id_result)) {
            $max_id = max(1, (int)$row['max_id'] + 1);
        }
        mysqli_query($conn, "ALTER TABLE users AUTO_INCREMENT = $max_id");
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        return;
    }
    
    // Renumber users using temporary high IDs
    $temp_start = 99990;
    foreach ($id_mapping as $old_id => $new_id) {
        $temp_id = $temp_start + $old_id;
        mysqli_query($conn, "UPDATE users SET user_id = $temp_id WHERE user_id = $old_id");
    }
    
    // Update foreign key references and renumber to final IDs
    $tables_to_update = [
        ['archived_deceased_records', 'archived_by'],
        ['reservations', 'reserved_by'],
        ['logs', 'user_id'],
        ['events', 'created_by'],
        ['pending_admin_registrations', 'approved_by']
    ];
    
    foreach ($id_mapping as $old_id => $new_id) {
        $temp_id = $temp_start + $old_id;
        
        // Update foreign key references
        foreach ($tables_to_update as $table_info) {
            $table_name = $table_info[0];
            $column_name = $table_info[1];
            $check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");
            if (mysqli_num_rows($check_table) > 0) {
                mysqli_query($conn, "UPDATE $table_name SET $column_name = $new_id WHERE $column_name = $temp_id");
            }
        }
        
        // Update session if affected
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $old_id) {
            $_SESSION['user_id'] = $new_id;
        }
        
        // Update user ID
        mysqli_query($conn, "UPDATE users SET user_id = $new_id WHERE user_id = $temp_id");
    }
    
    // Reset AUTO_INCREMENT
    $max_id_result = mysqli_query($conn, "SELECT MAX(user_id) AS max_id FROM users");
    $max_id = 1;
    if ($max_id_result && $row = mysqli_fetch_assoc($max_id_result)) {
        $max_id = max(1, (int)$row['max_id'] + 1);
    }
    mysqli_query($conn, "ALTER TABLE users AUTO_INCREMENT = $max_id");
    
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
}

// Run renumbering on page load (only once per session to avoid repeated execution)
if (!isset($_SESSION['users_renumbered'])) {
    // Check if there are any problematic user IDs (>= 1000) or gaps in sequence
    $check_problematic = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE user_id >= 1000");
    $has_problematic = false;
    if ($check_problematic && $row = mysqli_fetch_assoc($check_problematic)) {
        if ($row['count'] > 0) {
            $has_problematic = true;
        }
    }
    
    // Check for gaps in sequence
    $all_users = mysqli_query($conn, "SELECT user_id FROM users ORDER BY user_id ASC");
    $expected_id = 1;
    $has_gaps = false;
    while ($user = mysqli_fetch_assoc($all_users)) {
        if ($user['user_id'] != $expected_id) {
            $has_gaps = true;
            break;
        }
        $expected_id++;
    }
    
    if ($has_problematic || $has_gaps) {
        renumber_all_users($conn);
    }
    $_SESSION['users_renumbered'] = true;
}

// Handle add user
$message = '';
if (isset($_POST['add_user'])) {
    require_once '../config/security.php';
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'] ?? '';

    // Split name fields: Given Name, Middle Initial, Last Name
    $given_name = isset($_POST['given_name']) ? trim(mysqli_real_escape_string($conn, $_POST['given_name'])) : '';
    $middle_initial = isset($_POST['middle_initial']) ? trim(mysqli_real_escape_string($conn, $_POST['middle_initial'])) : '';
    $last_name = isset($_POST['last_name']) ? trim(mysqli_real_escape_string($conn, $_POST['last_name'])) : '';

    $role = mysqli_real_escape_string($conn, $_POST['role']);
    
    // Validate password - must contain at least 8 characters/digits
    if (strlen($password) < 8) {
        $message = '<div class="alert alert-danger">Password must contain at least 8 characters or digits.</div>';
        log_action('Warning', 'Failed to add user: ' . $username . ' (password too short)', $_SESSION['user_id']);
    }
    // Validate required name parts (Given Name and Last Name)
    else if ($given_name === '' || $last_name === '') {
        $message = '<div class="alert alert-danger">Given Name and Last Name are required.</div>';
        log_action('Warning', 'Failed to add user: ' . $username . ' (name incomplete)', $_SESSION['user_id']);
    }
    // Check if adding admin and limit is reached
    else if ($role === 'admin' && is_admin_limit_reached($conn, 5)) {
        $message = '<div class="alert alert-warning">Cannot add admin: Maximum limit of 5 administrators has been reached. Please remove an existing admin first.</div>';
        log_action('Warning', 'Failed to add admin user: ' . $username . ' (admin limit reached)', $_SESSION['user_id']);
    } else {
        // Check if username already exists
        $check_query = "SELECT user_id FROM users WHERE username = '$username'";
        $check_result = mysqli_query($conn, $check_query);
        if (mysqli_num_rows($check_result) > 0) {
            $message = '<div class="alert alert-danger">Username already exists. Please choose a different username.</div>';
            log_action('Warning', 'Failed to add user: ' . $username . ' (username exists)', $_SESSION['user_id']);
        } else {
            // Build full name from parts
            $name_parts = [];
            if ($given_name !== '') {
                $name_parts[] = $given_name;
            }
            if ($middle_initial !== '') {
                $name_parts[] = $middle_initial;
            }
            if ($last_name !== '') {
                $name_parts[] = $last_name;
            }
            $full_name = mysqli_real_escape_string($conn, implode(' ', $name_parts));

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new user using auto-increment
            $query = "INSERT INTO users (username, password, full_name, role) VALUES ('$username', '$hashed_password', '$full_name', '$role')";
            if (mysqli_query($conn, $query)) {
                // Renumber all users to ensure sequential IDs
                renumber_all_users($conn);
                $_SESSION['success_message'] = 'New User Added.';
                log_action('Info', 'Added user: ' . $username . ' (role: ' . $role . ')', $_SESSION['user_id']);
                header('Location: user_management.php');
                exit();
            } else {
                $message = '<div class="alert alert-danger">Error adding user: ' . mysqli_error($conn) . '</div>';
                log_action('Warning', 'Failed to add user: ' . $username, $_SESSION['user_id']);
            }
        }
    }
}
// Handle delete user
if (isset($_GET['delete']) && $_GET['delete'] != $_SESSION['user_id']) {
    $delete_id = intval($_GET['delete']);
    
    // Disable foreign key checks temporarily
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
    
    // Find the next user with higher ID to renumber down
    $next_user = mysqli_query($conn, "SELECT user_id FROM users WHERE user_id > $delete_id ORDER BY user_id ASC LIMIT 1");
    $next_user_id = null;
    if ($next_user && $row = mysqli_fetch_assoc($next_user)) {
        $next_user_id = $row['user_id'];
    }
    
    // Find first admin user to reassign foreign keys to, or first user if no admin
    $reassign_to = mysqli_query($conn, "SELECT user_id FROM users WHERE role = 'admin' AND user_id != $delete_id ORDER BY user_id ASC LIMIT 1");
    $reassign_id = null;
    if ($reassign_to && $row = mysqli_fetch_assoc($reassign_to)) {
        $reassign_id = $row['user_id'];
    } else {
        // If no admin found, use first user
        $first_user = mysqli_query($conn, "SELECT user_id FROM users WHERE user_id != $delete_id ORDER BY user_id ASC LIMIT 1");
        if ($first_user && $row = mysqli_fetch_assoc($first_user)) {
            $reassign_id = $row['user_id'];
        }
    }
    
    // Before deleting, update all foreign key references
    // Check if tables exist before updating
    $tables_to_update = [
        ['archived_deceased_records', 'archived_by'],
        ['reservations', 'reserved_by'],
        ['logs', 'user_id'],
        ['events', 'created_by'],
        ['pending_admin_registrations', 'approved_by']
    ];
    
    foreach ($tables_to_update as $table_info) {
        $table_name = $table_info[0];
        $column_name = $table_info[1];
        $check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");
        if (mysqli_num_rows($check_table) > 0 && $reassign_id) {
            mysqli_query($conn, "UPDATE $table_name SET $column_name = $reassign_id WHERE $column_name = $delete_id");
        }
    }
    
    // Now delete the user
    mysqli_query($conn, "DELETE FROM users WHERE user_id = $delete_id");
    
    // Re-enable foreign key checks
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    
    // Log the deletion BEFORE renumbering (to ensure user_id is still valid)
    if (function_exists('log_action')) {
        $log_result = log_action('Alert', 'Deleted user ID: ' . $delete_id, $_SESSION['user_id']);
        if (!$log_result) {
            // If logging fails, try again with a direct query as fallback
            $level = mysqli_real_escape_string($conn, 'Alert');
            $message = mysqli_real_escape_string($conn, 'Deleted user ID: ' . $delete_id);
            $user_id = intval($_SESSION['user_id']);
            $fallback_sql = "INSERT INTO logs (level, message, user_id) VALUES ('$level', '$message', $user_id)";
            mysqli_query($conn, $fallback_sql);
        }
    } else {
        // Direct insert if function doesn't exist
        $level = mysqli_real_escape_string($conn, 'Alert');
        $message = mysqli_real_escape_string($conn, 'Deleted user ID: ' . $delete_id);
        $user_id = intval($_SESSION['user_id']);
        $direct_sql = "INSERT INTO logs (level, message, user_id) VALUES ('$level', '$message', $user_id)";
        mysqli_query($conn, $direct_sql);
    }
    
    // Renumber all users to fill gaps and ensure sequential IDs
    renumber_all_users($conn);
    
    $_SESSION['success_message'] = 'Account Deleted Successfully!';
    header('Location: user_management.php');
    exit();
}

// Handle update user
if (isset($_POST['update_user'])) {
    $update_id = intval($_POST['user_id']);
    $new_username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $new_full_name = mysqli_real_escape_string($conn, trim($_POST['full_name']));
    
    // Validate inputs
    if (empty($new_username)) {
        $message = '<div class="alert alert-danger">Username cannot be empty.</div>';
        log_action('Warning', 'Failed to update user ID: ' . $update_id . ' (empty username)', $_SESSION['user_id']);
    } else if (empty($new_full_name)) {
        $message = '<div class="alert alert-danger">Full name cannot be empty.</div>';
        log_action('Warning', 'Failed to update user ID: ' . $update_id . ' (empty full name)', $_SESSION['user_id']);
    } else {
        // Check if username already exists for another user
        $check_query = "SELECT user_id FROM users WHERE username = '$new_username' AND user_id != $update_id";
        $check_result = mysqli_query($conn, $check_query);
        if (mysqli_num_rows($check_result) > 0) {
            $message = '<div class="alert alert-danger">Username already exists. Please choose a different username.</div>';
            log_action('Warning', 'Failed to update user ID: ' . $update_id . ' (username exists)', $_SESSION['user_id']);
        } else {
            // Update user
            $update_query = "UPDATE users SET username = '$new_username', full_name = '$new_full_name' WHERE user_id = $update_id";
            if (mysqli_query($conn, $update_query)) {
                $_SESSION['success_message'] = 'User Details Updated';
                log_action('Info', 'Updated user ID: ' . $update_id . ' (username: ' . $new_username . ')', $_SESSION['user_id']);
                header('Location: user_management.php');
                exit();
            } else {
                $message = '<div class="alert alert-danger">Error updating user: ' . mysqli_error($conn) . '</div>';
                log_action('Warning', 'Failed to update user ID: ' . $update_id, $_SESSION['user_id']);
}
        }
    }
}

// Get all users
$users = mysqli_query($conn, "SELECT * FROM users ORDER BY user_id ASC");
$pending_reset_requests = get_pending_password_reset_requests($conn);
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
        .main {
            padding: 48px 40px 32px 40px !important;
            width: 100%;
            max-width: 100%;
        }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            gap: 16px;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: 1px;
            margin: 0;
        }
        .notification-button {
            position: relative;
            border: none;
            background: rgba(43, 76, 126, 0.1);
            color: #2b4c7e;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .notification-button:hover {
            background: rgba(43, 76, 126, 0.15);
            transform: translateY(-1px);
        }
        .notification-button:focus {
            outline: 2px solid rgba(43, 76, 126, 0.4);
            outline-offset: 2px;
        }
        .notification-dot {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dc3545;
        }
        .notification-wrapper {
            position: relative;
        }
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 340px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.15);
            padding: 16px;
            display: none;
            z-index: 200;
        }
        .notification-dropdown.show {
            display: block;
        }
        /* Keep notification button and dropdown below modals when any modal is open */
        body.modal-open .notification-wrapper,
        body.edit-modal-open .notification-wrapper {
            z-index: 0;
            position: relative;
        }
        body.modal-open .notification-dropdown,
        body.edit-modal-open .notification-dropdown {
            z-index: 0;
        }
        .notification-item {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 12px;
            margin-bottom: 12px;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .notification-item.fade-out {
            opacity: 0;
            transform: translateY(-6px);
        }
        .notification-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .notification-title {
            font-weight: 600;
            color: #1d2a38;
            margin-bottom: 4px;
        }
        .notification-reason,
        .notification-status,
        .notification-meta {
            font-size: 0.9rem;
            color: #5b6c86;
            margin-bottom: 4px;
        }
        .notification-meta {
            font-size: 0.8rem;
        }
        .btn-reset-request {
            margin-top: 8px;
            width: 100%;
            border: none;
            background: #2b4c7e;
            color: #fff;
            padding: 10px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-reset-request:hover {
            background: #1f3659;
        }
        .btn-reset-request:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .notification-empty {
            text-align: center;
            color: #5b6c86;
            font-size: 0.9rem;
        }
        .temp-password-banner {
            background: #e6f4ea;
            border: 1px solid #badbcc;
            border-radius: 12px;
            padding: 12px;
            color: #0f5132;
            font-size: 0.95rem;
        }
        .temp-password-banner strong {
            display: block;
            font-size: 1.05rem;
            margin-top: 4px;
        }
        .temp-password-item {
            background: #f5fbf7;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid #cfead8;
        }
        .temp-password-item .notification-title {
            margin-bottom: 6px;
        }
        .temp-password-item .notification-meta {
            margin-top: 8px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            border: 1px solid #e0e0e0;
            max-width: 100%;
            width: 100%;
        }
        .card-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
        }
        .form-row {
            display: flex;
            gap: 18px;
            margin-bottom: 0;
            align-items: center;
            width: 100%;
        }
        .form-row input, .form-row select {
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: #fafafa;
            outline: none;
            flex: 1;
            min-width: 0;
        }
        .form-row input[type="text"], .form-row input[type="password"] {
            min-width: 120px;
        }
        .form-row input[disabled] {
            background: #e9ecef;
            cursor: not-allowed;
            color: #6c757d;
        }
        .form-row input:invalid:not(:placeholder-shown) {
            border-color: #dc3545;
        }
        .form-row input:valid:not(:placeholder-shown) {
            border-color: #198754;
        }
        .form-row select { 
            min-width: 100px;
        }
        .form-row button {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .form-row button:hover { background: #1e3a5e; }
        .password-wrapper {
            position: relative;
            flex: 1;
            min-width: 10px;
        }
        .password-wrapper input {
            width: 100%;
            padding-right: 45px;
        }
        .basic-info-card {
            display: none;
        }
        .basic-info-card.show {
            display: block;
        }
        .table-responsive { 
            width: 100%; 
            overflow-x: auto;
            max-width: 100%;
        }

        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #5b6c86;
            font-size: 1.2rem;
            transition: color 0.3s ease;
            z-index: 10;
            background: transparent !important;
            border: none !important;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none !important;
        }
        .password-toggle:hover {
            color: #2b4c7e;
            background: transparent !important;
        }
        .password-toggle:focus {
            outline: none;
            color: #2b4c7e;
            background: transparent !important;
        }
        .password-toggle:active {
            background: transparent !important;
        }
    
        table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            table-layout: auto;
        }
        th, td {
            padding: 14px 18px;
            text-align: center;
            font-size: 15px;
        }
        th { background: #fafafa; color: #333; border-bottom: 1px solid #e0e0e0; }
        tr { background: #fff; }
        tr:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
        tr.current-user-row {
            background: #e3f2fd !important;
            border-left: 3px solid #2b4c7e;
        }
        tr.current-user-row td {
            font-weight: 500;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 13px;
            color: #fff;
        }
        .badge.admin { background: #2b4c7e; }
        .badge.staff { background: #f5a623; }
        .btn-danger {
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0;
        }
        .btn-danger:hover { background: #b52a37; }
        .btn-danger i {
            font-size: 14px;
        }
        .btn-edit {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }
        .btn-edit:hover { 
            background: #1f3659; 
        }
        .btn-edit i {
            font-size: 0.875rem;
        }
        .btn-danger {
            background: #ef4444;
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        .btn-danger i {
            font-size: 0.875rem;
        }
        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        th:last-child, td:last-child {
            text-align: center;
        }
        /* Custom modal styles for edit user modal */
        #editUserModal.modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        #editUserModal.modal.show {
            display: block;
        }
        
        /* Bootstrap 5 modal overrides */
        .modal.fade {
            display: none;
        }
        .modal.fade.show {
            display: block;
        }
        .modal-backdrop {
            z-index: 1050;
        }
        .modal-dialog {
            z-index: 1056;
            position: relative;
            pointer-events: auto;
        }
        .modal-content {
            z-index: 1056;
            position: relative;
            pointer-events: auto;
        }
        .modal-body button,
        .modal-body a.btn {
            pointer-events: auto !important;
            cursor: pointer !important;
            position: relative;
            z-index: 1057;
        }
        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 32px;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #222;
        }
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        .close:hover {
            color: #000;
        }
        .modal-body {
            margin-bottom: 24px;
        }
        .modal-body .form-group {
            margin-bottom: 20px;
        }
        .modal-body label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .modal-body input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: #fafafa;
            outline: none;
            box-sizing: border-box;
        }
        .modal-body input:focus {
            border-color: #2b4c7e;
            background: #fff;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .modal-footer button {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-cancel {
            background: #6c757d;
            color: #fff;
        }
        .btn-cancel:hover {
            background: #5a6268;
        }
        .btn-save {
            background: #2b4c7e;
            color: #fff;
        }
        .btn-save:hover {
            background: #1e3a5e;
        }
        .text-muted { color: #888; font-size: 14px; }
        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 15px;
        }
        .alert-success { background: #e6f4ea; color: #218838; }
        .alert-danger { background: #f8d7da; color: #842029; }
        .alert-warning { background: #fff3cd; color: #856404; }
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
        .form-row select {
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: #fafafa;
            outline: none;
            flex: 1;
            min-width: 100px;
            cursor: pointer;
        }
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                padding: 48px 60px 32px 60px !important;
            }
            .card {
                padding: 40px 32px 32px 32px;
            }
            table {
                font-size: 16px;
            }
            th, td {
                padding: 16px 20px;
            }
        }
        
        @media (min-width: 1600px) {
            .main {
                padding: 48px 80px 32px 80px !important;
            }
            .card {
                padding: 48px 40px 40px 40px;
            }
            .page-title {
                font-size: 2.25rem;
            }
            .card-title {
                font-size: 20px;
            }
        }
        
        @media (min-width: 1920px) {
            .main {
                padding: 48px 120px 32px 120px !important;
            }
            .card {
                padding: 56px 48px 48px 48px;
            }
            .page-title {
                font-size: 2.5rem;
            }
            .card-title {
                font-size: 22px;
            }
            table {
                font-size: 17px;
            }
            th, td {
                padding: 18px 24px;
            }
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .notification-dropdown {
                width: 300px;
            }
            .table-container {
                overflow-x: auto;
            }
        }
        
        @media (max-width: 1100px) {
            .main { 
                padding: 24px 20px !important; 
            }
            .page-header {
                flex-wrap: wrap;
            }
            .page-title {
                font-size: 1.75rem;
            }
            .notification-dropdown {
                width: 280px;
                right: -20px;
            }
        }
        
        @media (max-width: 900px) {
            .form-row {
                gap: 12px;
            }
            .form-row input[type="text"], .form-row input[type="password"] {
                min-width: 100px;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .card {
                padding: 20px;
            }
            .table {
                font-size: 14px;
            }
            th, td {
                padding: 10px 8px;
            }
            .notification-dropdown {
                width: calc(100vw - 40px);
                max-width: 320px;
                right: 0;
                left: auto;
            }
        }
        
        @media (max-width: 768px) {
            .main { 
                padding: 20px 16px !important; 
                margin-left: 0 !important;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .page-title {
                font-size: 1.5rem;
                width: 100%;
            }
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            .form-row { 
                flex-direction: column; 
                gap: 12px; 
                align-items: stretch;
            }
            .form-row input, .form-row select, .form-row button {
                flex: none;
                width: 100%;
                min-width: 0;
            }
            .table-responsive {
                -webkit-overflow-scrolling: touch;
            }
            .table {
                font-size: 13px;
                min-width: 600px;
            }
            th, td {
                padding: 8px 6px;
                white-space: nowrap;
            }
            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }
            .action-buttons button {
                width: 100%;
            }
            .card {
                padding: 16px;
            }
            .modal-content {
                width: 95%;
                max-width: 95%;
                margin: 20px auto;
            }
            .notification-dropdown {
                width: calc(100vw - 32px);
                max-width: none;
                right: 16px;
                left: 16px;
            }
        }
        
        @media (max-width: 576px) {
            .main { 
                padding: 16px 12px !important; 
            }
            .page-title {
                font-size: 1.25rem;
            }
            .table {
                font-size: 12px;
                min-width: 500px;
            }
            th, td {
                padding: 6px 4px;
            }
            .card {
                padding: 12px;
            }
            .form-group {
                margin-bottom: 16px;
            }
            .btn {
                padding: 10px 16px;
                font-size: 14px;
            }
            .modal-content {
                width: 98%;
                max-width: 98%;
                padding: 20px 16px;
            }
            .notification-button {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
        }
        
        @media (max-width: 400px) {
            .main { 
                padding: 12px 8px !important; 
            }
            .page-title {
                font-size: 1.1rem;
            }
            .table {
                font-size: 11px;
                min-width: 450px;
            }
            .card {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-header">
        <div class="page-title">User Management</div>
            <div class="notification-wrapper">
                <button class="notification-button" type="button" aria-label="Notifications" id="notificationToggle">
                    <i class="bi bi-bell"></i>
                    <?php if (!empty($pending_reset_requests)): ?>
                        <span class="notification-dot" title="New notifications"></span>
                    <?php endif; ?>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <?php if (!empty($pending_reset_requests)): ?>
                        <?php foreach ($pending_reset_requests as $request): ?>
                            <div class="notification-item" data-request-id="<?php echo $request['id']; ?>">
                                <p class="notification-title">⚠️ New password reset request from: <strong><?php echo htmlspecialchars($request['username']); ?></strong></p>
                                <p class="notification-reason">Reason: <?php echo !empty($request['reason']) ? htmlspecialchars($request['reason']) : 'No reason provided.'; ?></p>
                                <p class="notification-status">Status: <?php echo ucfirst($request['status']); ?></p>
                                <p class="notification-meta">Received: <?php echo date('M d, Y g:i A', strtotime($request['created_at'])); ?></p>
                                <button type="button"
                                        class="btn-reset-request"
                                        data-request-id="<?php echo $request['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($request['username'], ENT_QUOTES); ?>">
                                    <i class="bi bi-shield-lock"></i>
                                    Reset Password
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-empty">No pending password reset requests.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (isset($_GET['cleaned'])): ?>
            <div class="alert alert-success">User IDs have been cleaned up and renumbered successfully.</div>
        <?php endif; ?>
        <?php 
        // Check for success message from session
        if (isset($_SESSION['success_message'])) {
            $success_msg = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
            echo '<div id="successNotification" class="notification-bubble success-notification" data-message="' . htmlspecialchars($success_msg) . '">';
            echo '<i class="bi bi-check-circle-fill"></i>';
            echo '<span>' . htmlspecialchars($success_msg) . '</span>';
            echo '</div>';
        }
        ?>
        <?php echo $message; ?>
        <form method="POST" id="addUserForm" onsubmit="return validateAndSubmit()">
            <div class="card">
                <div class="card-title">Add New User</div>
                <div class="form-row">
                    <input type="text" name="username" id="username" placeholder="Username *" required>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Password *" required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class='bx bx-hide' id="passwordToggleIcon"></i>
                        </button>
                    </div>
                        <input type="text" value="Staff" disabled style="background: #e9ecef; cursor: not-allowed;">
                    <input type="hidden" name="role" id="role" value="staff">
                    <button type="button" id="addBtn" onclick="showBasicInfo()">Add</button>
                </div>
            </div>
            <div class="card basic-info-card" id="basicInfoCard">
                <div class="card-title">Basic Information</div>
                <div class="form-row">
                    <input type="text" name="given_name" id="given_name" placeholder="First Name *" required>
                    <input type="text" name="middle_initial" id="middle_initial" placeholder="M.I." maxlength="1">
                    <input type="text" name="last_name" id="last_name" placeholder="Last Name *" required>
                    <button type="submit" name="add_user" id="saveBtn">Save</button>
                </div>
            </div>
        </form>
        <div class="card">
            <div class="card-title">All Users</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = mysqli_fetch_assoc($users)): ?>
                            <tr class="<?php echo $user['user_id'] == $_SESSION['user_id'] ? 'current-user-row' : ''; ?>">
                                <td><?php echo $user['user_id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td>
                                        <span class="badge <?php echo $user['role'] === 'admin' ? 'admin' : 'staff'; ?>"><?php echo ucfirst($user['role']); ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn-edit" 
                                                data-user-id="<?php echo $user['user_id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>"
                                                data-full-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>"
                                                onclick="window.openEditModal(this.getAttribute('data-user-id'), this.getAttribute('data-username'), this.getAttribute('data-full-name'))"
                                                title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <button type="button" class="btn-danger" onclick="showDeleteUserModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>');" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Edit User</h3>
            <span class="close" onclick="window.closeEditModal()">&times;</span>
        </div>
        <form method="POST" id="editUserForm">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_username">Username *</label>
                    <input type="text" name="username" id="edit_username" required>
                </div>
                <div class="form-group">
                    <label for="edit_full_name">Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="window.closeEditModal()">Cancel</button>
                <button type="submit" name="update_user" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function showBasicInfo() {
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const basicInfoCard = document.getElementById('basicInfoCard');
    
    // Clear previous validation
    usernameInput.setCustomValidity('');
    passwordInput.setCustomValidity('');
    
    // Validate using HTML5 validation
    if (!usernameInput.checkValidity()) {
        usernameInput.reportValidity();
        usernameInput.focus();
        return;
    }
    
    if (!passwordInput.checkValidity()) {
        if (passwordInput.value.length < 8) {
            passwordInput.setCustomValidity('Password must contain at least 8 characters or digits.');
        }
        passwordInput.reportValidity();
        passwordInput.focus();
        return;
    }
    
    const username = usernameInput.value.trim();
    
    // Check if username already exists
    checkUsernameExists(username, function(exists) {
        if (exists) {
            usernameInput.setCustomValidity('Username already exists. Please choose a different username.');
            usernameInput.reportValidity();
            usernameInput.focus();
            return;
        }
        
        // Show Basic Information section
        basicInfoCard.classList.add('show');
        document.getElementById('given_name').focus();
    });
}

function checkUsernameExists(username, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'check_username.php?username=' + encodeURIComponent(username), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    callback(response.exists || false);
                } catch (e) {
                    // If check_username.php doesn't exist, we'll validate on server side
                    callback(false);
                }
            } else {
                callback(false);
            }
        }
    };
    xhr.send();
}

function validateAndSubmit() {
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const givenNameInput = document.getElementById('given_name');
    const lastNameInput = document.getElementById('last_name');
    
    // Clear previous validation
    usernameInput.setCustomValidity('');
    passwordInput.setCustomValidity('');
    givenNameInput.setCustomValidity('');
    lastNameInput.setCustomValidity('');
    
    // Validate username
    if (!usernameInput.checkValidity()) {
        usernameInput.reportValidity();
        usernameInput.focus();
        return false;
    }
    
    // Validate password length
    if (passwordInput.value.length < 8) {
        passwordInput.setCustomValidity('Password must contain at least 8 characters or digits.');
        passwordInput.reportValidity();
        passwordInput.focus();
        return false;
    }
    
    // Validate required name parts
    if (!givenNameInput.checkValidity()) {
        givenNameInput.reportValidity();
        givenNameInput.focus();
        return false;
    }
    
    if (!lastNameInput.checkValidity()) {
        lastNameInput.reportValidity();
        lastNameInput.focus();
        return false;
    }
    
    return true;
}

function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('passwordToggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('bx-hide');
        toggleIcon.classList.add('bx-show');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('bx-show');
        toggleIcon.classList.add('bx-hide');
    }
}

// Reset form when page loads or after successful submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addUserForm');
    const basicInfoCard = document.getElementById('basicInfoCard');
    const notificationToggle = document.getElementById('notificationToggle');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    // Clear custom validity messages when user types
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    
    if (usernameInput) {
        usernameInput.addEventListener('input', function() {
            this.setCustomValidity('');
        });
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            this.setCustomValidity('');
        });
    }
    
    // Check if there's a success message (user was added)
    const successAlert = document.querySelector('.alert-success');
    if (successAlert && successAlert.textContent.includes('successfully')) {
        // Reset form and hide Basic Information
        if (form) {
            form.reset();
        }
        if (basicInfoCard) {
            basicInfoCard.classList.remove('show');
        }
    }
    
    // Auto-hide all success messages after 3 seconds
    const allSuccessAlerts = document.querySelectorAll('.alert-success');
    allSuccessAlerts.forEach(function(alert) {
        if (alert.textContent.includes('successfully')) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s ease-out';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            }, 3000);
        }
    });
    
    // Reset password toggle icon on form reset
    if (form) {
        form.addEventListener('reset', function() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            if (passwordInput && toggleIcon) {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('bx-show');
                toggleIcon.classList.add('bx-hide');
            }
        });
    }

    const toggleNotificationDropdown = (show) => {
        if (!notificationDropdown) return;
        if (show === false) {
            notificationDropdown.classList.remove('show');
        } else {
            notificationDropdown.classList.toggle('show');
        }
    };

    if (notificationToggle && notificationDropdown) {
        notificationToggle.addEventListener('click', function(event) {
            event.stopPropagation();
            toggleNotificationDropdown();
        });
        document.addEventListener('click', function(event) {
            if (!notificationDropdown.contains(event.target) && !notificationToggle.contains(event.target)) {
                toggleNotificationDropdown(false);
            }
        });
    }

    const updateNotificationState = () => {
        const remainingItems = notificationDropdown ? notificationDropdown.querySelectorAll('.notification-item[data-request-id]') : [];
        const tempPasswordItems = notificationDropdown ? notificationDropdown.querySelectorAll('.temp-password-item') : [];
        const dot = notificationToggle ? notificationToggle.querySelector('.notification-dot') : null;
        if (dot && remainingItems.length === 0) {
            dot.remove();
        }
        // Only show empty message if there are no pending requests AND no temp password notifications
        if (notificationDropdown && remainingItems.length === 0 && tempPasswordItems.length === 0) {
            notificationDropdown.innerHTML = '<div class="notification-empty">No pending password reset requests.</div>';
        }
    };

    const handleResetButtons = () => {
        const buttons = document.querySelectorAll('.btn-reset-request');
        buttons.forEach((btn) => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', async function() {
                const requestId = this.getAttribute('data-request-id');
                const username = this.getAttribute('data-username');
                const parentItem = this.closest('.notification-item');
                if (!requestId || !parentItem) return;

                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';

                const formData = new FormData();
                formData.append('request_id', requestId);

                try {
                    const response = await fetch('reset_password_request.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Unable to reset password.');
                    }
                    
                    // Check if temporary_password exists in response
                    if (!data.temporary_password) {
                        console.error('Temporary password not found in response:', data);
                        throw new Error('Temporary password was not generated. Please try again.');
                    }
                    
                    const dropdown = notificationDropdown;
                    if (!dropdown) {
                        console.error('Notification dropdown not found');
                        alert('Temporary Password: ' + data.temporary_password);
                        return;
                    }
                    
                    // Remove empty message if it exists
                    const emptyMessage = dropdown.querySelector('.notification-empty');
                    if (emptyMessage) {
                        emptyMessage.remove();
                    }
                    
                    const tempNotice = document.createElement('div');
                    tempNotice.className = 'notification-item temp-password-item';
                    tempNotice.innerHTML = `
                        <p class="notification-title">Temporary Password for <strong>${username}</strong>:</p>
                        <div class="temp-password-banner">
                            <strong>${data.temporary_password}</strong>
                        </div>
                        <p class="notification-meta">Provide this code securely and advise immediate password change.</p>
                    `;
                    dropdown.prepend(tempNotice);
                    
                    // Automatically open the notification dropdown to show the temporary password
                    dropdown.classList.add('show');
                    
                    // Remove the notification after 5 minutes
                    setTimeout(() => {
                        tempNotice.classList.add('fade-out');
                        setTimeout(() => {
                            tempNotice.remove();
                            // Check if dropdown is now empty and show empty message
                            const remainingItems = dropdown.querySelectorAll('.notification-item[data-request-id]');
                            const remainingTempItems = dropdown.querySelectorAll('.temp-password-item');
                            if (remainingItems.length === 0 && remainingTempItems.length === 0) {
                                dropdown.innerHTML = '<div class="notification-empty">No pending password reset requests.</div>';
                            }
                        }, 400);
                    }, 5 * 60 * 1000);
                    parentItem.remove();
                    updateNotificationState();
                } catch (error) {
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-shield-lock"></i> Reset Password';
                    alert(error.message || 'Unable to reset password.');
                }
            });
        });
    };

    handleResetButtons();
});

// Edit User Modal Functions - Make sure these are globally accessible
window.openEditModal = function(userId, username, fullName) {
    try {
        const modal = document.getElementById('editUserModal');
        if (!modal) {
            console.error('Edit modal not found');
            alert('Error: Edit modal not found. Please refresh the page.');
            return;
        }
        
        const userIdInput = document.getElementById('edit_user_id');
        const usernameInput = document.getElementById('edit_username');
        const fullNameInput = document.getElementById('edit_full_name');
        
        if (!userIdInput || !usernameInput || !fullNameInput) {
            console.error('Modal inputs not found');
            alert('Error: Modal form elements not found. Please refresh the page.');
            return;
        }
        
        userIdInput.value = userId || '';
        usernameInput.value = username || '';
        fullNameInput.value = fullName || '';
        document.body.classList.add('edit-modal-open');
        modal.style.display = 'block';
    } catch (error) {
        console.error('Error opening edit modal:', error);
        alert('Error opening edit form. Please refresh the page.');
    }
};

window.closeEditModal = function() {
    try {
        const modal = document.getElementById('editUserModal');
        if (modal) {
            document.body.classList.remove('edit-modal-open');
            modal.style.display = 'none';
            // Reset form
            const form = document.getElementById('editUserForm');
            if (form) {
                form.reset();
            }
        }
    } catch (error) {
        console.error('Error closing edit modal:', error);
    }
};

// Close modal when clicking outside of it
document.addEventListener('click', function(event) {
    const modal = document.getElementById('editUserModal');
    if (modal && event.target == modal) {
        window.closeEditModal();
    }
});

// Setup username validation on edit modal after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const editUsernameInput = document.getElementById('edit_username');
    if (editUsernameInput) {
        editUsernameInput.addEventListener('blur', function() {
            const username = this.value.trim();
            const userIdInput = document.getElementById('edit_user_id');
            const userId = userIdInput ? userIdInput.value : '';
            
            if (username && userId) {
                const xhr = new XMLHttpRequest();
                xhr.open('GET', 'check_username.php?username=' + encodeURIComponent(username) + '&exclude_id=' + userId, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            const input = document.getElementById('edit_username');
                            if (input && response.exists) {
                                input.setCustomValidity('Username already exists. Please choose a different username.');
                                input.reportValidity();
                            } else if (input) {
                                input.setCustomValidity('');
                            }
                        } catch (e) {
                            // If check fails, let server-side validation handle it
                            console.error('Error checking username:', e);
                        }
                    }
                };
                xhr.send();
            }
        });
    }
    
    // Add backup event listeners to edit buttons (in addition to onclick)
    const editButtons = document.querySelectorAll('.btn-edit');
    editButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            // Only use this if onclick didn't work (prevent double execution)
            if (!e.defaultPrevented) {
                const userId = this.getAttribute('data-user-id');
                const username = this.getAttribute('data-username');
                const fullName = this.getAttribute('data-full-name');
                
                if (userId && username !== null && fullName !== null) {
                    window.openEditModal(userId, username, fullName);
                }
            }
        });
    });
    
    // Debug: Test if openEditModal is accessible
    console.log('openEditModal function available:', typeof window.openEditModal);
    
    // Show success notification if present
    const successNotification = document.getElementById('successNotification');
    if (successNotification) {
        setTimeout(() => {
            successNotification.classList.add('show');
        }, 100);
        setTimeout(() => {
            successNotification.classList.remove('show');
            successNotification.classList.add('hide');
            setTimeout(() => {
                successNotification.remove();
            }, 300);
        }, 4000);
    }
});

// Show delete user confirmation modal
function showDeleteUserModal(userId, username) {
    // Remove any existing modal first
    const existingModal = document.getElementById('deleteUserModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const modalEl = document.createElement('div');
    modalEl.className = 'modal fade';
    modalEl.id = 'deleteUserModal';
    modalEl.setAttribute('tabindex', '-1');
    modalEl.setAttribute('aria-labelledby', 'deleteUserModalLabel');
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.style.zIndex = '1055'; // Higher than Bootstrap's default

    modalEl.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" style="z-index: 1056;">
            <div class="modal-content" style="z-index: 1056;">
                <div class="modal-body" style="padding: 24px;">
                    <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 10px;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class='bx bx-trash' style="font-size: 24px; color: #dc3545;"></i>
                        </div>
                        <div style="flex: 1;">
                            <p style="margin: 0; font-size: 16px; color: #212529; font-weight: 500;">Delete this user?</p>
                            <p style="margin: 8px 0 0 0; font-size: 14px; color: #6c757d;">This action cannot be undone.</p>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px; cursor: pointer; z-index: 1057; position: relative;">Cancel</button>
                        <a href="?delete=${userId}" class="btn btn-danger" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px; text-decoration: none; background-color: #dc3545; border-color: #dc3545; color: #fff; cursor: pointer; z-index: 1057; position: relative;">Delete</a>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(modalEl);
    
    // Wait for DOM to be ready, then initialize modal
    setTimeout(() => {
        const modal = new bootstrap.Modal(modalEl, {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();

        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
        });
    }, 10);
}

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
</body>
</html> 