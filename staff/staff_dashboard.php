<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Get statistics
$stats = [
    'total_plots' => 0,
    'available_plots' => 0,
    'reserved_plots' => 0,
    'occupied_plots' => 0
];

$query = "SELECT status, COUNT(*) as count FROM plots GROUP BY status";
$result = mysqli_query($conn, $query);
while ($row = mysqli_fetch_assoc($result)) {
    $stats['total_plots'] += $row['count'];
    if (isset($row['status'])) {
        $status_key = $row['status'] . '_plots';
        if (isset($stats[$status_key])) {
            $stats[$status_key] = $row['count'];
        }
    }
}

// Get total plots count
$total_query = "SELECT COUNT(*) as total FROM plots";
$total_result = mysqli_query($conn, $total_query);
if ($total_result && $row = mysqli_fetch_assoc($total_result)) {
    $stats['total_plots'] = $row['total'];
}

// Get announcements (staff can only view, not edit)
$announcements = [];
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'announcements'");
if (mysqli_num_rows($check_table) > 0) {
    $announcements_query = "SELECT a.*, u.full_name as created_by_name 
                            FROM announcements a 
                            LEFT JOIN users u ON a.created_by = u.user_id 
                            ORDER BY 
                                CASE WHEN a.scheduled_date IS NOT NULL THEN 0 ELSE 1 END,
                                a.scheduled_date ASC,
                                a.created_at DESC 
                            LIMIT 5";
    $announcements_result = mysqli_query($conn, $announcements_query);
    while ($row = mysqli_fetch_assoc($announcements_result)) {
        $announcements[] = $row;
    }
}

// Get assistance requests from kiosk users (only from today - resets daily)
$assistance_requests = [];
$check_assistance_table = mysqli_query($conn, "SHOW TABLES LIKE 'assistance_requests'");
if (mysqli_num_rows($check_assistance_table) > 0) {
    $today = date('Y-m-d');
    
    // Delete all requests from previous days (reset daily)
    $delete_old_query = "DELETE FROM assistance_requests WHERE DATE(created_at) < '$today'";
    mysqli_query($conn, $delete_old_query);
    
    // Fetch only today's requests
    $assistance_query = "SELECT * FROM assistance_requests 
                        WHERE DATE(created_at) = '$today'
                        ORDER BY 
                        CASE urgency WHEN 'urgent' THEN 0 ELSE 1 END,
                        created_at DESC 
                        LIMIT 50";
    $assistance_result = mysqli_query($conn, $assistance_query);
    while ($row = mysqli_fetch_assoc($assistance_result)) {
        $assistance_requests[] = $row;
    }
}

// Get upcoming events and activities for timeline
$upcoming_events = [];
$today = date('Y-m-d');
$next_month = date('Y-m-d', strtotime('+30 days'));

// Get upcoming events from events table (fetch up to 30 to ensure we have enough)
$events_query = "SELECT event_id, title, type, event_date, description 
                  FROM events 
                  WHERE event_date >= '$today' AND event_date <= '$next_month'
                  ORDER BY event_date ASC, title ASC
                  LIMIT 30";
$events_result = mysqli_query($conn, $events_query);
while ($row = mysqli_fetch_assoc($events_result)) {
    $event_type_key = strtolower($row['type']);
    $upcoming_events[] = [
        'type' => 'event',
        'title' => $row['title'],
        'date' => $row['event_date'],
        'event_type' => $event_type_key,
        'description' => $row['description']
    ];
}

// Get upcoming burial dates (fetch up to 30 to ensure we have enough)
$burial_query = "SELECT deceased_id, CONCAT(first_name, ' ', last_name) as name, date_of_burial, plot_id
                 FROM deceased 
                 WHERE date_of_burial >= '$today' AND date_of_burial <= '$next_month'
                 ORDER BY date_of_burial ASC
                 LIMIT 30";
$burial_result = mysqli_query($conn, $burial_query);
while ($row = mysqli_fetch_assoc($burial_result)) {
    $upcoming_events[] = [
        'type' => 'burial',
        'title' => $row['name'] . ' - Burial',
        'date' => $row['date_of_burial'],
        'event_type' => 'burial',
        'description' => 'Burial scheduled'
    ];
}

