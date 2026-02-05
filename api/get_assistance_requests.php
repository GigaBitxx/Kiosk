<?php
/**
 * API: Get assistance requests (today's active + archived)
 * Returns JSON for real-time polling by staff dashboard.
 * Requires staff or admin session.
 */
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Require staff or admin session
$is_staff = isset($_SESSION['staff_session']) && isset($_SESSION['staff_user_id']);
$is_admin = isset($_SESSION['admin_session']) && isset($_SESSION['admin_user_id']);

if (!$is_staff && !$is_admin) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'requests' => [], 'archived' => []]);
    exit;
}

$active = [];
$archived = [];
$manila_tz = new DateTimeZone('Asia/Manila');
$utc_tz = new DateTimeZone('UTC');

$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'assistance_requests'");
if (mysqli_num_rows($check_table) > 0) {
    // Today in Asia/Manila so times match when users sent (Philippines)
    $manila_today_start = (new DateTime('today', $manila_tz))->setTimezone($utc_tz)->format('Y-m-d H:i:s');
    $manila_today_end = (new DateTime('tomorrow', $manila_tz))->modify('-1 second')->setTimezone($utc_tz)->format('Y-m-d H:i:s');

    $active_query = "SELECT * FROM assistance_requests 
        WHERE created_at >= '" . mysqli_real_escape_string($conn, $manila_today_start) . "'
          AND created_at <= '" . mysqli_real_escape_string($conn, $manila_today_end) . "'
          AND (archived IS NULL OR archived = 0)
        ORDER BY 
        CASE 
            WHEN status = 'pending' THEN 0
            WHEN status = 'in_progress' THEN 1
            WHEN status = 'resolved' THEN 2
            WHEN status = 'closed' THEN 3
            ELSE 4
        END,
        CASE urgency WHEN 'urgent' THEN 0 ELSE 1 END,
        created_at DESC 
        LIMIT 50";
    $active_result = mysqli_query($conn, $active_query);
    while ($row = mysqli_fetch_assoc($active_result)) {
        $dt = new DateTime($row['created_at'], $utc_tz);
        $dt->setTimezone($manila_tz);
        $row['created_at_display'] = $dt->format('M d, Y - h:i A');
        $active[] = $row;
    }

    $archived_query = "SELECT * FROM assistance_requests 
        WHERE created_at >= '" . mysqli_real_escape_string($conn, $manila_today_start) . "'
          AND created_at <= '" . mysqli_real_escape_string($conn, $manila_today_end) . "'
          AND archived = 1
        ORDER BY created_at DESC 
        LIMIT 100";
    $archived_result = mysqli_query($conn, $archived_query);
    if ($archived_result) {
        while ($row = mysqli_fetch_assoc($archived_result)) {
            $dt = new DateTime($row['created_at'], $utc_tz);
            $dt->setTimezone($manila_tz);
            $row['created_at_display'] = $dt->format('M d, Y - h:i A');
            $archived[] = $row;
        }
    }
}

echo json_encode([
    'requests' => $active,
    'archived' => $archived,
]);
