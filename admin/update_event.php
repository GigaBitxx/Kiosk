<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    // Validate required fields
    if (!isset($_POST['event_id']) || !isset($_POST['title']) || !isset($_POST['type']) || !isset($_POST['date']) 
        || !isset($_POST['start_time']) || empty($_POST['start_time'])
        || !isset($_POST['end_time']) || empty($_POST['end_time'])) {
        throw new Exception('Missing required fields');
    }

    // Sanitize input
    $event_id = intval($_POST['event_id']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $date = mysqli_real_escape_string($conn, $_POST['date']);
    $start_time = mysqli_real_escape_string($conn, $_POST['start_time']);
    $end_time = mysqli_real_escape_string($conn, $_POST['end_time']);
    $description = isset($_POST['description']) ? mysqli_real_escape_string($conn, $_POST['description']) : '';

    if ($start_time === $end_time) {
        throw new Exception('Start and end time cannot be the same.');
    }

    // Validate event type
    $valid_types = ['burial', 'maintenance', 'funeral', 'chapel', 'appointment', 'holiday', 'exhumation', 'cremation', 'other'];
    if (!in_array($type, $valid_types)) {
        throw new Exception('Invalid event type');
    }

    // Check if event_time column exists, if not add it
    $check_column = mysqli_query($conn, "SHOW COLUMNS FROM events LIKE 'event_time'");
    if ($check_column && mysqli_num_rows($check_column) == 0) {
        mysqli_query($conn, "ALTER TABLE events ADD COLUMN event_time TIME NULL AFTER event_date");
    }
    $check_end_column = mysqli_query($conn, "SHOW COLUMNS FROM events LIKE 'end_time'");
    if ($check_end_column && mysqli_num_rows($check_end_column) == 0) {
        mysqli_query($conn, "ALTER TABLE events ADD COLUMN end_time TIME NULL AFTER event_time");
    }
    
    // Update ENUM type to include exhumation and cremation if they don't exist
    $check_enum = mysqli_query($conn, "SHOW COLUMNS FROM events WHERE Field = 'type'");
    if ($check_enum && mysqli_num_rows($check_enum) > 0) {
        $enum_row = mysqli_fetch_assoc($check_enum);
        $enum_type = $enum_row['Type'];
        // Check if exhumation and cremation are in the ENUM
        if (strpos($enum_type, 'exhumation') === false || strpos($enum_type, 'cremation') === false) {
            // Alter the ENUM to include the new types
            mysqli_query($conn, "ALTER TABLE events MODIFY COLUMN type ENUM('burial','maintenance','funeral','chapel','appointment','holiday','exhumation','cremation','other') NOT NULL");
        }
    }

    // Prevent editing of expired events (based on existing event date/time)
    $check_query = "SELECT event_date, event_time FROM events WHERE event_id = $event_id";
    $check_result = mysqli_query($conn, $check_query);
    if (mysqli_num_rows($check_result) == 0) {
        throw new Exception('Event not found');
    }
    $event_data = mysqli_fetch_assoc($check_result);
    $existing_date = $event_data['event_date'];
    $existing_time = $event_data['event_time'];
    if ($existing_date) {
        if ($existing_time) {
            $event_datetime = $existing_date . ' ' . $existing_time;
            $now = date('Y-m-d H:i:s');
            if (strtotime($event_datetime) < strtotime($now)) {
                throw new Exception('You cannot edit an event that has already passed.');
            }
    } else {
            $today_only = date('Y-m-d');
            if (strtotime($existing_date) < strtotime($today_only)) {
                throw new Exception('You cannot edit an event that has already passed.');
            }
        }
    }

    // Update the event with new values
    $query = "UPDATE events SET title = '$title', type = '$type', event_date = '$date', event_time = '$start_time', end_time = '$end_time', description = '$description' WHERE event_id = $event_id";

    if (mysqli_query($conn, $query)) {
        echo json_encode([
            'success' => true,
            'message' => 'Event updated successfully'
        ]);
    } else {
        throw new Exception('Error updating event: ' . mysqli_error($conn));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

