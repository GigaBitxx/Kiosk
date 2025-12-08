<?php
/**
 * Database Migration: Add plot price columns to settings table
 * 
 * Run this file once to add the niche_price, lawn_price, and mausoleum_price columns 
 * to your settings table.
 * Access via: http://localhost/ca/kiosk/database/migrate_plot_prices.php
 */

require_once '../config/database.php';

$success = false;
$error = '';
$messages = [];

// Check if columns already exist
$check_niche = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'niche_price'");
$check_lawn = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'lawn_price'");
$check_mausoleum = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'mausoleum_price'");

$niche_exists = $check_niche && mysqli_num_rows($check_niche) > 0;
$lawn_exists = $check_lawn && mysqli_num_rows($check_lawn) > 0;
$mausoleum_exists = $check_mausoleum && mysqli_num_rows($check_mausoleum) > 0;

if ($niche_exists && $lawn_exists && $mausoleum_exists) {
    $messages[] = "✓ All plot price columns already exist in the settings table.";
    $success = true;
} else {
    // Start transaction for atomicity
    mysqli_begin_transaction($conn);
    
    try {
        // Add niche_price column if it doesn't exist
        if (!$niche_exists) {
            $alter_query = "ALTER TABLE `settings` 
                           ADD COLUMN `niche_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `brightness`";
            
            if (mysqli_query($conn, $alter_query)) {
                $messages[] = "✓ Successfully added 'niche_price' column to settings table.";
            } else {
                throw new Exception("Failed to add niche_price column: " . mysqli_error($conn));
            }
        } else {
            $messages[] = "✓ Column 'niche_price' already exists.";
        }
        
        // Add lawn_price column if it doesn't exist
        if (!$lawn_exists) {
            $alter_query = "ALTER TABLE `settings` 
                           ADD COLUMN `lawn_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `niche_price`";
            
            if (mysqli_query($conn, $alter_query)) {
                $messages[] = "✓ Successfully added 'lawn_price' column to settings table.";
            } else {
                throw new Exception("Failed to add lawn_price column: " . mysqli_error($conn));
            }
        } else {
            $messages[] = "✓ Column 'lawn_price' already exists.";
        }
        
        // Add mausoleum_price column if it doesn't exist
        if (!$mausoleum_exists) {
            $alter_query = "ALTER TABLE `settings` 
                           ADD COLUMN `mausoleum_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `lawn_price`";
            
            if (mysqli_query($conn, $alter_query)) {
                $messages[] = "✓ Successfully added 'mausoleum_price' column to settings table.";
            } else {
                throw new Exception("Failed to add mausoleum_price column: " . mysqli_error($conn));
            }
        } else {
            $messages[] = "✓ Column 'mausoleum_price' already exists.";
        }
        
        // Commit transaction
        mysqli_commit($conn);
        $success = true;
        $messages[] = "✓ Migration completed successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        mysqli_rollback($conn);
        $error = $e->getMessage();
        $messages[] = "✗ Error: " . $error;
    }
}

// Verify the columns exist
if ($success) {
    $verify_query = "DESCRIBE settings";
    $verify_result = mysqli_query($conn, $verify_query);
    $columns_found = [
        'niche_price' => false,
        'lawn_price' => false,
        'mausoleum_price' => false
    ];
    
    if ($verify_result) {
        while ($row = mysqli_fetch_assoc($verify_result)) {
            if (isset($columns_found[$row['Field']])) {
                $columns_found[$row['Field']] = true;
            }
        }
    }
    
    $all_found = true;
    foreach ($columns_found as $column => $found) {
        if (!$found) {
            $all_found = false;
            $messages[] = "⚠ Warning: Column '$column' may not have been created properly.";
        }
    }
    
    if ($all_found) {
        $messages[] = "✓ Verification: All columns confirmed in settings table.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - Plot Prices</title>
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
            margin-right: 1rem;
        }
        .btn:hover {
            background: #1f3659;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Migration: Plot Prices</h1>
        
        <?php foreach ($messages as $message): ?>
            <div class="message <?php echo $success ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endforeach; ?>
        
        <?php if ($success): ?>
            <div class="next-steps">
                <h2>Next Steps:</h2>
                <ol>
                    <li>Go to <strong>Reports & Analytics</strong> in the admin panel</li>
                    <li>Navigate to the <strong>Plot Price Information</strong> section</li>
                    <li>Click the <strong>Edit</strong> button to set your plot prices</li>
                    <li>Enter prices for Niches, Lawn, and Mausoleum</li>
                    <li>Click <strong>Update Prices</strong> to save</li>
                </ol>
                <a href="../admin/reports.php" class="btn">Go to Reports & Analytics</a>
                <a href="../admin/settings.php" class="btn btn-secondary">Go to System Settings</a>
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
