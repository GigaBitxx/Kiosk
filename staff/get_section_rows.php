<?php
// Set JSON response first so we never send redirects (keeps fetch() from getting HTML)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

session_start();
// Inline auth: redirect would break fetch(); return JSON so client can show message or reload
if (!isset($_SESSION['staff_session']) || !isset($_SESSION['staff_user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired or not authorized', 'rows' => []]);
    exit();
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired', 'rows' => []]);
    exit();
}
$_SESSION['last_activity'] = time();

require_once '../config/database.php';

// Function to convert row number to letter (1=A, 2=B, 27=AA, etc.)
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }

    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1) {
        return '';
    }

    $letter = '';
    while ($rowNumber > 0) {
        $remainder = ($rowNumber - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $rowNumber = intval(($rowNumber - 1) / 26);
    }
    return $letter;
}

// Support both single section_id and multiple section_ids
if (isset($_GET['section_ids'])) {
    // Handle multiple section IDs (comma-separated)
    $section_ids_str = $_GET['section_ids'];
    $section_ids = array_filter(array_map('intval', explode(',', $section_ids_str)));
    
    if (empty($section_ids)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid section IDs'
        ]);
        exit;
    }
    
    // Create placeholders for IN clause
    $placeholders = str_repeat('?,', count($section_ids) - 1) . '?';
    
    // Get distinct row numbers for the selected sections (any status)
    $query = "SELECT DISTINCT row_number 
              FROM plots 
              WHERE section_id IN ($placeholders)
              ORDER BY row_number";
    
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed', 'rows' => []]);
        exit;
    }
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error', 'rows' => []]);
        exit;
    }
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($section_ids)), ...$section_ids);
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => 'Database error', 'rows' => []]);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rowLetter = rowNumberToLetter($row['row_number']);
            $rows[] = [
                'row_number' => $row['row_number'],
                'display_name' => 'Row ' . $rowLetter
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'rows' => $rows
    ]);
} elseif (isset($_GET['section_id']) && is_numeric($_GET['section_id'])) {
    $section_id = intval($_GET['section_id']);
    
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed', 'rows' => []]);
        exit;
    }
    
    // Get distinct row numbers for the selected section (any status)
    $query = "SELECT DISTINCT row_number 
              FROM plots 
              WHERE section_id = ? 
              ORDER BY row_number";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error', 'rows' => []]);
        exit;
    }
    mysqli_stmt_bind_param($stmt, "i", $section_id);
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => 'Database error', 'rows' => []]);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rowLetter = rowNumberToLetter($row['row_number']);
            $rows[] = [
                'row_number' => $row['row_number'],
                'display_name' => 'Row ' . $rowLetter
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'rows' => $rows
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid section ID'
    ]);
}
?>
