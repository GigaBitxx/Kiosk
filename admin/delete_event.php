<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';
require_once 'includes/logging.php';

header('Content-Type: application/json');

// Get JSON data from request
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['event_id'])) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

$event_id = mysqli_real_escape_string($conn, $data['event_id']);

// Get event details before deletion for logging
$event_query = "SELECT title, event_date FROM events WHERE event_id = '$event_id'";
$event_result = mysqli_query($conn, $event_query);
$event_info = '';
if ($event_result && mysqli_num_rows($event_result) > 0) {
    $event_row = mysqli_fetch_assoc($event_result);
    $event_info = $event_row['title'] . ' (Date: ' . $event_row['event_date'] . ')';
}

// Delete the event
$query = "DELETE FROM events WHERE event_id = '$event_id'";
if (mysqli_query($conn, $query)) {
    log_action('Alert', 'Deleted event ID: ' . $event_id . ($event_info ? ' - ' . $event_info : ''), $_SESSION['user_id']);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting event: ' . mysqli_error($conn)]);
}
?> 