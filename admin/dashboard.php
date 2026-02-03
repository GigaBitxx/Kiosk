<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

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

// Handle announcement operations
$message = '';
$error = '';
$announcements = [];

if (isset($_POST['add_announcement'])) {
    $content = mysqli_real_escape_string($conn, $_POST['announcement_content']);
    $user_id = $_SESSION['user_id'];
    
    // Check if announcements table exists, if not create it
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'announcements'");
    if (mysqli_num_rows($check_table) == 0) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS announcements (
            announcement_id INT PRIMARY KEY AUTO_INCREMENT,
            content TEXT NOT NULL,
            created_by INT,
            scheduled_date DATE NULL,
            scheduled_time TIME NULL,
            end_time TIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
        )");
    } else {
        // Check if scheduled_date and scheduled_time columns exist
        $check_date = mysqli_query($conn, "SHOW COLUMNS FROM announcements LIKE 'scheduled_date'");
        if (mysqli_num_rows($check_date) == 0) {
            mysqli_query($conn, "ALTER TABLE announcements ADD COLUMN scheduled_date DATE NULL AFTER content");
        }
        $check_time = mysqli_query($conn, "SHOW COLUMNS FROM announcements LIKE 'scheduled_time'");
        if (mysqli_num_rows($check_time) == 0) {
            mysqli_query($conn, "ALTER TABLE announcements ADD COLUMN scheduled_time TIME NULL AFTER scheduled_date");
        }
        $check_end_time = mysqli_query($conn, "SHOW COLUMNS FROM announcements LIKE 'end_time'");
        if (mysqli_num_rows($check_end_time) == 0) {
            mysqli_query($conn, "ALTER TABLE announcements ADD COLUMN end_time TIME NULL AFTER scheduled_time");
        }
    }
    
    $scheduled_date = isset($_POST['scheduled_date']) && !empty($_POST['scheduled_date']) ? mysqli_real_escape_string($conn, $_POST['scheduled_date']) : null;
    
    if (!empty($content)) {
        if ($scheduled_date) {
            $insert_query = "INSERT INTO announcements (content, created_by, scheduled_date) VALUES ('$content', $user_id, '$scheduled_date')";
        } else {
            $insert_query = "INSERT INTO announcements (content, created_by) VALUES ('$content', $user_id)";
        }
        
        if (mysqli_query($conn, $insert_query)) {
            $message = 'Announcement added successfully.';
        } else {
            $error = 'Error adding announcement.';
        }
    }
}

if (isset($_POST['update_announcement'])) {
    $announcement_id = intval($_POST['announcement_id']);
    $content = mysqli_real_escape_string($conn, $_POST['announcement_content']);
    $scheduled_date = isset($_POST['scheduled_date']) && !empty($_POST['scheduled_date']) ? mysqli_real_escape_string($conn, $_POST['scheduled_date']) : null;
    
    // Check if scheduled_date column exists
    $check_date = mysqli_query($conn, "SHOW COLUMNS FROM announcements LIKE 'scheduled_date'");
    if (mysqli_num_rows($check_date) == 0) {
        mysqli_query($conn, "ALTER TABLE announcements ADD COLUMN scheduled_date DATE NULL AFTER content");
    }
    
    if (!empty($content)) {
        if ($scheduled_date) {
            $update_query = "UPDATE announcements SET content = '$content', scheduled_date = '$scheduled_date' WHERE announcement_id = $announcement_id";
        } else {
            $update_query = "UPDATE announcements SET content = '$content', scheduled_date = NULL WHERE announcement_id = $announcement_id";
        }
        
        if (mysqli_query($conn, $update_query)) {
            $message = 'Updated Successfully!';
        } else {
            $error = 'Error updating announcement.';
        }
    }
}

