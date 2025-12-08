<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/password_reset_helper.php';

ensure_password_reset_table($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;

if ($request_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$stmt = $conn->prepare("SELECT username FROM password_reset_requests WHERE id = ? AND status = 'pending'");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Password reset request not found or already processed.']);
    exit();
}

$request = $result->fetch_assoc();
$stmt->close();
$username = $request['username'];

$user_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$user_stmt->bind_param("s", $username);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    $user_stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User account not found.']);
    exit();
}

$user = $user_result->fetch_assoc();
$user_stmt->close();

$temporary_password = generate_temporary_password(8);
$hashed_password = password_hash($temporary_password, PASSWORD_DEFAULT);

$update_user = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
$update_user->bind_param("si", $hashed_password, $user['user_id']);

if (!$update_user->execute()) {
    $update_user->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reset password.']);
    exit();
}
$update_user->close();

$admin_id = $_SESSION['user_id'];
$update_request = $conn->prepare("UPDATE password_reset_requests SET status = 'completed', admin_id = ?, temp_password_hash = ?, updated_at = NOW() WHERE id = ?");
$update_request->bind_param("isi", $admin_id, $hashed_password, $request_id);
$update_request->execute();
$update_request->close();

log_action('Info', "Admin ID {$admin_id} reset password for {$username}", $admin_id);

echo json_encode([
    'success' => true,
    'message' => 'Password reset successfully.',
    'temporary_password' => $temporary_password
]);
exit();

