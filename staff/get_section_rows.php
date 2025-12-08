<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    http_response_code(403);
    exit();
}
require_once '../config/database.php';

header('Content-Type: application/json');

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
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($section_ids)), ...$section_ids);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rowLetter = rowNumberToLetter($row['row_number']);
        $rows[] = [
            'row_number' => $row['row_number'],
            'display_name' => 'Row ' . $rowLetter
        ];
    }
    
    echo json_encode([
        'success' => true,
        'rows' => $rows
    ]);
} elseif (isset($_GET['section_id']) && is_numeric($_GET['section_id'])) {
    $section_id = intval($_GET['section_id']);
    
    // Get distinct row numbers for the selected section (any status)
    $query = "SELECT DISTINCT row_number 
              FROM plots 
              WHERE section_id = ? 
              ORDER BY row_number";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rowLetter = rowNumberToLetter($row['row_number']);
        $rows[] = [
            'row_number' => $row['row_number'],
            'display_name' => 'Row ' . $rowLetter
        ];
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
