<?php
// Prevent any accidental output (notices, BOM, includes) from breaking JSON
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

session_start();
// Inline auth: auth_check.php may redirect (HTML), which breaks fetch(). Return JSON instead.
if (!isset($_SESSION['staff_session']) || !isset($_SESSION['staff_user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired or not authorized', 'plots' => []]);
    exit();
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired', 'plots' => []]);
    exit();
}
$_SESSION['last_activity'] = time();

require_once '../config/database.php';

// Discard any accidental output from config/includes so only our JSON is sent
ob_clean();

$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$row_number = isset($_GET['row_number']) ? intval($_GET['row_number']) : 0;
$exclude_plot_id = isset($_GET['exclude_plot_id']) ? intval($_GET['exclude_plot_id']) : 0;

if ($section_id <= 0 || $row_number <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid section or row number.'
    ]);
    exit();
}

// Get plots for the given section and row (any status), excluding the specified plot if provided
$query = "SELECT plot_id, plot_number, status
          FROM plots
          WHERE section_id = ? AND row_number = ?";
          
if ($exclude_plot_id > 0) {
    $query .= " AND plot_id != ?";
}

$query .= " ORDER BY CAST(plot_number AS UNSIGNED) ASC";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement.'
    ]);
    exit();
}

if ($exclude_plot_id > 0) {
    mysqli_stmt_bind_param($stmt, "iii", $section_id, $row_number, $exclude_plot_id);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $section_id, $row_number);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$plots = [];
while ($row = mysqli_fetch_assoc($result)) {
    $plots[] = [
        'plot_id' => (int)$row['plot_id'],
        'plot_number' => $row['plot_number'],
        'status' => $row['status'],
    ];
}

echo json_encode([
    'success' => true,
    'plots' => $plots
]);

exit();
