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
$contract_notes = $_POST['contract_notes'] ?? null;
$renewal_reminder_date = $_POST['renewal_reminder_date'] ?? null;

if (!$record_id || !$plot_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Automatically determine contract status based on end date
$contract_status = 'active';
if ($contract_end_date) {
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $end_date = new DateTime($contract_end_date);
    $end_date->setTime(0, 0, 0);
    
    // If already expired
    if ($end_date < $today) {
        $contract_status = 'expired';
    }
    // If within 30 days of expiration
    elseif ($end_date <= (clone $today)->modify('+30 days')) {
        $contract_status = 'renewal_needed';
    }
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
    // After updating contract, run maintenance to check if it should be archived immediately
    require_once __DIR__ . '/contract_maintenance.php';
    run_contract_maintenance($conn, false);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Contract information updated successfully',
        'contract_status' => $contract_status
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating contract information: ' . mysqli_error($conn)]);
}
?>
