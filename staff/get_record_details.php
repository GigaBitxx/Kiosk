<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
require_once '../config/database.php';

$record_id = $_GET['id'] ?? null;

if (!$record_id) {
    echo json_encode(['success' => false, 'message' => 'Missing record ID']);
    exit();
}

// Check if deceased_records table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = mysqli_num_rows($table_check) > 0;

$record = null;

if ($use_deceased_records) {
    // Use deceased_records table
    $query = "SELECT d.*, p.plot_number, p.row_number, p.status, s.section_name 
              FROM deceased_records d 
              JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE d.record_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    mysqli_stmt_bind_param($stmt, "i", $record_id);
    mysqli_stmt_execute($stmt);
    $record = mysqli_stmt_get_result($stmt)->fetch_assoc();
} else {
    // Use deceased table (fallback)
    $query = "SELECT d.*, p.plot_number, p.row_number, p.status, s.section_name,
                     CONCAT(d.first_name, ' ', d.last_name) as full_name,
                     d.date_of_death,
                     d.date_of_burial as burial_date
              FROM deceased d 
              JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE d.deceased_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    mysqli_stmt_bind_param($stmt, "i", $record_id);
    mysqli_stmt_execute($stmt);
    $record = mysqli_stmt_get_result($stmt)->fetch_assoc();
}

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    exit();
}

echo json_encode(['success' => true, 'record' => $record]);
?>
