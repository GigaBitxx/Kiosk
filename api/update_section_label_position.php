<?php
require_once '../config/database.php';
require_once '../includes/auth_check.php';

// Only allow staff and admin
if ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['section_id']) || !isset($input['lat_offset']) || !isset($input['lng_offset'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    $section_id = intval($input['section_id']);
    $lat_offset = floatval($input['lat_offset']);
    $lng_offset = floatval($input['lng_offset']);
    
    // Shift all plots in this section by the given offset so the whole section moves
    $query = "UPDATE plots 
              SET latitude = latitude + ?, 
                  longitude = longitude + ? 
              WHERE section_id = ?";

    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ddi", $lat_offset, $lng_offset, $section_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Section plots moved successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update plots: ' . mysqli_error($conn)]);
        }
        
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . mysqli_error($conn)]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

