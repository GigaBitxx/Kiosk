<?php
require_once '../config/database.php';

// Check if admin already exists
$query = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_assoc($result);

if ($row['count'] == 0) {
    // Create admin account
    $username = "admin";
    $password = password_hash("admin123", PASSWORD_DEFAULT); // Default password: admin123
    $full_name = "Administrator";
    $role = "admin";
    
    $query = "INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssss", $username, $password, $full_name, $role);
    mysqli_stmt_execute($stmt);
    
    echo "Admin account created successfully!<br>";
    echo "Username: admin<br>";
    echo "Password: admin123<br>";
    echo "<a href='login.php'>Go to Login</a>";
} else {
    echo "Admin account already exists!<br>";
    echo "<a href='login.php'>Go to Login</a>";
}
?> 