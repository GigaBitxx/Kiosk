<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['exists' => false]);
    exit();
}
require_once '../config/database.php';

header('Content-Type: application/json');

if (isset($_GET['username'])) {
    $username = mysqli_real_escape_string($conn, $_GET['username']);
    $exclude_id = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;
    
    if ($exclude_id > 0) {
        $check_query = "SELECT user_id FROM users WHERE username = '$username' AND user_id != $exclude_id";
    } else {
        $check_query = "SELECT user_id FROM users WHERE username = '$username'";
    }
    
    $check_result = mysqli_query($conn, $check_query);
    $exists = mysqli_num_rows($check_result) > 0;
    
    echo json_encode(['exists' => $exists]);
} else {
    echo json_encode(['exists' => false]);
}
?>

