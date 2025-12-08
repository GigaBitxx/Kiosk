<?php
/**
 * Database Migration: Add admin_registration_code to settings table
 * 
 * Run this file once to add the admin_registration_code column to your settings table.
 * Access via: http://localhost/CAPSTONE/kiosk/database/migrate_admin_code.php
 */

require_once '../config/database.php';

$success = false;
$error = '';
$messages = [];

// Check if column already exists
$check_query = "SHOW COLUMNS FROM settings LIKE 'admin_registration_code'";
$check_result = mysqli_query($conn, $check_query);

if ($check_result && mysqli_num_rows($check_result) > 0) {
    $messages[] = "✓ Column 'admin_registration_code' already exists in the settings table.";
    $success = true;
} else {
    // Add the column
    $alter_query = "ALTER TABLE `settings` 
                    ADD COLUMN `admin_registration_code` VARCHAR(255) NULL DEFAULT NULL AFTER `brightness`";
    
    if (mysqli_query($conn, $alter_query)) {
        $messages[] = "✓ Successfully added 'admin_registration_code' column to settings table.";
        
        // Set initial default value
        $update_query = "UPDATE `settings` 
                        SET `admin_registration_code` = 'TMMP-ADMIN-2025' 
                        WHERE `id` = 1";
        
        if (mysqli_query($conn, $update_query)) {
            $messages[] = "✓ Set initial admin registration code (please change this in System Settings).";
            $success = true;
        } else {
            $messages[] = "⚠ Column added, but failed to set initial value: " . mysqli_error($conn);
            $success = true; // Column was added, so partial success
        }
    } else {
        $error = "Failed to add column: " . mysqli_error($conn);
        $messages[] = "✗ Error: " . $error;
    }
}

// Verify the column exists
if ($success) {
    $verify_query = "DESCRIBE settings";
    $verify_result = mysqli_query($conn, $verify_query);
    $column_found = false;
    
    if ($verify_result) {
        while ($row = mysqli_fetch_assoc($verify_result)) {
            if ($row['Field'] === 'admin_registration_code') {
                $column_found = true;
                $messages[] = "✓ Verification: Column confirmed in settings table.";
                break;
            }
        }
    }
    
    if (!$column_found && !(mysqli_num_rows($check_result) > 0)) {
        $messages[] = "⚠ Warning: Column may not have been created properly.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - Admin Registration Code</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: #f4f6f9;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2a38;
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
        }
        .message {
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-radius: 8px;
            line-height: 1.6;
        }
        .message.success {
            background: #e6f4ea;
            color: #217a3c;
            border-left: 4px solid #2d5a2d;
        }
        .message.error {
            background: #fdeaea;
            color: #b94a48;
            border-left: 4px solid #c44536;
        }
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #f5a623;
        }
        .next-steps {
            margin-top: 2rem;
            padding: 1.5rem;
            background: #f0f8ff;
            border-left: 4px solid #2b4c7e;
            border-radius: 8px;
        }
        .next-steps h2 {
            color: #2b4c7e;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        .next-steps ol {
            margin-left: 1.5rem;
            line-height: 2;
        }
        .next-steps li {
            margin-bottom: 0.5rem;
        }
        .btn {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 12px 24px;
            background: #2b4c7e;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #1f3659;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Migration: Admin Registration Code</h1>
        
        <?php foreach ($messages as $message): ?>
            <div class="message <?php echo $success ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endforeach; ?>
        
        <?php if ($success): ?>
            <div class="next-steps">
                <h2>Next Steps:</h2>
                <ol>
                    <li>Go to <strong>System Settings</strong> in the admin panel</li>
                    <li>Navigate to the <strong>Admin Registration Code</strong> section</li>
                    <li>Change the default code to a strong, unique value</li>
                    <li>Click <strong>Update Admin Code</strong> to save</li>
                </ol>
                <a href="../admin/settings.php" class="btn">Go to System Settings</a>
            </div>
        <?php else: ?>
            <div class="message error">
                <strong>Migration Failed</strong><br>
                Please check the error message above and ensure:
                <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                    <li>You have proper database permissions</li>
                    <li>The settings table exists</li>
                    <li>You're connected to the correct database</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

