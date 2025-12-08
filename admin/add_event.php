<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    // Validate required fields (title, type, date, start_time, end_time are required)
    if (!isset($_POST['title']) || !isset($_POST['type']) || !isset($_POST['date']) 
        || !isset($_POST['start_time']) || empty($_POST['start_time'])
        || !isset($_POST['end_time']) || empty($_POST['end_time'])) {
        throw new Exception('Missing required fields');
    }

    // Sanitize input
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $date = mysqli_real_escape_string($conn, $_POST['date']);
    $start_time = mysqli_real_escape_string($conn, $_POST['start_time']);
    $end_time = mysqli_real_escape_string($conn, $_POST['end_time']);
    $description = isset($_POST['description']) ? mysqli_real_escape_string($conn, $_POST['description']) : '';
    $user_id = $_SESSION['user_id'];

    // Validate time range
    if ($start_time === $end_time) {
        throw new Exception('Start and end time cannot be the same.');
    }

    // Validate event type
    $valid_types = ['burial', 'maintenance', 'funeral', 'chapel', 'appointment', 'holiday', 'exhumation', 'cremation', 'other'];
    if (!in_array($type, $valid_types)) {
        throw new Exception('Invalid event type');
    }

    // Prevent creating events in the past (based on date only)
    $today = new DateTime('today');
    $eventDate = DateTime::createFromFormat('Y-m-d', $date);
    if ($eventDate && $eventDate < $today) {
        throw new Exception('You cannot create events in the past.');
    }

    // Ensure event_time column exists (for time-based events)
    $check_column = mysqli_query($conn, "SHOW COLUMNS FROM events LIKE 'event_time'");
    if ($check_column && mysqli_num_rows($check_column) == 0) {
        mysqli_query($conn, "ALTER TABLE events ADD COLUMN event_time TIME NULL AFTER event_date");
    }
    // Ensure end_time column exists
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

    // Insert the event with start/end time
    $query = "INSERT INTO events (title, type, event_date, event_time, end_time, description, created_by) 
              VALUES ('$title', '$type', '$date', '$start_time', '$end_time', '$description', '$user_id')";

    if (mysqli_query($conn, $query)) {
        echo json_encode([
            'success' => true,
            'message' => 'Event added successfully',
            'event_id' => mysqli_insert_id($conn)
        ]);
    } else {
        throw new Exception('Error adding event: ' . mysqli_error($conn));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 