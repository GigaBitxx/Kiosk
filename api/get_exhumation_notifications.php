<?php
require_once '../config/database.php';
require_once '../admin/includes/auth_check.php';

// Only allow admin users
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

// Check if exhumation feature is enabled
$exhumation_enabled = false;
$exhum_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'exhumation_requests'");
if (mysqli_num_rows($exhum_table_check) > 0) {
    $exhumation_enabled = true;
}

if (!$exhumation_enabled) {
    echo json_encode(['notifications' => [], 'count' => 0]);
    exit();
}

// Get timestamp of last check (from request parameter)
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : null;

// Build query to get pending exhumation requests
$query = "SELECT er.request_id, 
                er.source_plot_id,
                er.target_plot_id,
                er.deceased_name,
                er.notes,
                er.created_at,
                sp.plot_number AS source_plot_number,
                ss.section_code AS source_section_code,
                tp.plot_number AS target_plot_number,
                ts.section_code AS target_section_code,
                COALESCE(u.full_name, u.username) AS requested_by_name
         FROM exhumation_requests er
         JOIN plots sp ON er.source_plot_id = sp.plot_id
         JOIN sections ss ON sp.section_id = ss.section_id
         JOIN plots tp ON er.target_plot_id = tp.plot_id
         JOIN sections ts ON tp.section_id = ts.section_id
         LEFT JOIN users u ON er.requested_by = u.user_id
         WHERE er.status = 'pending'";

// If last_check is provided, only get requests created after that time
if ($last_check) {
    $last_check_escaped = mysqli_real_escape_string($conn, $last_check);
    $query .= " AND er.created_at > '$last_check_escaped'";
}

$query .= " ORDER BY er.created_at DESC LIMIT 50";

$result = mysqli_query($conn, $query);

$notifications = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Ensure we have a readable deceased name
        $display_name = trim((string)($row['deceased_name'] ?? ''));
        if ($display_name === '' || $display_name === '0') {
            $display_name = 'Unknown';
        }

        // Format the notification message
        $source_location = $row['source_section_code'] . '-' . $row['source_plot_number'];
        $target_location = $row['target_section_code'] . '-' . $row['target_plot_number'];
        
        $notifications[] = [
            'id' => (int)$row['request_id'],
            'message' => "Exhumation request for {$display_name}",
            'details' => "From {$source_location} to {$target_location}",
            'requested_by' => $row['requested_by_name'] ?? 'Staff',
            'created_at' => $row['created_at'],
            'request_id' => (int)$row['request_id'],
            'source_plot_id' => (int)$row['source_plot_id'],
            'target_plot_id' => (int)$row['target_plot_id']
        ];
    }
}

// Get total count of pending requests
$count_query = "SELECT COUNT(*) as total FROM exhumation_requests WHERE status = 'pending'";
$count_result = mysqli_query($conn, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$total_count = (int)($count_row['total'] ?? 0);

echo json_encode([
    'notifications' => $notifications,
    'count' => $total_count,
    'timestamp' => date('Y-m-d H:i:s')
]);