if (isset($_GET['delete_announcement'])) {
    $announcement_id = intval($_GET['delete_announcement']);
    $delete_query = "DELETE FROM announcements WHERE announcement_id = $announcement_id";
    if (mysqli_query($conn, $delete_query)) {
        $message = 'Announcement deleted successfully.';
    } else {
        $error = 'Error deleting announcement.';
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

// Get announcements
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

// Get feedback entries
$feedback_entries = [];
$check_feedback_table = mysqli_query($conn, "SHOW TABLES LIKE 'feedback'");
if (mysqli_num_rows($check_feedback_table) > 0) {
    $feedback_query = "SELECT * FROM feedback ORDER BY created_at DESC";
    $feedback_result = mysqli_query($conn, $feedback_query);
    while ($row = mysqli_fetch_assoc($feedback_result)) {
        $feedback_entries[] = $row;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <?php include 'includes/styles.php'; ?>
    <style>
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
        .section-subtitle {
            font-size: 13px;
            color: #666;
            margin-bottom: 14px;
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
        .timeline-box {
            flex: 1;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px;
            min-height: 600px;
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
        .announcement-box {
            flex: 1;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            padding: 24px;
            min-height: 260px;
            overflow-y: auto;
            max-height: 600px;
        }
        .btn-add-announcement {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .btn-add-announcement:hover {
            background: #1f3659;
        }
        .btn-view-feedback {
            background: #fff;
            color: #2b4c7e;
            border: 1px solid #2b4c7e;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-view-feedback:hover {
            background: #f5f5f5;
            color: #2b4c7e;
            border-color: #1f3659;
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
        .announcement-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }
        .btn-edit-announcement,
        .btn-delete-announcement {
            background: transparent;
            border: none;
            color: #666;
            font-size: 16px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-edit-announcement:hover {
            background: #e0e7ff;
            color: #2b4c7e;
        }
        .btn-delete-announcement:hover {
            background: #fee2e2;
            color: #dc3545;
        }
        .notification-bubble {
            position: fixed;
            top: 24px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            border-radius: 12px;
            color: #fff;
            font-weight: 500;
            font-size: 15px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
        }
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        .notification-bubble i {
            font-size: 20px;
        }
        .notification-bubble span {
            display: inline-block;
        }
        .success-notification {
            background: linear-gradient(135deg, #00b894, #00a184);
        }
        .error-notification {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        /* View Feedback Modal Styling */
        #viewFeedbackModal .modal-dialog {
            max-width: 1100px;
            margin: 2.5vh auto;
        }
        #viewFeedbackModal .modal-content {
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }
        #viewFeedbackModal .modal-body {
            flex: 1;
            overflow-y: auto;
            background: #f5f6fa;
        }
        #viewFeedbackModal .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            align-items: stretch;
        }
        #viewFeedbackModal .feedback-card {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        #viewFeedbackModal .feedback-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        #viewFeedbackModal .feedback-card-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 10px;
        }
        #viewFeedbackModal .feedback-name {
            font-size: 16px;
            font-weight: 600;
            color: #1d2a38;
        }
        #viewFeedbackModal .feedback-meta {
            font-size: 12px;
            color: #666;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        #viewFeedbackModal .feedback-meta-row {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        #viewFeedbackModal .feedback-message {
            font-size: 14px;
            color: #1d2a38;
            line-height: 1.6;
            padding-top: 12px;
            border-top: 1px solid #f0f0f0;
            word-wrap: break-word;
        }
        #viewFeedbackModal .feedback-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #4b5563;
            gap: 8px;
            flex-wrap: wrap;
        }
        #viewFeedbackModal .feedback-page-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        #viewFeedbackModal .feedback-page-btn {
            border: 1px solid #d1d5db;
            background: #ffffff;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            color: #374151;
            min-width: 32px;
            text-align: center;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }
        #viewFeedbackModal .feedback-page-btn:hover:not(.active):not(:disabled) {
            background: #f3f4f6;
        }
        #viewFeedbackModal .feedback-page-btn.active {
            background: #2b4c7e;
            border-color: #2b4c7e;
            color: #ffffff;
        }
        #viewFeedbackModal .feedback-page-btn:disabled {
            opacity: 0.6;
            cursor: default;
        }
        @media (max-width: 768px) {
            #viewFeedbackModal .feedback-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                padding: 48px 60px 32px 60px !important;
            }
            .stats-grid {
                padding: 32px;
                gap: 24px;
            }
            .stat-value {
                font-size: 36px;
            }
            .stat-label {
                font-size: 16px;
            }
            .timeline-box,
            .announcement-box {
                padding: 28px;
            }
            .section-title {
                font-size: 22px;
            }
        }
        
        @media (min-width: 1600px) {
            .main {
                padding: 48px 80px 32px 80px !important;
            }
            .stats-grid {
                padding: 40px;
                gap: 28px;
            }
            .stat-value {
                font-size: 40px;
            }
            .stat-label {
                font-size: 17px;
            }
            .timeline-box,
            .announcement-box {
                padding: 32px;
            }
            .section-title {
                font-size: 24px;
            }
        }
        
        @media (min-width: 1920px) {
            .main {
                padding: 48px 120px 32px 120px !important;
            }
            .stats-grid {
                padding: 48px;
                gap: 32px;
            }
            .stat-value {
                font-size: 44px;
            }
            .stat-label {
                font-size: 18px;
            }
            .timeline-box,
            .announcement-box {
                padding: 40px;
            }
            .section-title {
                font-size: 26px;
            }
        }
        
        @media (max-width: 1200px) {
            .main {
                padding: 40px 32px 24px 32px !important;
            }
        }
        
        @media (max-width: 1100px) {
            .main {
                padding: 32px 24px 20px 24px !important;
            }
            .dashboard-container {
                flex-direction: column;
                height: auto;
            }
            .announcement-section {
                flex: 1;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main {
                padding: 24px 16px 16px 16px !important;
                margin-left: 0 !important;
            }
            .dashboard-container {
                gap: 20px;
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
            .timeline-box,
            .announcement-box {
                padding: 16px;
            }
            .timeline-item {
                padding: 10px;
            }
            .announcement-item {
                padding: 12px 14px;
            }
        }
        
        @media (max-width: 576px) {
            .main {
                padding: 16px 12px 12px 12px !important;
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
            .timeline-box,
            .announcement-box {
                padding: 12px;
                min-height: 300px;
            }
            .timeline-item {
                padding: 8px;
                gap: 8px;
            }
            .date-badge {
                font-size: 10px;
                padding: 3px 8px;
            }
            .timeline-title {
                font-size: 13px;
            }
            .timeline-type {
                font-size: 11px;
            }
            .announcement-content {
                font-size: 13px;
            }
            .btn-add-announcement {
                padding: 8px 16px;
                font-size: 13px;
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <div>
                            <h2 class="section-title" style="margin: 0;">ANNOUNCEMENTS</h2>
                            <div class="section-subtitle">Reminders / Updates</div>
                        </div>
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <button class="btn-view-feedback" onclick="showViewFeedback()">
                                <i class='bx bx-message-square-dots'></i> Feedback
                            </button>
                            <button class="btn-add-announcement" onclick="showAddAnnouncementForm()">
                                <i class='bx bx-plus'></i> Add
                            </button>
                        </div>
                    </div>
                    <?php if ($message): ?>
                        <div id="successNotification" class="notification-bubble success-notification">
                            <i class="bi bi-check-circle-fill"></i>
                            <span><?php echo htmlspecialchars($message); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div id="errorNotification" class="notification-bubble error-notification">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>
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
                                            <div class="announcement-actions">
                                                <button class="btn-edit-announcement" onclick="showEditAnnouncementForm(<?php echo $announcement['announcement_id']; ?>, <?php echo htmlspecialchars(json_encode($announcement['content']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($announcement['scheduled_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                                <button type="button" class="btn-delete-announcement" onclick="showDeleteConfirmation(<?php echo $announcement['announcement_id']; ?>)">
                                                    <i class='bx bx-trash'></i>
                                                </button>
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
    // Helper function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text ? String(text).replace(/[&<>"']/g, m => map[m]) : '';
    }

    // Show view feedback modal with pagination
    function showViewFeedback() {
        try {
            const feedbackData = <?php echo json_encode($feedback_entries ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const pageSize = 6;
            let currentPage = 1;

            // Remove any existing modal first
            const existingModal = document.getElementById('viewFeedbackModal');
            if (existingModal) {
                existingModal.remove();
            }

            const modalEl = document.createElement('div');
            modalEl.className = 'modal fade';
            modalEl.id = 'viewFeedbackModal';
            modalEl.setAttribute('tabindex', '-1');
            modalEl.setAttribute('aria-hidden', 'true');

            const renderPage = () => {
                let bodyContent = '';

                if (!feedbackData || feedbackData.length === 0) {
                    bodyContent = `
                        <div style="text-align: center; padding: 60px 40px; color: #666;">
                            <i class='bx bx-message-square-dots' style="font-size: 64px; color: #ddd; margin-bottom: 16px;"></i>
                            <p style="font-size: 18px; margin: 0; font-weight: 500;">No feedback submitted yet.</p>
                            <p style="font-size: 14px; margin: 8px 0 0 0; color: #999;">Feedback from kiosk users will appear here.</p>
                        </div>
                    `;
                } else {
                    const totalItems = feedbackData.length;
                    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
                    if (currentPage > totalPages) currentPage = totalPages;

                    const start = (currentPage - 1) * pageSize;
                    const end = Math.min(start + pageSize, totalItems);
                    const pageItems = feedbackData.slice(start, end);

                    let gridHTML = '<div class="feedback-grid">';
                    pageItems.forEach((feedback) => {
                        let formattedDate = '—';
                        try {
                            if (feedback.created_at) {
                                const date = new Date(feedback.created_at);
                                if (!isNaN(date.getTime())) {
                                    formattedDate = date.toLocaleDateString('en-US', { 
                                        year: 'numeric', 
                                        month: 'short', 
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    });
                                }
                            }
                        } catch (e) {
                            console.error('Error formatting date:', e);
                        }

                        gridHTML += `
                            <div class="feedback-card">
                                <div class="feedback-card-header">
                                    <div class="feedback-name">
                                        ${escapeHtml(feedback.full_name)}
                                    </div>
                                    <div class="feedback-meta">
                                        ${feedback.contact ? `
                                            <div class="feedback-meta-row">
                                                <i class='bx bx-envelope' style="font-size: 14px;"></i>
                                                <span>${escapeHtml(feedback.contact)}</span>
                                            </div>
                                        ` : ''}
                                        <div class="feedback-meta-row">
                                            <i class='bx bx-time' style="font-size: 13px;"></i>
                                            <span>${formattedDate}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="feedback-message">
                                    ${escapeHtml(feedback.message)}
                                </div>
                            </div>
                        `;
                    });
                    gridHTML += '</div>';

                    // Build pagination controls
                    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
                    let pagesHTML = '';
                    const maxPageButtons = 5;
                    let startPage = Math.max(1, currentPage - 2);
                    let endPage = Math.min(totalPages, startPage + maxPageButtons - 1);
                    if (endPage - startPage + 1 < maxPageButtons) {
                        startPage = Math.max(1, endPage - maxPageButtons + 1);
                    }

                    for (let p = startPage; p <= endPage; p++) {
                        pagesHTML += `
                            <button type="button" class="feedback-page-btn ${p === currentPage ? 'active' : ''}" data-page="${p}">
                                ${p}
                            </button>
                        `;
                    }

                    bodyContent = `
                        ${gridHTML}
                        <div class="feedback-pagination">
                            <div>
                                Showing <strong>${start + 1}</strong>–<strong>${end}</strong> of <strong>${totalItems}</strong> feedback
                            </div>
                            <div class="feedback-page-buttons">
                                <button type="button" class="feedback-page-btn" data-prev ${currentPage === 1 ? 'disabled' : ''}>Prev</button>
                                ${pagesHTML}
                                <button type="button" class="feedback-page-btn" data-next ${currentPage === totalPages ? 'disabled' : ''}>Next</button>
                            </div>
                        </div>
                    `;
                }

                const bodyEl = modalEl.querySelector('.modal-body');
                if (bodyEl) {
                    bodyEl.innerHTML = bodyContent;

                    // Wire up pagination events
                    const prevBtn = bodyEl.querySelector('[data-prev]');
                    const nextBtn = bodyEl.querySelector('[data-next]');
                    const pageBtns = bodyEl.querySelectorAll('.feedback-page-btn[data-page]');

                    if (prevBtn) {
                        prevBtn.addEventListener('click', () => {
                            if (currentPage > 1) {
                                currentPage--;
                                renderPage();
                            }
                        });
                    }
                    if (nextBtn) {
                        nextBtn.addEventListener('click', () => {
                            const totalItems = feedbackData ? feedbackData.length : 0;
                            const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
                            if (currentPage < totalPages) {
                                currentPage++;
                                renderPage();
                            }
                        });
                    }
                    pageBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            const target = parseInt(btn.getAttribute('data-page'), 10);
                            if (!isNaN(target) && target !== currentPage) {
                                currentPage = target;
                                renderPage();
                            }
                        });
                    });
                }
            };

            modalEl.innerHTML = `
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; padding: 20px 24px;">
                        <h5 class="modal-title" style="font-size: 1.5rem; font-weight: 600; margin: 0;">View Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="padding: 24px;">
                    </div>
                </div>
            </div>
        `;

            document.body.appendChild(modalEl);
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            // Initial render
            renderPage();

            modalEl.addEventListener('hidden.bs.modal', function () {
                modalEl.remove();
            });
        } catch (error) {
            console.error('Error showing feedback modal:', error);
            alert('Error loading feedback. Please try again.');
        }
    }

    // Show add announcement form
    function showAddAnnouncementForm() {
        const modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'addAnnouncementModal';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-hidden', 'true');

        modalEl.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="width: 30px;"></div>
                        <h5 class="modal-title" style="flex: 1; text-align: center; font-weight: 600; margin: 0;">Add Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="width: 30px;"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" id="addAnnouncementForm">
                            <div class="mb-3">
                                <label for="announcementContent" class="form-label">Message</label>
                                <textarea class="form-control" id="announcementContent" name="announcement_content" rows="6" required placeholder="Enter your announcement message here..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="scheduledDate" class="form-label">Expiration Date (Optional)</label>
                                <input type="text" class="form-control date-mdY" id="scheduledDate" name="scheduled_date" min="" placeholder="mm/dd/yyyy">
                                <small class="form-text text-muted">Set a date for when this announcement is relevant</small>
                            </div>
                            <button type="submit" name="add_announcement" class="btn btn-primary w-100" style="background-color: #2b4c7e; border-color: #2b4c7e; color: #fff; font-weight: 500;">Post</button>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Set minimum date to today to prevent selecting past dates
        const today = new Date();
        const todayFormatted = today.toISOString().split('T')[0];
        const dateInput = modalEl.querySelector('#scheduledDate');
        if (dateInput) {
            dateInput.setAttribute('min', todayFormatted);
        }

        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
        });
    }

    // Show edit announcement form
    function showEditAnnouncementForm(announcementId, content, scheduledDate) {
        const modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'editAnnouncementModal';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-hidden', 'true');

        // Escape HTML entities
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };

        // Handle null/undefined values
        const safeDate = scheduledDate && scheduledDate !== 'null' && scheduledDate !== '' ? scheduledDate : '';

        modalEl.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="width: 30px;"></div>
                        <h5 class="modal-title" style="flex: 1; text-align: center; font-weight: 600; margin: 0;">Edit Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="width: 30px;"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" id="editAnnouncementForm">
                            <input type="hidden" name="announcement_id" value="${announcementId}">
                            <div class="mb-3">
                                <label for="editAnnouncementContent" class="form-label">Message</label>
                                <textarea class="form-control" id="editAnnouncementContent" name="announcement_content" rows="6" required>${escapeHtml(content)}</textarea>
                            </div>
                            <div class="mb-3">
                                <label for="editScheduledDate" class="form-label">Expiration Date (Optional)</label>
                                <input type="text" class="form-control date-mdY" id="editScheduledDate" name="scheduled_date" value="${safeDate}" min="" placeholder="mm/dd/yyyy">
                                <small class="form-text text-muted">Set a date for when this announcement is relevant</small>
                            </div>
                            <button type="submit" name="update_announcement" class="btn btn-primary w-100" style="background-color: #2b4c7e; border-color: #2b4c7e; color: #fff; font-weight: 500;">Update</button>
                        </form>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Set minimum date to today to prevent selecting past dates
        const today = new Date();
        const todayFormatted = today.toISOString().split('T')[0];
        const dateInput = modalEl.querySelector('#editScheduledDate');
        if (dateInput) {
            dateInput.setAttribute('min', todayFormatted);
        }

        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
        });
    }

    // Toggle announcement collapse/expand
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

    // Show delete confirmation modal
    function showDeleteConfirmation(announcementId) {
        const modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'deleteAnnouncementModal';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-hidden', 'true');

        modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body" style="padding: 24px;">
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
                            <div style="width: 48px; height: 40px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class='bx bx-trash' style="font-size: 24px; color: #dc3545;"></i>
                            </div>
                            <div style="flex: 1;">
                                <p style="margin: 0; font-size: 16px; color: #212529; font-weight: 500;">Delete this announcement?</p>
                                <p style="margin: 8px 0 0 0; font-size: 14px; color: #6c757d;">This action cannot be undone.</p>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px;">Cancel</button>
                            <a href="?delete_announcement=${announcementId}" class="btn btn-danger" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px; text-decoration: none; background-color: #dc3545; border-color: #dc3545;">Delete</a>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
        });
    }

    // Notification handling
    document.addEventListener('DOMContentLoaded', function() {
        const successNotification = document.getElementById('successNotification');
        const errorNotification = document.getElementById('errorNotification');

        const showNotification = (notification) => {
            if (!notification) return;
            setTimeout(() => notification.classList.add('show'), 100);
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
            }, 4000);
        };

        showNotification(successNotification);
        showNotification(errorNotification);
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Flatpickr setup for announcement expiration dates
        document.addEventListener('DOMContentLoaded', function () {
            const dateInputs = document.querySelectorAll('input.date-mdY');
            if (!dateInputs.length || !window.flatpickr) return;

            dateInputs.forEach(function (input) {
                flatpickr(input, {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "m/d/Y",
                    allowInput: true
                });
            });
        });
    </script>
</body>
</html> 