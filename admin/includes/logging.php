<?php
require_once __DIR__ . '/../../config/database.php';

// Ensure 'Alert' level exists in the database enum (run once)
function ensure_alert_level_exists() {
    global $conn;
    static $checked = false;
    
    if ($checked) {
        return; // Already checked in this request
    }
    
    $check_query = "SHOW COLUMNS FROM logs WHERE Field = 'level'";
    $result = mysqli_query($conn, $check_query);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $type = $row['Type'];
        if (strpos($type, 'Alert') === false) {
            // Add 'Alert' to the enum
            $alter_query = "ALTER TABLE `logs` MODIFY COLUMN `level` ENUM('Info','Warning','Error','Alert') NOT NULL";
            mysqli_query($conn, $alter_query);
        }
    }
    
    $checked = true;
}

function log_action($level, $message, $user_id = null) {
    global $conn;
    if (!$conn) {
        return false;
    }
    
    // Ensure Alert level exists in database
    ensure_alert_level_exists();
    
    $level = mysqli_real_escape_string($conn, trim($level));
    $message = mysqli_real_escape_string($conn, trim($message));
    $user_id_sql = $user_id ? intval($user_id) : 'NULL';
    
    // Ensure level is one of the valid values
    $valid_levels = ['Info', 'Warning', 'Error', 'Alert'];
    if (!in_array($level, $valid_levels)) {
        $level = 'Info'; // Default to Info if invalid
    }
    
    $sql = "INSERT INTO logs (level, message, user_id) VALUES ('$level', '$message', $user_id_sql)";
    $result = mysqli_query($conn, $sql);
    
    // Log error if insert fails
    if (!$result) {
        error_log("Failed to log action: " . mysqli_error($conn));
        return false;
    }
    
    return true;
} 