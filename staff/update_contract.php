<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$record_id = $_POST['record_id'] ?? null;
$plot_id = $_POST['plot_id'] ?? null;
$contract_start_date = $_POST['contract_start_date'] ?? null;
$contract_end_date = $_POST['contract_end_date'] ?? null;
$contract_type = 'temporary'; // 5-year contracts
$contract_status = $_POST['contract_status'] ?? 'active';
$contract_notes = $_POST['contract_notes'] ?? null;
$renewal_reminder_date = $_POST['renewal_reminder_date'] ?? null;

if (!$record_id || !$plot_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Update plot contract information
$update_query = "UPDATE plots SET 
                 contract_start_date = ?, 
                 contract_end_date = ?, 
                 contract_type = ?, 
                 contract_status = ?, 
                 contract_notes = ?, 
                 renewal_reminder_date = ?
                 WHERE plot_id = ?";

$stmt = mysqli_prepare($conn, $update_query);
mysqli_stmt_bind_param($stmt, "ssssssi", 
    $contract_start_date, 
    $contract_end_date, 
    $contract_type, 
    $contract_status, 
    $contract_notes, 
    $renewal_reminder_date, 
    $plot_id
);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => true, 'message' => 'Contract information updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating contract information: ' . mysqli_error($conn)]);
}
?>
