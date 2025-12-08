<?php
header('Content-Type: application/json');

try {
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}
require_once '../config/database.php';

    // Try to include logging, but don't fail if it doesn't work
    $logging_available = false;
    if (file_exists('../admin/includes/logging.php')) {
        try {
            require_once '../admin/includes/logging.php';
            $logging_available = function_exists('log_action');
        } catch (Exception $e) {
            // Logging not available, continue without it
        }
    }

// Get JSON data from request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['event_id'])) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

$event_id = intval($data['event_id']);
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
        exit();
    }

$user_id = $_SESSION['user_id'];

// Check if event exists and was created by current user
    $check_query = "SELECT created_by, title, event_date FROM events WHERE event_id = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $event_id);
    mysqli_stmt_execute($stmt);
    $check_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($check_result) == 0) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
        mysqli_stmt_close($stmt);
    exit();
}

$event_data = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($stmt);
    
if ($event_data['created_by'] != $user_id) {
    echo json_encode(['success' => false, 'message' => 'You can only delete events you created']);
    exit();
}

    // Get event details for logging
    $event_info = $event_data['title'] . ' (Date: ' . $event_data['event_date'] . ')';

// Delete the event
    $delete_query = "DELETE FROM events WHERE event_id = ? AND created_by = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    mysqli_stmt_bind_param($stmt, 'ii', $event_id, $user_id);
    if (mysqli_stmt_execute($stmt)) {
        // Try to log the action, but don't fail if logging doesn't work
        if ($logging_available) {
            try {
                log_action('Alert', 'Deleted event ID: ' . $event_id . ' - ' . $event_info, $_SESSION['user_id']);
            } catch (Exception $e) {
                // Logging failed, but deletion succeeded
            }
        }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting event: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?> 