// Sort all events by date
usort($upcoming_events, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Limit to maximum 30 events, but ensure minimum 15 if available
$total_events = count($upcoming_events);
if ($total_events > 30) {
    $upcoming_events = array_slice($upcoming_events, 0, 30);
} elseif ($total_events < 15 && $total_events > 0) {
    // If we have fewer than 15, try to get more by extending the date range
    $extended_date = date('Y-m-d', strtotime('+60 days'));
    
    // Get additional events if we have less than 15
    if ($total_events < 15) {
        $additional_events_query = "SELECT event_id, title, type, event_date, description 
                      FROM events 
                      WHERE event_date > '$next_month' AND event_date <= '$extended_date'
                      ORDER BY event_date ASC, title ASC
                      LIMIT " . (15 - $total_events);
        $additional_events_result = mysqli_query($conn, $additional_events_query);
        while ($row = mysqli_fetch_assoc($additional_events_result)) {
            $event_type_key = strtolower($row['type']);
            $upcoming_events[] = [
                'type' => 'event',
                'title' => $row['title'],
                'date' => $row['event_date'],
                'event_type' => $event_type_key,
                'description' => $row['description']
            ];
        }
        
        // Get additional burials if still needed
        $current_count = count($upcoming_events);
        if ($current_count < 15) {
            $additional_burial_query = "SELECT deceased_id, CONCAT(first_name, ' ', last_name) as name, date_of_burial, plot_id
                         FROM deceased 
                         WHERE date_of_burial > '$next_month' AND date_of_burial <= '$extended_date'
                         ORDER BY date_of_burial ASC
                         LIMIT " . (15 - $current_count);
            $additional_burial_result = mysqli_query($conn, $additional_burial_query);
            while ($row = mysqli_fetch_assoc($additional_burial_result)) {
                $upcoming_events[] = [
                    'type' => 'burial',
                    'title' => $row['name'] . ' - Burial',
                    'date' => $row['date_of_burial'],
                    'event_type' => 'burial',
                    'description' => 'Burial scheduled'
                ];
            }
        }
        
        // Re-sort after adding additional events
        usort($upcoming_events, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        // Limit to 30 maximum after adding additional events
        if (count($upcoming_events) > 30) {
            $upcoming_events = array_slice($upcoming_events, 0, 30);
        }
    }
}

$timeline_type_labels = [
    'burial' => 'Burial',
    'maintenance' => 'Maintenance',
    'funeral' => 'Funeral',
    'chapel' => 'Chapel Service',
    'appointment' => 'Appointment',
    'holiday' => 'Holiday',
    'exhumation' => 'Exhumation',
    'cremation' => 'Cremation',
    'other' => 'Event'
];

$timeline_type_icons = [
    'burial' => "<i class='bx bx-cross' style=\"color: #000000;\"></i>",
    'maintenance' => "<i class='bx bx-wrench' style=\"color: #000000;\"></i>",
    'funeral' => "<i class='bx bx-ghost' style=\"color: #000000;\"></i>",
    'chapel' => "<i class='bx bxs-church' style=\"color: #000000;\"></i>",
    'appointment' => "<i class='bx bx-time' style=\"color: #000000;\"></i>",
    'holiday' => "<i class='bx bx-party' style=\"color: #000000;\"></i>",
    'exhumation' => "<i class='bx bx-box' style=\"color: #000000;\"></i>",
    'cremation' => "<i class='bx bxs-hot' style=\"color: #000000;\"></i>",
    'other' => "<i class='bx bx-calendar-event' style=\"color: #000000;\"></i>"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        .main {
            padding: 48px 40px 32px 40px;
        }
        .dashboard-container {
            display: flex;
            gap: 24px;
            height: calc(100vh - 120px);
            min-height: 600px;
        }
        .dashboard-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .dashboard-section {
            flex: 0 0 auto;
        }
        .timeline-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .announcement-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1d2a38;
            margin: 0; /* remove extra gap so subtitle sits directly under, like TIMELINE header */
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .section-header-with-help {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .help-icon-btn {
            background: transparent;
            border: none;
            color: #2b4c7e;
            cursor: pointer;
            font-size: 1.4rem;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .help-icon-btn:hover {
            background: #f1f5f9;
            transform: scale(1.1);
        }
        /* Announcement Help Modal Styles */
        .announcement-help-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .announcement-help-modal-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }
        .announcement-help-modal {
            position: relative;
            background: #ffffff;
            border-radius: 16px;
            max-width: 90vw;
            width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 10001;
            box-shadow: 0 25px 55px rgba(15, 23, 42, 0.25);
            transform: scale(0.9);
            transition: transform 0.3s ease;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }
        .announcement-help-modal-overlay.active .announcement-help-modal {
            transform: scale(1);
        }
        .announcement-help-modal-header {
            position: relative;
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
        }
        .announcement-help-modal-close {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #5b6c86;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .announcement-help-modal-close:hover {
            background: #f1f5f9;
            color: #1d2a38;
        }
        .announcement-help-modal-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            color: #1d2a38;
            margin: 0;
        }
        .announcement-help-modal-body {
            padding: 2rem;
        }
        .announcement-help-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(15, 23, 42, 0.1);
        }
        .announcement-help-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1d2a38;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .announcement-help-card h3 i {
            color: #2b4c7e;
        }
        .announcement-help-card p {
            font-size: 1rem;
            color: #4a5568;
            line-height: 1.6;
            margin: 0;
        }
        .assistance-request-item {
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-left: 4px solid #2b4c7e;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }
        .assistance-request-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }
        .assistance-request-item.urgent {
            border-left-color: #e74c3c;
        }
        .assistance-request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
            gap: 1rem;
        }
        .assistance-request-category {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1d2a38;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .assistance-request-category i {
            font-size: 1.2rem;
            color: #2b4c7e;
        }
        .assistance-request-item.urgent .assistance-request-category i {
            color: #e74c3c;
        }
        .assistance-request-urgency {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .assistance-request-urgency.normal {
            background: #e0f2fe;
            color: #0369a1;
        }
        .assistance-request-urgency.urgent {
            background: #fee2e2;
            color: #dc2626;
        }
        .assistance-request-description {
            font-size: 0.95rem;
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 0.75rem;
            white-space: pre-wrap;
        }
        .assistance-request-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(15, 23, 42, 0.1);
            font-size: 0.85rem;
            color: #666;
        }
        .assistance-request-status {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .assistance-request-status.pending {
            background: #fef3c7;
            color: #d97706;
        }
        .assistance-request-status.in_progress {
            background: #dbeafe;
            color: #2563eb;
        }
        .assistance-request-status.resolved {
            background: #d1fae5;
            color: #059669;
        }
        .assistance-request-status.closed {
            background: #f3f4f6;
            color: #6b7280;
        }
        .assistance-requests-empty {
            text-align: center;
            padding: 3rem 2rem;
            color: #999;
        }
        .assistance-requests-empty i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 1rem;
        }
        .assistance-requests-empty p {
            font-size: 1rem;
            margin: 0;
        }
        @media (max-width: 768px) {
            .announcement-help-modal {
                width: 95%;
                max-height: 95vh;
            }
            .announcement-help-modal-header,
            .announcement-help-modal-body {
                padding: 1.5rem;
            }
            .announcement-help-card {
                padding: 1.5rem;
            }
        }
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px;
        }
        .stat-box {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .stat-label {
            font-size: 14px;
            font-weight: 700;
            color: #1d2a38;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 600;
        }
        .stat-box:nth-child(1) .stat-value {
            color: #2b4c7e; /* Dark blue for Total Plots */
        }
        .stat-box:nth-child(2) .stat-value {
            color: #f5a623; /* Orange-yellow for Available Plots */
        }
        .stat-box:nth-child(3) .stat-value {
            color: #3bb3b3; /* Teal/light blue-green for Reserved Plots */
        }
        .stat-box:nth-child(4) .stat-value {
            color: #e26a2c; /* Orange-red for Occupied Plots */
        }
        .section-subtitle {
            font-size: 13px;
            color: #666;
            margin-bottom: 14px;
        }
        .timeline-box {
            flex: 1;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px;
            min-height: 580px;
            overflow-y: auto;
        }
        .view-calendar-link {
            color: #2b4c7e;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
        }
        .view-calendar-link:hover {
            color: #1f3659;
            text-decoration: underline;
        }
        .timeline-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
            font-size: 14px;
        }
        .timeline-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .timeline-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .timeline-item:hover {
            background: #f0f2f5;
            transform: translateX(4px);
        }
        .timeline-date {
            flex-shrink: 0;
        }
        .date-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #e0e7ff;
            color: #2b4c7e;
        }
        .date-badge.today {
            background: #fee2e2;
            color: #dc3545;
        }
        .date-badge.tomorrow {
            background: #fef3c7;
            color: #f59e0b;
        }
        .timeline-content {
            flex: 1;
            min-width: 0;
        }
        .timeline-title {
            font-size: 14px;
            font-weight: 600;
            color: #1d2a38;
            margin-bottom: 2px;
            text-align: left;
        }
        .timeline-type {
            font-size: 12px;
            color: #666;
            text-align: left;
        }
        .timeline-icon {
            flex-shrink: 0;
            font-size: 20px;
        }
        /* Override Bootstrap primary button color for consistency */
        .btn-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
        }
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #1f3659;
            border-color: #1f3659;
        }
        /* Bootstrap alert styling consistency */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        /* Bootstrap badge styling */
        .badge {
            font-size: 0.75em;
            padding: 0.375em 0.75em;
            border-radius: 0.25rem;
        }
        /* Bootstrap form control styling */
        .form-control {
            display: block;
            width: 100%;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            color: #212529;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #2b4c7e;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
        }
        .announcement-box {
            flex: 1;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px;
            min-height: 340px;
            overflow-y: auto;
            max-height: 580px;
        }
        .announcement-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
            font-size: 14px;
            text-align: center;
            padding: 40px 20px;
        }
        .announcement-empty i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 12px;
        }
        .announcement-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        @media (max-width: 900px) {
            .announcement-list {
                grid-template-columns: 1fr;
            }
        }
        .announcement-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-top: 4px solid #2b4c7e;
            border-radius: 8px;
            padding: 16px 18px;
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }
        .announcement-minimize-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #f0f0f0;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
            padding: 0;
            font-size: 12px;
            color: #666;
        }
        .announcement-minimize-btn:hover {
            background: #e0e0e0;
            border-color: #bbb;
            color: #2b4c7e;
        }
        .announcement-body {
            max-height: 1000px;
            transition: max-height 0.3s ease, opacity 0.3s ease, margin 0.3s ease;
            overflow: hidden;
        }
        .announcement-item.collapsed .announcement-body {
            max-height: 0;
            opacity: 0;
            margin: 0;
        }
        .announcement-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
            border-left-color: #1f3659;
        }
        .announcement-content {
            color: #1d2a38;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.6;
            margin: 0 0 14px 0;
            padding: 0;
            word-wrap: break-word;
        }
        .announcement-content::selection {
            background: transparent;
            color: #1d2a38;
        }
        .announcement-content::-moz-selection {
            background: transparent;
            color: #1d2a38;
        }
        .announcement-item {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        .announcement-item * {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        .announcement-meta {
            color: #666;
            font-size: 12px;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .announcement-meta small {
            display: block;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .announcement-created {
            color: #888;
            font-size: 12px;
        }
        .announcement-scheduled {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #2b4c7e;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 10px;
            background: #e0e7ff;
            border-radius: 6px;
            margin-top: 12px;
        }
        .announcement-scheduled i {
            font-size: 14px;
        }
        .announcement-item.collapsed .scheduled-label {
            display: none;
        }
        .announcement-item.collapsed .announcement-scheduled {
            display: none;
        }
        .announcement-posted {
            display: none;
            align-items: center;
            gap: 6px;
            color: #2b4c7e;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 10px;
            background: #e0e7ff;
            border-radius: 6px;
            margin-top: 12px;
        }
        .announcement-item.collapsed .announcement-posted {
            display: flex;
        }
        .announcement-posted i {
            font-size: 14px;
        }
        /* Responsive Media Queries */
        
        /* Large Desktop (1024px and up) */
        @media (max-width: 1024px) {
            .dashboard-container {
                flex-direction: column;
                height: auto;
                gap: 20px;
            }
            
            .announcement-section,
            .timeline-section {
                flex: 1;
                min-height: 400px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Tablet (768px and below) */
        @media (max-width: 768px) {
            .main {
                padding: 24px 20px;
            }
            
            .dashboard-container {
                gap: 16px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                padding: 16px;
            }
            
            .stat-value {
                font-size: 28px;
            }
            
            .stat-label {
                font-size: 12px;
            }
            
            .section-title {
                font-size: 16px;
            }
            
            .timeline-box {
                min-height: 400px;
                padding: 16px;
            }
            
            .announcement-help-modal {
                width: 95%;
                max-height: 95vh;
            }
            
            .announcement-help-modal-header,
            .announcement-help-modal-body {
                padding: 1.5rem;
            }
            
            .announcement-help-card {
                padding: 1.5rem;
            }
        }
        
        /* Mobile Large (480px and below) */
        @media (max-width: 480px) {
            .main {
                padding: 16px 12px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 16px;
            }
            
            .stat-box {
                padding: 12px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
            }
            
            .stat-value {
                font-size: 32px;
            }
            
            .stat-label {
                font-size: 13px;
            }
            
            .section-title {
                font-size: 15px;
            }
            
            .section-subtitle {
                font-size: 12px;
            }
            
            .dashboard-section,
            .announcement-section,
            .timeline-section {
                gap: 12px;
            }
            
            .timeline-box,
            .announcement-box {
                padding: 16px 12px;
            }
            
            .timeline-item {
                padding: 10px;
                gap: 10px;
            }
            
            .timeline-title {
                font-size: 13px;
            }
            
            .timeline-type {
                font-size: 11px;
            }
            
            .view-calendar-link {
                font-size: 12px;
            }
            
            .help-icon-btn {
                font-size: 1.2rem;
                padding: 0.4rem;
            }
            
            .section-header-with-help {
                flex-wrap: wrap;
                gap: 8px;
            }
        }
        
        /* Mobile Small (320px and below) */
        @media (max-width: 320px) {
            .main {
                padding: 12px 8px;
            }
            
            .stats-grid {
                padding: 12px;
                gap: 8px;
            }
            
            .stat-value {
                font-size: 24px;
            }
            
            .stat-label {
                font-size: 11px;
            }
            
            .section-title {
                font-size: 14px;
            }
            
            .timeline-box,
            .announcement-box {
                padding: 12px 8px;
            }
            
            .timeline-item {
                padding: 8px;
                gap: 8px;
            }
            
            .timeline-title {
                font-size: 12px;
            }
            
            .timeline-type {
                font-size: 10px;
            }
            
            .date-badge {
                font-size: 10px;
                padding: 4px 8px;
            }
        }
        
        /* Prevent horizontal scrolling */
        body {
            overflow-x: hidden;
        }
        
        .layout {
            overflow-x: hidden;
        }
        
        /* Responsive Design - Standard Breakpoints */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .main {
                padding: 24px 20px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .sidebar a {
                font-size: 14px;
                padding: 10px 20px;
            }
            
            .sidebar-logo {
                width: 80px;
            }
            
            .logo {
                font-size: 18px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            
            /* Prevent horizontal scroll */
            body, html {
                overflow-x: hidden;
                max-width: 100vw;
            }
            
            .layout {
                overflow-x: hidden;
                max-width: 100vw;
            }
        }
        
        /* Responsive announcement items */
        @media (max-width: 768px) {
            .announcement-item {
                padding: 12px;
            }
            
            .announcement-content {
                font-size: 14px;
            }
            
            .announcement-posted,
            .announcement-created {
                font-size: 11px;
            }
        }
        
        @media (max-width: 480px) {
            .announcement-item {
                padding: 10px;
            }
            
            .announcement-content {
                font-size: 13px;
            }
        }
        .main {
            flex: 1;
            padding: 48px 40px 32px 40px;
            background: #f5f5f5;
            margin-left: 240px;
            transition: margin-left 0.2s ease, padding 0.3s ease;
        }
        .sidebar.collapsed + .main {
            margin-left: 100px;
        }
        
        /* Responsive Dashboard Container */
        @media (max-width: 1100px) {
            .dashboard-container {
                flex-direction: column;
                height: auto;
                min-height: auto;
            }
            .main {
                margin-left: 0 !important;
                padding: 24px 20px !important;
            }
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                gap: 20px;
            }
            .main {
                padding: 16px 12px !important;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 20px;
            }
            .section-title {
                font-size: 16px;
            }
            .section-subtitle {
                font-size: 12px;
            }
        }
        
        @media (max-width: 576px) {
            .main {
                padding: 12px 8px !important;
            }
            .stats-grid {
                padding: 16px;
            }
            .stat-value {
                font-size: 28px;
            }
            .stat-label {
                font-size: 12px;
            }
        }

    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="dashboard-container">
            <div class="dashboard-left">
                <!-- METRICS Section -->
                <div class="dashboard-section">
                    <h2 class="section-title">METRICS</h2>
                    <div class="section-subtitle">Plot Statistics</div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-label">TOTAL PLOTS</div>
                            <div class="stat-value"><?php echo $stats['total_plots']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">AVAILABLE PLOTS</div>
                            <div class="stat-value"><?php echo $stats['available_plots']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">OCCUPIED PLOTS</div>
                            <div class="stat-value"><?php echo $stats['occupied_plots']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">RESERVE PLOTS</div>
                            <div class="stat-value"><?php echo $stats['reserved_plots']; ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- ANNOUNCEMENTS Section (now under Metrics) -->
            <div class="announcement-section">
                    <div class="section-header-with-help">
                        <h2 class="section-title">ANNOUNCEMENTS</h2>
                        <button class="help-icon-btn" onclick="openAnnouncementHelp()" aria-label="Help">
                            <i class='bx bx-help-circle'></i>
                        </button>
                    </div>
                    <div class="section-subtitle">Reminders / Updates</div>
                <div class="announcement-box">
                    <?php if (empty($announcements)): ?>
                        <div class="announcement-empty">
                            <i class='bx bx-bullhorn'></i>
                            <p>No announcements yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="announcement-list">
                            <?php foreach ($announcements as $index => $announcement): ?>
                                <div class="announcement-item" id="announcement-<?php echo $index; ?>">
                                    <button class="announcement-minimize-btn" onclick="toggleAnnouncement(<?php echo $index; ?>)" aria-label="Minimize announcement">
                                        <i class='bx bx-minus'></i>
                                    </button>
                                    <div class="announcement-body">
                                        <div class="announcement-content">
                                            <?php echo nl2br(htmlspecialchars(trim($announcement['content']))); ?>
                                        </div>
                                        <div class="announcement-meta">
                                            <small class="announcement-created">
                                                <?php 
                                                $created_date = new DateTime($announcement['created_at']);
                                                echo 'Posted: ' . $created_date->format('M d, Y') . ' - ' . $created_date->format('h:i A');
                                                if ($announcement['created_by_name']) {
                                                    echo ' • ' . htmlspecialchars($announcement['created_by_name']);
                                                }
                                                ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php 
                                    $created_date = new DateTime($announcement['created_at']);
                                    $posted_date_formatted = 'Posted: ' . $created_date->format('M d, Y') . ' - ' . $created_date->format('h:i A');
                                    ?>
                                    <div class="announcement-posted">
                                        <i class='bx bx-time'></i>
                                        <span><?php echo $posted_date_formatted; ?></span>
                                    </div>
                                    <?php if (!empty($announcement['scheduled_date'])): ?>
                                        <div class="announcement-scheduled">
                                            <i class='bx bx-calendar'></i>
                                            <span>
                                                <span class="scheduled-label">Expired: </span>
                                                <?php 
                                                $scheduled = new DateTime($announcement['scheduled_date']);
                                                echo $scheduled->format('M d, Y');
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- TIMELINE Section (now on the right) -->
            <div class="timeline-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h2 class="section-title" style="margin: 0;">TIMELINE</h2>
                        <div class="section-subtitle">Activity Log &amp; System Alerts</div>
                    </div>
                    <a href="calendar.php" class="view-calendar-link">View Calendar →</a>
                </div>
                <div class="timeline-box">
                    <?php if (empty($upcoming_events)): ?>
                        <div class="timeline-empty">
                            <p>No upcoming events or activities</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline-list">
                            <?php foreach ($upcoming_events as $event): 
                                // Determine event type key - check title for cremation first
                                $eventTypeKey = strtolower($event['event_type']);
                                $title_lower = strtolower($event['title']);
                                if (strpos($title_lower, 'cremation') !== false) {
                                    $eventTypeKey = 'cremation';
                                }
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?php 
                                        $event_date = new DateTime($event['date']);
                                        $today_date = new DateTime($today);
                                        $diff = $today_date->diff($event_date)->days;
                                        
                                        if ($diff == 0) {
                                            echo '<span class="date-badge today">TODAY</span>';
                                        } elseif ($diff == 1) {
                                            echo '<span class="date-badge tomorrow">TOMORROW</span>';
                                        } else {
                                            echo '<span class="date-badge">' . $event_date->format('M d') . '</span>';
                                        }
                                        ?>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <div class="timeline-type">
                                            <?php echo $timeline_type_labels[$eventTypeKey] ?? 'Event'; ?>
                                        </div>
                                    </div>
                                    <div class="timeline-icon">
                                        <?php echo $timeline_type_icons[$eventTypeKey] ?? $timeline_type_icons['other']; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
<script>
    function toggleAnnouncement(index) {
        const item = document.getElementById('announcement-' + index);
        if (item) {
            item.classList.toggle('collapsed');
            const icon = item.querySelector('.announcement-minimize-btn i');
            if (item.classList.contains('collapsed')) {
                icon.className = 'bx bx-plus';
            } else {
                icon.className = 'bx bx-minus';
            }
        }
    }

    function openAnnouncementHelp() {
        const overlay = document.getElementById('announcementHelpModalOverlay');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeAnnouncementHelp(event) {
        if (event && event.target !== event.currentTarget) {
            return;
        }
        const overlay = document.getElementById('announcementHelpModalOverlay');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
</script>
    <!-- Announcement Help Modal -->
    <div class="announcement-help-modal-overlay" id="announcementHelpModalOverlay" onclick="closeAnnouncementHelp(event)">
        <div class="announcement-help-modal" onclick="event.stopPropagation()">
            <div class="announcement-help-modal-header">
                <button class="announcement-help-modal-close" onclick="closeAnnouncementHelp()" aria-label="Close">
                    <i class='bx bx-x'></i>
                </button>
                <h1 class="announcement-help-modal-title">Request Assistance</h1>
            </div>
            <div class="announcement-help-modal-body">
                <?php if (empty($assistance_requests)): ?>
                    <div class="assistance-requests-empty">
                        <i class='bx bx-inbox'></i>
                        <p>No assistance requests at this time.</p>
                    </div>
                <?php else: ?>
                    <?php 
                    // Category icons mapping
                    $category_icons = [
                        'help-finding-grave' => 'bx bxs-pin',
                        'burial-schedule-inquiry' => 'bx bx-square',
                        'map-navigation-help' => 'bx bx-compass',
                        'general-inquiry' => 'bx bx-bulb',
                    ];
                    
                    // Category display names
                    $category_names = [
                        'help-finding-grave' => 'Help Finding a Grave',
                        'burial-schedule-inquiry' => 'Burial Schedule Inquiry',
                        'map-navigation-help' => 'Map Navigation Help',
                        'general-inquiry' => 'General Inquiry',
                    ];
                    
                    foreach ($assistance_requests as $request): 
                        $category = $request['category'];
                        $is_urgent = $request['urgency'] === 'urgent';
                        
                        // Handle "others" category with custom_category
                        if ($category === 'others' && !empty($request['custom_category'])) {
                            $category_name = htmlspecialchars($request['custom_category']);
                            $icon = 'bx bx-message-dots';
                        } else {
                            $icon = isset($category_icons[$category]) ? $category_icons[$category] : 'bx bx-message-dots';
                            $category_name = isset($category_names[$category]) ? $category_names[$category] : ucwords(str_replace(['-', '_'], ' ', $category));
                        }
                        
                        $request_date = new DateTime($request['created_at']);
                    ?>
                        <div class="assistance-request-item <?php echo $is_urgent ? 'urgent' : ''; ?>">
                            <div class="assistance-request-header">
                                <div class="assistance-request-category">
                                    <i class='<?php echo $icon; ?>'></i>
                                    <span><?php echo htmlspecialchars($category_name); ?></span>
                                </div>
                                <span class="assistance-request-urgency <?php echo $request['urgency']; ?>">
                                    <i class='bx <?php echo $is_urgent ? 'bxs-hot' : 'bx-time-five'; ?>'></i>
                                    <?php echo ucfirst($request['urgency']); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($request['description'])): ?>
                                <div class="assistance-request-description">
                                    <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="assistance-request-meta">
                                <span class="assistance-request-status <?php echo str_replace('_', '-', $request['status']); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $request['status'])); ?>
                                </span>
                                <small>
                                    <i class='bx bx-time'></i>
                                    <?php echo $request_date->format('M d, Y - h:i A'); ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html> 