<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
require_once '../config/database.php';

$record_id = $_GET['record_id'] ?? null;
$plot_id = $_GET['plot_id'] ?? null;

if (!$record_id || !$plot_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Check if deceased_records table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = mysqli_num_rows($table_check) > 0;

$record = null;

if ($use_deceased_records) {
    // Use deceased_records table
    $query = "SELECT d.*, p.*, s.section_name 
              FROM deceased_records d 
              JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE d.record_id = ? AND p.plot_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    mysqli_stmt_bind_param($stmt, "ii", $record_id, $plot_id);
    mysqli_stmt_execute($stmt);
    $record = mysqli_stmt_get_result($stmt)->fetch_assoc();
} else {
    // Use deceased table (fallback)
    $query = "SELECT d.*, p.*, s.section_name,
                     CONCAT(d.first_name, ' ', d.last_name) as full_name,
                     d.date_of_death,
                     d.date_of_burial
              FROM deceased d 
              JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE d.deceased_id = ? AND p.plot_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    mysqli_stmt_bind_param($stmt, "ii", $record_id, $plot_id);
    mysqli_stmt_execute($stmt);
    $record = mysqli_stmt_get_result($stmt)->fetch_assoc();
}

if (!$record) {
    // Debug information
    $debug_info = [
        'record_id' => $record_id,
        'plot_id' => $plot_id,
        'use_deceased_records' => $use_deceased_records,
        'query' => $query ?? 'No query executed'
    ];
    echo json_encode(['success' => false, 'message' => 'Record not found', 'debug' => $debug_info]);
    exit();
}

echo json_encode(['success' => true, 'record' => $record]);
?>
