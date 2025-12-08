<?php
session_start();
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

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$confirmed = isset($_POST['confirm_reset']) && $_POST['confirm_reset'] === '1';

if ($username === '' || $full_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and full name are required.']);
    exit();
}

if (!$confirmed) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please confirm that you cannot access your account.']);
    exit();
}

$stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Account not found. Please verify your username.']);
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

if (strcasecmp(trim($user['full_name']), $full_name) !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Full name does not match our records.']);
    exit();
}

if (has_pending_reset_request($conn, $username)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'A pending request already exists for this account.']);
    exit();
}

if (!create_password_reset_request($conn, $username, $full_name, $reason)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to submit your request. Please try again later.']);
    exit();
}

echo json_encode(['success' => true, 'message' => "Request received. We'll help you shortly."]);
exit();

