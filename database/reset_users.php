<?php
/**
 * Reset Users Table Script
 * 
 * This script will:
 * 1. Delete all users except the admin user
 * 2. Ensure admin user exists with username: admin, password: 123123
 * 
 * WARNING: This will delete all users except admin!
 */

require_once '../config/database.php';

// Security check - only allow this to run in development or with explicit confirmation
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

if (!$confirm) {
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Users - Confirmation Required</title>
        <!-- Favicon -->
        <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
        <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
        <style>
            body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
            .warning { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .warning strong { color: #856404; }
            .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px; text-decoration: none; border-radius: 5px; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-secondary { background: #6c757d; color: white; }
        </style>
    </head>
    <body>
        <h1>Reset Users Table</h1>
        <div class="warning">
            <strong>WARNING:</strong> This action will delete ALL users except the user with ID:1 (username: admin, password: 123123).
        </div>
        <p>Are you sure you want to proceed?</p>
        <a href="?confirm=yes" class="btn btn-danger">Yes, Reset Users</a>
        <a href="../admin/user_management.php" class="btn btn-secondary">Cancel</a>
    </body>
    </html>
    ');
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Disable foreign key checks temporarily
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
    
    // Check if user with id:1 exists
    $admin_check = mysqli_query($conn, "SELECT user_id FROM users WHERE user_id = 1");
    $admin_exists = mysqli_num_rows($admin_check) > 0;
    
    // Before deleting, update all foreign key references to point to user_id = 1 or set to NULL
    // This prevents foreign key constraint violations
    
    // Update archived_deceased_records: set archived_by to 1 (admin) for records archived by users being deleted
    mysqli_query($conn, "UPDATE archived_deceased_records SET archived_by = 1 WHERE archived_by IS NOT NULL AND archived_by != 1");
    
    // Update reservations: set reserved_by to 1 (admin) for reservations by users being deleted
    mysqli_query($conn, "UPDATE reservations SET reserved_by = 1 WHERE reserved_by IS NOT NULL AND reserved_by != 1");
    
    // Update logs: set user_id to 1 (admin) for logs by users being deleted
    mysqli_query($conn, "UPDATE logs SET user_id = 1 WHERE user_id IS NOT NULL AND user_id != 1");
    
    // events table has ON DELETE SET NULL, so it will handle itself
    // pending_admin_registrations has ON DELETE SET NULL, so it will handle itself
    
    // Now delete all users except the one with id:1
    $delete_query = "DELETE FROM users WHERE user_id != 1";
    mysqli_query($conn, $delete_query);
    $deleted_count = mysqli_affected_rows($conn);
    
    // Re-enable foreign key checks
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    
    // Generate password hash for '123123'
    $admin_password_hash = password_hash('123123', PASSWORD_DEFAULT);
    $admin_password_hash_escaped = mysqli_real_escape_string($conn, $admin_password_hash);
    
    if ($admin_exists) {
        // Update existing user with id:1 to be admin
        $update_query = "UPDATE users 
                        SET username = 'admin',
                            password = '$admin_password_hash_escaped', 
                            full_name = 'Administrator',
                            role = 'admin'
                        WHERE user_id = 1";
        mysqli_query($conn, $update_query);
        $message = "Updated user with ID:1 to admin user.";
    } else {
        // Create admin user with id:1
        // First, reset auto_increment if needed
        mysqli_query($conn, "ALTER TABLE users AUTO_INCREMENT = 1");
        
        $insert_query = "INSERT INTO users (user_id, username, password, full_name, role) 
                        VALUES (1, 'admin', '$admin_password_hash_escaped', 'Administrator', 'admin')";
        mysqli_query($conn, $insert_query);
        $message = "Created admin user with ID:1.";
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Success message
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Users - Success</title>
        <!-- Favicon -->
        <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
        <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
        <style>
            body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
            .success { background: #d4edda; border: 2px solid #28a745; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .info { background: #d1ecf1; border: 2px solid #17a2b8; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px; text-decoration: none; border-radius: 5px; background: #0d6efd; color: white; }
        </style>
    </head>
    <body>
        <h1>Users Table Reset Successfully</h1>
        <div class="success">
            <strong>Success!</strong> ' . $message . '<br>
            Deleted ' . $deleted_count . ' user(s).
        </div>
        <div class="info">
            <strong>Admin Credentials:</strong><br>
            Username: <strong>admin</strong><br>
            Password: <strong>123123</strong>
        </div>
        <a href="../admin/user_management.php" class="btn">Go to User Management</a>
    </body>
    </html>
    ';
    
} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($conn);
    
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Users - Error</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
            .error { background: #f8d7da; border: 2px solid #dc3545; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px; text-decoration: none; border-radius: 5px; background: #6c757d; color: white; }
        </style>
    </head>
    <body>
        <h1>Error Resetting Users</h1>
        <div class="error">
            <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
        </div>
        <a href="../admin/user_management.php" class="btn">Go Back</a>
    </body>
    </html>
    ';
}

mysqli_close($conn);
?>

