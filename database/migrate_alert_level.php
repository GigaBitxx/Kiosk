<?php
/**
 * Migration script to add 'Alert' level to logs table
 * Run this once to update the database schema
 */

require_once __DIR__ . '/../config/database.php';

// Check current enum values
$check_query = "SHOW COLUMNS FROM logs WHERE Field = 'level'";
$result = mysqli_query($conn, $check_query);

if ($result && $row = mysqli_fetch_assoc($result)) {
    $type = $row['Type'];
    
    // Check if 'Alert' is already in the enum
    if (strpos($type, 'Alert') === false) {
        // Add 'Alert' to the enum
        $alter_query = "ALTER TABLE `logs` MODIFY COLUMN `level` ENUM('Info','Warning','Error','Alert') NOT NULL";
        
        if (mysqli_query($conn, $alter_query)) {
            echo "Successfully added 'Alert' level to logs table.\n";
        } else {
            echo "Error adding 'Alert' level: " . mysqli_error($conn) . "\n";
        }
    } else {
        echo "'Alert' level already exists in logs table.\n";
    }
} else {
    echo "Error checking logs table structure: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
?>

