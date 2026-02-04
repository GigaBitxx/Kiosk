<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch all plots with their status
$query = "SELECT * FROM plots "
       . "ORDER BY section, "
       . "LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(plot_number, '-', 2), '-', -1), 1), "
       . "CAST(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(plot_number, '-', 2), '-', -1), 2) AS UNSIGNED), "
       . "level_number";
$result = mysqli_query($conn, $query);
$plots = [];
while ($plot = mysqli_fetch_assoc($result)) {
    $plots[] = $plot;
}

// Get upcoming interments (next 30 days) - include both deceased records and burial events from calendar
$query = "SELECT 
            'deceased' as source_type,
            d.record_id as id,
            d.full_name as name,
            d.burial_date as event_date,
            s.section_name,
            p.plot_number
          FROM deceased_records d 
          JOIN plots p ON d.plot_id = p.plot_id 
          LEFT JOIN sections s ON p.section_id = s.section_id
          WHERE d.burial_date >= CURDATE() 
          AND d.burial_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          UNION ALL
          SELECT 
            'event' as source_type,
            e.event_id as id,
            e.title as name,
            e.event_date,
            NULL as section_name,
            NULL as plot_number
          FROM events e
          WHERE e.type = 'burial'
          AND e.event_date >= CURDATE() 
          AND e.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          ORDER BY event_date ASC";
$upcoming_result = mysqli_query($conn, $query);
$upcoming_interments = array();
while ($row = mysqli_fetch_assoc($upcoming_result)) {
    $upcoming_interments[] = $row;
}

// Get recent interments (past 30 days) - include both deceased records and burial events from calendar
$query = "SELECT 
            'deceased' as source_type,
            d.record_id as id,
            d.full_name as name,
            d.burial_date as event_date,
            s.section_name,
            p.plot_number
          FROM deceased_records d 
          JOIN plots p ON d.plot_id = p.plot_id 
          LEFT JOIN sections s ON p.section_id = s.section_id
          WHERE d.burial_date < CURDATE() 
          AND d.burial_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          UNION ALL
          SELECT 
            'event' as source_type,
            e.event_id as id,
            e.title as name,
            e.event_date,
            NULL as section_name,
            NULL as plot_number
          FROM events e
          WHERE e.type = 'burial'
          AND e.event_date < CURDATE() 
          AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          ORDER BY event_date DESC";
$recent_result = mysqli_query($conn, $query);
$recent_interments = array();
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_interments[] = $row;
}

// Get recent deceased records
$query = "SELECT d.*, p.plot_number, s.section_name 
          FROM deceased_records d 
          JOIN plots p ON d.plot_id = p.plot_id 
          LEFT JOIN sections s ON p.section_id = s.section_id
          ORDER BY d.burial_date DESC 
          LIMIT 5";
$recent_deceased = mysqli_query($conn, $query);

// Fetch events for calendar (dummy for now)
$events = [];
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <!-- Add Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Add Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link rel="stylesheet" href="../assets/css/consistency.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        /* Prevent layout shift when Bootstrap modals open */
        body.modal-open {
            overflow: hidden;
            padding-right: 0 !important;
        }
        .layout {
            transition: none;
        }
        .calendar-header {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: 1px;
        }
        .calendar-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            margin-bottom: 24px;
        }
        .calendar-arrow {
            background: #e0e0e0;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #444;
            cursor: pointer;
            transition: background 0.2s;
        }
        .calendar-arrow:hover {
            background: #d0d0d0;
        }
        .calendar-date-label {
            text-align: center;
            font-size: 1.2rem;
            color: #444;
            font-weight: 700;
            min-width: 120px;
        }
        .calendar-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
            justify-content: center;
            position: relative;
            z-index: 10;
        }
        .calendar-tab {
            background: #e0e0e0;
            border: none;
            border-radius: 20px;
            padding: 8px 32px;
            font-size: 16px;
            color: #222;
            cursor: pointer;
            outline: none;
            transition: background 0.2s, color 0.2s;
            position: relative;
            z-index: 11;
            pointer-events: auto;
        }
        .calendar-tab.active {
            background: #fff;
            color: #2b4c7e;
            border: 2px solid #2b4c7e;
        }
        #calendar { 
            width: 100%; 
            /* Fixed calendar height so the page itself does not grow when many events exist */
            height: 650px;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden; /* Keep scrolling inside day cells only */
        }
        
        /* FullCalendar container styling */
        .fc {
            background: transparent;
        }
        
        .fc-theme-standard td, .fc-theme-standard th {
            border-color: #e0e0e0;
        }
        
        .fc-col-header-cell {
            background: transparent;
            padding: 12px 0;
        }
        
        .fc-daygrid-day {
            padding: 6px;
            position: relative;
            height: 115px; /* Fixed height for calendar days */
            overflow: hidden;
        }
        /* Make day frame fixed height and enable scrolling for events */
        .fc-daygrid-day-frame {
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        /* Make events container scrollable without showing scrollbar */
        .fc-daygrid-day-events {
            flex: 1;
            /* Show about 5 events; scroll inside the cell if there are more */
            max-height: 90px;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        .fc-daygrid-day-events::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }
        /* Ensure day number stays at top */
        .fc-daygrid-day-top {
            flex-shrink: 0;
        }
        /* Slightly smaller date text so more room is left for events */
        .fc-daygrid-day-number,
        .fc-daygrid-day-number a {
            font-size: 0.8rem;
            font-weight: 600;
        }
        /* Disable scrolling inside FullCalendar - hide scrollbars and prevent scrolling */
        #calendar .fc-scroller {
            scrollbar-width: none; /* Firefox */
            overflow: hidden !important;
        }
        #calendar .fc-scroller::-webkit-scrollbar {
            width: 0;
            height: 0;
        }
        /* Holiday styling */
        #calendar .fc-holiday-day {
            background-color: #f1f1f1;
        }
        #calendar .fc-holiday-event {
            background-color: transparent !important;
            border: none !important;
            color: #666 !important;
            font-size: 0.75rem;
        }
        /* Ensure holiday event dots are visible */
        #calendar .fc-holiday-event .fc-event-type-dot {
            display: inline-block !important;
            width: 8px !important;
            height: 8px !important;
            border-radius: 50% !important;
            background-color: #6f42c1 !important;
            margin-right: 6px !important;
            vertical-align: middle !important;
        }
        .interments-section {
            display: flex;
            gap: 32px;
            margin-top: 32px;
        }
        .side-box { background: #fff; border-radius: 16px; padding: 24px 20px 20px 20px; min-height: 220px; display: flex; flex-direction: column; }
        .side-box-title { font-size: 18px; font-weight: 500; margin-bottom: 16px; color: #222; }
        .interment-list { flex: 1; overflow-y: auto; }
        .interment-card { background: #f5f5f5; border-radius: 10px; padding: 12px 16px; margin-bottom: 12px; }
        .interment-card:last-child { margin-bottom: 0; }
        .interment-name { font-weight: 500; margin-bottom: 4px; }
        .interment-date { color: #2b4c7e; font-size: 15px; margin-bottom: 2px; }
        .interment-plot { color: #888; font-size: 14px; }
        /* Notification Bubble Styles */
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
            font-weight: 700;
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
        @media (max-width: 1100px) {
            .interments-section { flex-direction: column; gap: 24px; }
        }
        /* Responsive Design - Calendar Specific */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            #calendar {
                height: 500px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            #calendar {
                height: 400px;
                padding: 10px;
            }
        }
    </style>
    <style>
    /* Force all calendar text to black in staff calendar */
    #calendar, #calendar *,
    #calendar .fc-col-header-cell-cushion,
    #calendar .fc-col-header-cell a,
    #calendar .fc-col-header-cell a:visited,
    #calendar .fc-col-header-cell a:active,
    #calendar .fc-col-header-cell a:hover,
    #calendar .fc-daygrid-day-number,
    #calendar .fc-daygrid-day-number a,
    #calendar .fc-daygrid-day-number a:visited,
    #calendar .fc-daygrid-day-number a:active,
    #calendar .fc-daygrid-day-number a:hover {
        color: #111 !important;
        text-shadow: 0 0 0 #111 !important;
    }
    #calendar .fc-col-header-cell a,
    #calendar .fc-col-header-cell a:visited,
    #calendar .fc-col-header-cell a:hover,
    #calendar .fc-daygrid-day-number,
    #calendar .fc-daygrid-day-number a,
    #calendar .fc-daygrid-day-number a:visited,
    #calendar .fc-daygrid-day-number a:hover {
        text-decoration: none !important;
    }
        /* Add cursor styles for better accessibility */
        .fc .fc-daygrid-day {
            cursor: pointer;
        }
        .fc .fc-daygrid-day:hover {
            cursor: pointer;
        }
        .fc .fc-daygrid-day.fc-day-today {
            cursor: pointer;
        }
        .fc .fc-daygrid-day-number, 
        .fc .fc-daygrid-day-number a {
            cursor: pointer;
        }
        .fc .fc-daygrid-day.fc-day-other .fc-daygrid-day-number, 
        .fc .fc-daygrid-day.fc-day-other .fc-daygrid-day-number a {
            cursor: not-allowed;
        }
        .fc .fc-event {
            cursor: pointer;
        }
        .fc-event-type-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
        }
        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 30px;
            padding: 16px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #1d2a38;
        }
        .legend-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        @media (max-width: 1100px) {
            .dashboard-row { flex-direction: column; gap: 24px; }
        }
        /* Modal styles */
        .modal-bg {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.25);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-bg.active { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 16px;
            padding: 32px 24px 24px 24px;
            min-width: 320px;
            max-width: 400px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
            position: relative;
        }
        /* Full-screen styling for event list modal */
        #eventListModal {
            background: rgba(0,0,0,0.15);
        }
        #eventListModal .modal-content.modal-events {
            max-width: 1280px;
            width: 98%;
            max-height: 95vh;
            padding: 24px 24px 20px 24px;
            display: flex;
            flex-direction: column;
        }
        .modal-events-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .modal-subtitle {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        .modal-events-body {
            display: flex;
            gap: 24px;
            flex: 1;
            min-height: 0;
        }
        .modal-filter-panel {
            width: 230px;
            border-right: 1px solid #eee;
            padding-right: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .filter-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2a38;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .filter-chips {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .filter-chip {
            border: 1px solid #e0e0e0;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
            text-align: left;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .filter-chip.active {
            background: #2b4c7e;
            border-color: #2b4c7e;
            color: #fff;
        }
        .modal-events-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 0;
        }
        .events-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .events-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            gap: 12px;
        }
        .events-section span{
            font-size: 14px;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .events-section-header span {
            font-size: 14px;
            font-weight: 600;
            color: #1d2a38;
        }
        .events-range-select {
            width: 130px;
        }
        .events-list {
            flex: 1;
            overflow-y: auto;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            padding: 8px 12px;
            background: #fafafa;
        }
        /* Today's schedule: 3-up flex grid */
        .events-list--today {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            border: none;
            background: transparent;
            padding: 0;
        }
        /* Stacked cards for upcoming / overdue */
        .events-list--stacked {
            border-color: #f0f0f0;
            background: #fafafa;
        }
        .events-list-empty {
            padding: 12px 4px;
            font-size: 13px;
            color: #777;
        }
        .events-list-item {
            padding: 8px 4px;
            border-bottom: 1px solid #eee;
        }
        .events-list-item:last-child {
            border-bottom: none;
        }
        .events-list--today .events-list-item {
            border: 2px solid #e0e7ff;
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
            flex: 1 1 calc(33.333% - 12px);
            min-width: 220px;
            min-height: 100px;
        }
        .events-list--stacked .events-list-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
            margin-bottom: 6px;
        }
        .events-list-item-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .events-list-item-meta {
            font-size: 12px;
            color: #666;
        }
        .modal-close {
            position: absolute;
            top: 12px;
            right: 18px;
            font-size: 22px;
            color: #888;
            background: none;
            border: none;
            cursor: pointer;
        }
        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 18px;
            color: #2b4c7e;
        }
        .modal-event-list {
            max-height: 320px;
            overflow-y: auto;
        }
        .modal-event-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .modal-event-item:last-child { border-bottom: none; }
        .modal-event-name { font-weight: 500; }
        .modal-event-date { color:#2b4c7e; font-size: 14px; }
        .modal-event-plot { color: #888; font-size: 13px; }
        /* Override Bootstrap primary button color for consistency */
        .btn-primary {
            background-color: #2b4c7e !important;
            border-color: #2b4c7e !important;
        }
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #1f3659 !important;
            border-color: #1f3659 !important;
        }
        /* Ensure Add Event modal button uses correct color */
        #addEventModal .btn-primary,
        #addEventModal button[type="submit"] {
            background-color: #2b4c7e !important;
            border-color: #2b4c7e !important;
        }
        #addEventModal .btn-primary:hover,
        #addEventModal .btn-primary:focus,
        #addEventModal .btn-primary:active,
        #addEventModal button[type="submit"]:hover,
        #addEventModal button[type="submit"]:focus,
        #addEventModal button[type="submit"]:active {
            background-color: #1f3659 !important;
            border-color: #1f3659 !important;
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
        /* Bootstrap form control styling - using consistency.css standards */
        .form-control {
            display: block;
            width: 100%;
            padding: 10px 12px;
            font-size: var(--font-size-sm, 14px);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            font-weight: var(--font-weight-normal, 400);
            line-height: 1.5;
            color: #212529;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius-md, 8px);
            min-height: 40px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #2b4c7e;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
        }

.sidebar-bottom {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0;
}

.sidebar-logo {
    width: 100px;
    height: auto;
    object-fit: contain;
    display: block;
}       
.sidebar.collapsed .sidebar-logo {
    width: 50px;
}

.sidebar a i {
    font-size: 18px;
    margin-right: 10px;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}
.sidebar.collapsed a i {
    margin-right: 0;
    font-size: 20px;
}
.sidebar a.active i {
    font-size: 18px;
    margin-right: 10px;
}

    </style>
    <script>
        let currentView = 'dayGridMonth';
        const EVENT_TYPE_LABELS = {
            burial: 'Burial',
            maintenance: 'Maintenance',
            funeral: 'Funeral',
            chapel: 'Chapel',
            appointment: 'Appointment',
            holiday: 'Holiday',
            exhumation: 'Exhumation',
            cremation: 'Cremation',
            other: 'Other',
            interment: 'Interment'
        };
        const MONTH_LABELS = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];
        function formatDateLabel(dateInput) {
            const date = new Date(dateInput);
            if (isNaN(date)) return dateInput || '';
            const month = MONTH_LABELS[date.getMonth()] || '';
            const day = date.getDate();
            const year = date.getFullYear();
            return `${month} ${day}, ${year}`;
        }
        function formatEventType(type) {
            if (type === null || type === undefined) return '—';
            const normalized = (typeof type === 'string' ? type : String(type)).trim();
            if (!normalized) return '—';
            const key = normalized.toLowerCase();
            if (EVENT_TYPE_LABELS[key]) {
                return EVENT_TYPE_LABELS[key];
            }
            return normalized.charAt(0).toUpperCase() + normalized.slice(1);
        }
        function getEventTypeColor(type, isDue) {
            if (isDue) {
                return '#dc3545'; // red for expired events
            }
            const eventType = (type || '').toLowerCase();
            switch (eventType) {
                case 'burial':
                    return '#000000';
                case 'maintenance':
                    return '#198754';
                case 'funeral':
                    return '#ffc107';
                case 'chapel':
                    return '#fd7e14';
                case 'appointment':
                    return '#2b4c7e';
                case 'holiday':
                    return '#6f42c1';
                case 'exhumation':
                    return '#8b4513';
                case 'cremation':
                    return '#800000';
                case 'other':
                    return '#6c757d';
                default:
                    return '#6c757d';
            }
        }
        function formatEventTime(startTimeStr, endTimeStr) {
            if (!startTimeStr) return '';
            const [h, m] = startTimeStr.split(':');
            if (h === undefined || m === undefined) return '';
            const date = new Date();
            date.setHours(parseInt(h, 10), parseInt(m, 10));
            let formatted = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            
            if (endTimeStr) {
                const [eh, em] = endTimeStr.split(':');
                if (eh !== undefined && em !== undefined) {
                    const endDate = new Date();
                    endDate.setHours(parseInt(eh, 10), parseInt(em, 10));
                    formatted += ' - ' + endDate.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                }
            }
            
            return formatted;
        }
        function renderEventContent(info) {
            const wrapper = document.createElement('div');
            wrapper.className = 'fc-custom-event';
            const startTime = info.event.extendedProps?.event_time || '';
            const endTime = info.event.extendedProps?.end_time || '';
            const timeText = info.event.extendedProps?.event_time_formatted || info.timeText || formatEventTime(startTime, endTime);
            const isHoliday = !!info.event.extendedProps?.isHoliday;
            // Determine color based on event type and due status
            const eventType = isHoliday ? 'holiday' : (info.event.extendedProps?.type || '').toLowerCase();
            const isDue = !!info.event.extendedProps?.is_due;
            let typeColor = '#6c757d'; // default gray
            if (isDue) {
                typeColor = '#dc3545'; // red for expired events
            } else {
                switch (eventType) {
                    case 'burial':
                        typeColor = '#000000'; // black
                        break;
                    case 'maintenance':
                        typeColor = '#198754'; // green
                        break;
                    case 'funeral':
                        typeColor = '#ffc107'; // yellow
                        break;
                    case 'chapel':
                        typeColor = '#fd7e14'; // orange
                        break;
                    case 'appointment':
                        typeColor = '#2b4c7e'; // blue
                        break;
                    case 'holiday':
                        typeColor = '#6f42c1'; // violet
                        break;
                    case 'exhumation':
                        typeColor = '#8b4513'; // brown
                        break;
                    case 'cremation':
                        typeColor = '#800000'; // maroon
                        break;
                    case 'other':
                        typeColor = '#6c757d'; // gray
                        break;
                }
            }
            // For holidays, show dot inline with title
            if (isHoliday) {
                const titleEl = document.createElement('div');
                titleEl.className = 'fc-event-title-label';
                const dot = document.createElement('span');
                dot.className = 'fc-event-type-dot';
                dot.style.backgroundColor = typeColor;
                dot.style.display = 'inline-block';
                dot.style.width = '8px';
                dot.style.height = '8px';
                dot.style.borderRadius = '50%';
                dot.style.marginRight = '6px';
                dot.style.verticalAlign = 'middle';
                titleEl.appendChild(dot);
                const titleText = document.createElement('span');
                titleText.textContent = info.event.title;
                titleEl.appendChild(titleText);
                wrapper.appendChild(titleEl);
            } else {
                // For other events, show time with dot if available
                if (timeText) {
                    const timeEl = document.createElement('div');
                    timeEl.className = 'fc-event-time-label';
                    // Colored dot beside time
                    const dot = document.createElement('span');
                    dot.className = 'fc-event-type-dot';
                    dot.style.backgroundColor = typeColor;
                    timeEl.appendChild(dot);
                    const timeTextNode = document.createElement('span');
                    timeTextNode.textContent = timeText;
                    timeEl.appendChild(timeTextNode);
                    wrapper.appendChild(timeEl);
                }
                const titleEl = document.createElement('div');
                titleEl.className = 'fc-event-title-label';
                titleEl.textContent = info.event.title;
                wrapper.appendChild(titleEl);
            }
            return { domNodes: [wrapper] };
        }
        function setCalendarView(view) {
            currentView = view;
            var calendar = window.calendar;
            if (calendar) calendar.changeView(view);
            document.querySelectorAll('.calendar-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById('tab-' + view).classList.add('active');
        }
        function updateCalendarDateLabel() {
            var calendar = window.calendar;
            if (calendar) {
                var view = calendar.view;
                var date = view.currentStart;
                var month = date.toLocaleString('default', { month: 'long' });
                var year = date.getFullYear();
                document.getElementById('calendar-date-label').textContent = month + ' ' + year;
            }
        }

        /**
         * Return events for Philippine holidays for a given year.
         * These will be rendered as greyed-out days with labels.
         */
        function getPhilippineHolidaysForYear(year) {
            // Fixed-date regular and special (non-working) holidays
            const fixedHolidays = [
                { month: 1,  day: 1,  title: "New Year's Day" },
                { month: 4,  day: 9,  title: "Araw ng Kagitingan" },
                { month: 5,  day: 1,  title: "Labor Day" },
                { month: 6,  day: 12, title: "Independence Day" },
                { month: 8,  day: 21, title: "Ninoy Aquino Day" },
                { month: 11, day: 1,  title: "All Saints' Day" },
                { month: 11, day: 2,  title: "All Souls' Day" },
                { month: 11, day: 30, title: "Bonifacio Day" },
                { month: 12, day: 25, title: "Christmas Day" },
                { month: 12, day: 30, title: "Rizal Day" }
            ];

            return fixedHolidays.map(h => {
                const month = String(h.month).padStart(2, '0');
                const day = String(h.day).padStart(2, '0');
                const dateStr = `${year}-${month}-${day}`;

                return {
                    title: h.title,
                    start: dateStr,
                    allDay: true,
                    editable: false,
                    overlap: true,
                    classNames: ['fc-holiday-event'],
                    extendedProps: {
                        isHoliday: true,
                        type: 'holiday'
                    }
                };
            });
        }
        function calendarPrevMonth() {
            var calendar = window.calendar;
            if (calendar) {
                calendar.prev();
            }
        }
        function calendarNextMonth() {
            var calendar = window.calendar;
            if (calendar) {
                calendar.next();
            }
        }
        // Store current user ID for permission checks
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
        }
        
        // Helper to open the "Events for this date" modal (also used after adding events)
        function openDateEventsModal(dateStr) {
            // If a previous dateEventsModal exists, hide and remove it to avoid duplicates
            const existingDateModalEl = document.getElementById('dateEventsModal');
            if (existingDateModalEl) {
                const existingModal = bootstrap.Modal.getInstance(existingDateModalEl);
                if (existingModal) {
                    existingModal.hide();
                }
                existingDateModalEl.remove();
            }

            // Determine if the clicked date is in the past (based on date only)
            const clickedDate = new Date(dateStr + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const isPastDate = clickedDate < today;

            const formattedDate = formatDateLabel(clickedDate);

            // Fetch events for the clicked date
            fetch('../api/get_events.php?date=' + dateStr)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(events => {
                            // Sort events so those with earlier times appear first
                            events.sort((a, b) => {
                                const aDate = new Date(a.start);
                                const bDate = new Date(b.start);
                                return aDate - bDate;
                            });
                            // Create modal element
                            const modalEl = document.createElement('div');
                            modalEl.className = 'modal fade';
                            modalEl.id = 'dateEventsModal';
                            modalEl.setAttribute('tabindex', '-1');
                            modalEl.setAttribute('aria-hidden', 'true');

                            let modalContent = `
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Events for ${formattedDate}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="event-list mb-3">
                                                ${events.length === 0 ? '<p>No events for this date</p>' : ''}
                                                ${events.map(event => {
                                                    const isOwnEvent = event.extendedProps.created_by && event.extendedProps.created_by == currentUserId;
                                                    const isDue = !!event.extendedProps.is_due;
                                                    const eventId = event.id.replace('event_', '');
                                                    const eventDateOnly = event.start ? event.start.split('T')[0] : '';
                                                    const startTimeRaw = event.extendedProps?.event_time || '';
                                                    const endTimeRaw = event.extendedProps?.end_time || '';
                                                    const eventTimeLabel = formatEventTime(startTimeRaw, endTimeRaw);
                                                    return `
                                                    <div class="event-item p-2 border-bottom">
                                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                                            <div class="flex-grow-1">
                                                                <h6 class="mb-1">${event.title}</h6>
                                                                <div class="text-muted small">
                                                                    ${formatEventType(event.extendedProps.type)}${eventTimeLabel ? `: ${eventTimeLabel}` : ''}
                                                            </div>
                                                                <div class="text-muted small">
                                                                    Created by: ${event.extendedProps.creator_name || '—'}${event.extendedProps.creator_role ? ` (${event.extendedProps.creator_role})` : ''}
                                                                </div>
                                                            </div>
                                                            ${isOwnEvent && !isDue ? `
                                                                <div class="btn-group btn-group-sm align-self-center">
                                                                    <button class="btn btn-primary edit-event" data-event-id="${eventId}" data-event-title="${event.title}" data-event-type="${event.extendedProps?.type || event.extendedProps?.event_type || 'other'}" data-event-date="${eventDateOnly}" data-event-description="${event.extendedProps.description || ''}" data-event-start-time="${startTimeRaw}" data-event-end-time="${endTimeRaw}">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                    <button class="btn btn-danger delete-event" data-event-id="${eventId}">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                                ` : ''}
                                                        </div>
                                                    </div>
                                                `;
                                                }).join('')}
                                            </div>
                                            ${isPastDate ? '' : `
                                            <button class="btn btn-primary w-100" onclick="showAddEventForm('${dateStr}')">
                                                Add New Event
                                            </button>
                                            `}
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            modalEl.innerHTML = modalContent;
                            document.body.appendChild(modalEl);

                            // Initialize Bootstrap modal with backdrop static to prevent body shift
                            const modal = new bootstrap.Modal(modalEl, {
                                backdrop: true,
                                keyboard: true
                            });
                            
                            // Prevent body scroll jump when modal opens
                            modalEl.addEventListener('show.bs.modal', function () {
                                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                                if (scrollbarWidth > 0) {
                                    document.body.style.paddingRight = scrollbarWidth + 'px';
                                }
                            });
                            
                            modalEl.addEventListener('hidden.bs.modal', function () {
                                document.body.style.paddingRight = '';
                            });
                            
                            modal.show();

                            // Add event listeners for delete buttons
                            modalEl.querySelectorAll('.delete-event').forEach(btn => {
                                btn.onclick = function() {
                                        const eventId = this.dataset.eventId;
                                    showDeleteEventModal(eventId, modalEl, modal, calendar);
                                };
                            });
                            
                            // Add event listeners for edit buttons
                            modalEl.querySelectorAll('.edit-event').forEach(btn => {
                                btn.onclick = function() {
                                    const eventId = this.dataset.eventId;
                                    const eventTitle = this.dataset.eventTitle;
                                    const eventType = this.dataset.eventType;
                                    const eventDate = this.dataset.eventDate;
                                    const eventDescription = this.dataset.eventDescription || '';
                                    const startTime = this.dataset.eventStartTime || '';
                                    const endTime = this.dataset.eventEndTime || '';
                                    
                                    modal.hide();
                                    modalEl.remove();
                                    window.showEditEventForm(eventId, eventTitle, eventType, eventDate, eventDescription, startTime, endTime);
                                };
                            });

                            // Remove modal from DOM after it's hidden
                            modalEl.addEventListener('hidden.bs.modal', function () {
                                modalEl.remove();
                            });
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error fetching events. Please try again.');
                        });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Flatpickr for any date-mdY inputs on initial page load
            if (window.flatpickr) {
                document.querySelectorAll('input.date-mdY').forEach(function (input) {
                    flatpickr(input, {
                        dateFormat: "Y-m-d",  // value sent to backend
                        altInput: true,
                        altFormat: "m/d/Y",   // what user sees
                        allowInput: true
                    });
                });
            }

            var calendarEl = document.getElementById('calendar');
            window.calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: currentView,
                /* Match the CSS height so the calendar area stays fixed */
                height: 650,
                headerToolbar: false,
                // Only render as many weeks as the month actually needs,
                // but still show spillover dates from adjacent months
                fixedWeekCount: false,
                showNonCurrentDates: true,
                dayHeaderContent: function(arg) {
                    // For Day view, show "DD - Weekday" (e.g., "26 - Wednesday")
                    if (arg.view && arg.view.type === 'dayGridDay') {
                        const date = arg.date;
                        const day = date.getDate();
                        const weekday = date.toLocaleString('default', { weekday: 'long' });
                        return day + ' - ' + weekday;
                    }
                    // For Week view, show "Weekday - Month Day" (e.g., "Fri - Nov 28")
                    if (arg.view && arg.view.type === 'dayGridWeek') {
                        const date = arg.date;
                        const weekday = date.toLocaleString('default', { weekday: 'short' });
                        const month = date.toLocaleString('default', { month: 'short' });
                        const day = date.getDate();
                        return weekday + ' - ' + month + ' ' + day;
                    }
                    // Use FullCalendar's default text for other views
                    return arg.text;
                },
                eventSources: [
                    {
                        url: '../api/get_events.php',
                        failure: function(error) {
                            console.error('Error fetching events:', error);
                            alert('Error fetching events. Please try again.');
                        }
                    },
                    {
                        // Static Philippine holidays rendered as grey days with labels
                        events: function(fetchInfo, successCallback, failureCallback) {
                            try {
                                const startYear = fetchInfo.start.getFullYear();
                                const endYear = fetchInfo.end.getFullYear();

                                let events = getPhilippineHolidaysForYear(startYear);
                                if (endYear !== startYear) {
                                    events = events.concat(getPhilippineHolidaysForYear(endYear));
                                }

                                successCallback(events);
                            } catch (e) {
                                console.error('Error generating Philippine holiday events:', e);
                                if (failureCallback) {
                                    failureCallback(e);
                                } else {
                                    successCallback([]);
                                }
                            }
                        }
                    }
                ],
                eventContent: renderEventContent,
                eventDidMount: function(info) {
                    const view = info.view;
                    // Hide events that are not in the current month being viewed
                    if (view.type === 'dayGridMonth') {
                        const eventMonth = info.event.start.getMonth();
                        const currentViewMonth = view.currentStart.getMonth();
                        if (eventMonth !== currentViewMonth) {
                            info.el.style.display = 'none';
                        }
                    }
                    
                    // Highlight holiday days
                    if (info.event.extendedProps && info.event.extendedProps.isHoliday) {
                        const dayCell = info.el.closest('.fc-daygrid-day');
                        if (dayCell) {
                            dayCell.classList.add('fc-holiday-day');
                        }
                    }

                    const creatorName = info.event.extendedProps.creator_name;
                    const creatorRole = info.event.extendedProps.creator_role;

                    // Tooltip: "Created by: Full Name (role)"
                    if (creatorName || creatorRole) {
                        let tooltip = 'Created by: ';
                        if (creatorName) {
                            tooltip += creatorName;
                        }
                        if (creatorRole) {
                            tooltip += creatorName ? ` (${creatorRole})` : creatorRole;
                        }
                        info.el.title = tooltip;
                    }
                },
                dateClick: function(info) {
                    openDateEventsModal(info.dateStr);
                },
                eventClick: function(info) {
                    // Do not show details modal for static Philippine holiday background events
                    if (info.event.extendedProps && info.event.extendedProps.isHoliday) {
                        return;
                    }

                    // Show event details using dynamic modal for all event types
                    const modalEl = document.createElement('div');
                    modalEl.className = 'modal fade';
                    modalEl.setAttribute('tabindex', '-1');
                    modalEl.setAttribute('aria-hidden', 'true');
                    
                        const isOwnEvent = info.event.extendedProps.created_by && parseInt(info.event.extendedProps.created_by) === parseInt(currentUserId);
                    const isDue = !!info.event.extendedProps.is_due;
                        const eventId = info.event.id.replace('event_', '');
                        const eventDate = info.event.start.toISOString().split('T')[0];
                        // Get event type - check multiple possible locations
                        let eventType = info.event.extendedProps?.type || info.event.extendedProps?.event_type || null;
                        // Ensure it's a string if it exists
                        if (eventType !== null && eventType !== undefined && eventType !== '') {
                            eventType = String(eventType).trim();
                        } else {
                            eventType = null;
                        }
                        
                    const modalContent = `
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Event Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p><strong>Title:</strong> ${info.event.title}</p>
                                    <p><strong>Date:</strong> ${formatDateLabel(info.event.start)}</p>
                                    <p><strong>Type:</strong> <span class="legend-dot" style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: ${getEventTypeColor(eventType, isDue)}; margin-right: 6px; vertical-align: middle;"></span>${formatEventType(eventType)}</p>
                                        <p><strong>Description:</strong> ${info.event.extendedProps.description || '—'}</p>
                                        ${info.event.extendedProps.creator_name ? `<p><strong>Created by:</strong> ${info.event.extendedProps.creator_name}</p>` : ''}
                                    </div>
                                    <div class="modal-footer">
                                    ${!isDue ? `
                                        <button type="button" class="btn btn-primary edit-event-click" data-event-id="${eventId}" data-event-title="${escapeHtml(info.event.title)}" data-event-type="${eventType || ''}" data-event-date="${eventDate}" data-event-description="${escapeHtml(info.event.extendedProps.description || '')}" data-event-start-time="${info.event.extendedProps.event_time || ''}" data-event-end-time="${info.event.extendedProps.end_time || ''}">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            ${isOwnEvent ? `
                                            <button type="button" class="btn btn-danger delete-event-click" data-event-id="${eventId}">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                            ` : ''}
                                        ` : ''}
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        `;
                    
                    modalEl.innerHTML = modalContent;
                    document.body.appendChild(modalEl);
                    
                    const modal = new bootstrap.Modal(modalEl, {
                        backdrop: true,
                        keyboard: true
                    });
                    
                    // Prevent body scroll jump when modal opens
                    modalEl.addEventListener('show.bs.modal', function () {
                        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                        if (scrollbarWidth > 0) {
                            document.body.style.paddingRight = scrollbarWidth + 'px';
                        }
                    });
                    
                    modalEl.addEventListener('hidden.bs.modal', function () {
                        document.body.style.paddingRight = '';
                    });
                    
                    modal.show();
                    
                    // Add event listeners for edit and delete buttons
                        const editBtn = modalEl.querySelector('.edit-event-click');
                        const deleteBtn = modalEl.querySelector('.delete-event-click');
                        
                        if (editBtn) {
                            editBtn.onclick = function() {
                                const eventId = this.dataset.eventId;
                                const eventTitle = this.dataset.eventTitle;
                                const eventType = this.dataset.eventType;
                                const eventDate = this.dataset.eventDate;
                                const eventDescription = this.dataset.eventDescription || '';
                            const startTime = this.dataset.eventStartTime || '';
                            const endTime = this.dataset.eventEndTime || '';
                                
                                modal.hide();
                                modalEl.remove();
                            window.showEditEventForm(eventId, eventTitle, eventType, eventDate, eventDescription, startTime, endTime);
                            };
                        }
                        
                        if (deleteBtn) {
                            deleteBtn.onclick = function() {
                                    const eventId = this.dataset.eventId;
                            showDeleteEventModal(eventId, modalEl, modal, calendar);
                        };
                    }
                    
                    // Remove modal from DOM after it's hidden
                    modalEl.addEventListener('hidden.bs.modal', function () {
                        modalEl.remove();
                    });
                },
                datesSet: updateCalendarDateLabel
            });
            window.calendar.render();
            window.setCalendarView('dayGridMonth');
            updateCalendarDateLabel();

            // Modal functionality for View List Event
            var viewListEventBtn = document.getElementById('viewListEventBtn');
            var eventListModal = document.getElementById('eventListModal');
            if (viewListEventBtn && eventListModal) {
                viewListEventBtn.onclick = function() {
                    fetch('../api/get_events.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(events => {
                            const now = new Date();

                            // Exclude interment-only entries from list view
                            const allEvents = events.filter(e => e.extendedProps && e.extendedProps.type !== 'interment');

                            const filterChips = eventListModal.querySelectorAll('.filter-chip');
                            const upcomingRangeSelect = eventListModal.querySelector('#upcomingRangeSelect');
                            const overdueRangeSelect = eventListModal.querySelector('#overdueRangeSelect');
                            const todayListEl = eventListModal.querySelector('#todayEventsList');
                            const upcomingListEl = eventListModal.querySelector('#upcomingEventsList');
                            const overdueListEl = eventListModal.querySelector('#overdueEventsList');

                            let activeType = 'all';

                            function matchesType(ev) {
                                if (activeType === 'all') return true;
                                const t = (ev.extendedProps.type || '').toLowerCase();
                                return t === activeType;
                            }

                            function withinUpcomingRange(ev, days) {
                                const d = new Date(ev.start);
                                const diffDays = (d - now) / (1000 * 60 * 60 * 24);
                                return diffDays >= 0 && diffDays <= days;
                            }

                            function withinOverdueRange(ev, days) {
                                const d = new Date(ev.start);
                                const diffDays = (now - d) / (1000 * 60 * 60 * 24);
                                return diffDays >= 0 && diffDays <= days;
                            }

                            function isSameDay(a, b) {
                                return a.getFullYear() === b.getFullYear() &&
                                       a.getMonth() === b.getMonth() &&
                                       a.getDate() === b.getDate();
                            }

                            function renderLists() {
                                const upcomingDays = parseInt(upcomingRangeSelect.value, 10) || 3;
                                const overdueDays = parseInt(overdueRangeSelect.value, 10) || 3;

                                // Today's events (not overdue, same calendar day)
                                let todayEvents = allEvents.filter(ev => {
                                    const d = new Date(ev.start);
                                    return !ev.extendedProps.is_due &&
                                           matchesType(ev) &&
                                           isSameDay(d, now);
                                });

                                // Upcoming events (future, not today, not overdue)
                                let upcoming = allEvents.filter(ev =>
                                    !ev.extendedProps.is_due &&
                                    matchesType(ev) &&
                                    !isSameDay(new Date(ev.start), now) &&
                                    withinUpcomingRange(ev, upcomingDays)
                                );

                                // Overdue events (past and marked is_due)
                                let overdue = allEvents.filter(ev =>
                                    ev.extendedProps.is_due &&
                                    matchesType(ev) &&
                                    withinOverdueRange(ev, overdueDays)
                                );

                                // Sort today's and upcoming events from nearest to farthest future date/time
                                todayEvents.sort((a, b) => new Date(a.start) - new Date(b.start));
                                upcoming.sort((a, b) => new Date(a.start) - new Date(b.start));

                                // Sort overdue events from most recently past to farthest past
                                overdue.sort((a, b) => new Date(b.start) - new Date(a.start));

                                function renderList(list, el, emptyMsg) {
                                    if (!list.length) {
                                        el.innerHTML = '<div class="events-list-empty">' + emptyMsg + '</div>';
                                        return;
                                    }
                                    el.innerHTML = list.map(ev => {
                                        const dateLabel = formatDateLabel(ev.start);
                                        const startTime = ev.extendedProps?.event_time || '';
                                        const endTime = ev.extendedProps?.end_time || '';
                                        const timeLabel = formatEventTime(startTime, endTime);
                                        const typeLabel = formatEventType(ev.extendedProps.type);
                                        return `
                                            <div class="events-list-item">
                                                <div class="events-list-item-title">${ev.title}</div>
                                                <div class="events-list-item-meta">
                                                    ${dateLabel}${timeLabel ? ' • ' + timeLabel : ''} • ${typeLabel}
                                        </div>
                                    </div>
                                        `;
                                    }).join('');
                            }

                                renderList(todayEvents, todayListEl, 'No events scheduled for today.');
                                renderList(upcoming, upcomingListEl, 'No upcoming events for the selected range.');
                                renderList(overdue, overdueListEl, 'No overdue events for the selected range.');
                            }

                            // Wire up type chips
                            filterChips.forEach(chip => {
                                chip.onclick = function() {
                                    filterChips.forEach(c => c.classList.remove('active'));
                                    this.classList.add('active');
                                    activeType = this.dataset.type || 'all';
                                    renderLists();
                                };
                            });

                            // Wire up range dropdowns
                            upcomingRangeSelect.onchange = renderLists;
                            overdueRangeSelect.onchange = renderLists;

                            // Initial render
                            renderLists();
                            
                            eventListModal.classList.add('active');
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error fetching events. Please try again.');
                        });
                };
            }

            function closeEventListModal() {
                eventListModal.classList.remove('active');
            }
            window.closeEventListModal = closeEventListModal;

            // Optional: close modal when clicking outside content
            if (eventListModal) {
                eventListModal.addEventListener('click', function(e) {
                    if (e.target === this) closeEventListModal();
                });
            }


            // Add Event Modal functionality
            var addEventBtn = document.getElementById('addEventBtn');
            var addEventModal = document.getElementById('addEventModal');
            var addEventForm = document.getElementById('addEventForm');
            var addEventMessage = document.getElementById('addEventMessage');

            if (addEventBtn && addEventModal) {
                addEventBtn.onclick = function() {
                    addEventModal.classList.add('active');
                };
            }

            function closeAddEventModal() {
                addEventModal.classList.remove('active');
            }
            window.closeAddEventModal = closeAddEventModal;

            if (addEventForm) {
                addEventForm.onsubmit = function(e) {
                    e.preventDefault();
                    const formData = new FormData(addEventForm);

                    if (addEventMessage) {
                        addEventMessage.classList.add('d-none');
                        addEventMessage.classList.remove('alert-success', 'alert-danger');
                        addEventMessage.textContent = '';
                    }
                    
                    fetch('add_event.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success notification
                            showEventNotification('Event Added Successfully.', 'success');
                            
                            calendar.refetchEvents();
                            addEventForm.reset();
                            setTimeout(() => {
                                closeAddEventModal();
                            }, 500);
                        } else {
                            showEventNotification(data.message || 'Error adding event.', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showEventNotification('Error adding event. Please try again.', 'error');
                    });
                };
            }

            // Optional: close modal when clicking outside content
            if (addEventModal) {
                addEventModal.addEventListener('click', function(e) {
                    if (e.target === this) closeAddEventModal();
                });
            }
        });

        // Function to show edit event form
        window.showEditEventForm = function(eventId, eventTitle, eventType, eventDate, eventDescription, startTime = '', endTime = '') {
            const normalizedStartTime = startTime ? startTime.substring(0,5) : '';
            const normalizedEndTime = endTime ? endTime.substring(0,5) : '';
            // Normalize event type to lowercase for comparison
            const normalizedEventType = eventType ? String(eventType).toLowerCase().trim() : 'other';
            const modalEl = document.createElement('div');
            modalEl.className = 'modal fade';
            modalEl.id = 'editEventModal';
            modalEl.setAttribute('tabindex', '-1');
            modalEl.setAttribute('aria-hidden', 'true');

            let modalContent = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Event</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="editEventForm">
                                <input type="hidden" name="event_id" value="${eventId}">
                                <div class="mb-3">
                                    <label for="editEventTitle" class="form-label">Event Title</label>
                                    <input type="text" class="form-control" id="editEventTitle" name="title" value="${eventTitle}" required>
                                </div>
                                <div class="mb-3">
                                    <label for="editEventType" class="form-label">Event Type</label>
                                    <select class="form-control" id="editEventType" name="type" required>
                                        <option value="burial" ${normalizedEventType === 'burial' ? 'selected' : ''}>Burial</option>
                                        <option value="maintenance" ${normalizedEventType === 'maintenance' ? 'selected' : ''}>Maintenance</option>
                                        <option value="funeral" ${normalizedEventType === 'funeral' ? 'selected' : ''}>Funeral Service</option>
                                        <option value="chapel" ${normalizedEventType === 'chapel' ? 'selected' : ''}>Chapel Booking</option>
                                        <option value="appointment" ${normalizedEventType === 'appointment' ? 'selected' : ''}>Meetings/Appointments</option>
                                        <option value="holiday" ${normalizedEventType === 'holiday' ? 'selected' : ''}>Public Events/Holidays</option>
                                        <option value="exhumation" ${normalizedEventType === 'exhumation' ? 'selected' : ''}>Exhumation</option>
                                        <option value="cremation" ${normalizedEventType === 'cremation' ? 'selected' : ''}>Cremation</option>
                                        <option value="other" ${normalizedEventType === 'other' || !normalizedEventType ? 'selected' : ''}>Other</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editEventDate" class="form-label">Event Date</label>
                                    <input type="date" class="form-control" id="editEventDate" name="date" value="${eventDate}" min="" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Event Time</label>
                                    <div class="row">
                                        <div class="col-6">
                                            <label for="editEventStartTime" class="form-label small">Start Time</label>
                                            <input type="time" class="form-control" id="editEventStartTime" name="start_time" value="${normalizedStartTime}" required>
                                        </div>
                                        <div class="col-6">
                                            <label for="editEventEndTime" class="form-label small">End Time</label>
                                            <input type="time" class="form-control" id="editEventEndTime" name="end_time" value="${normalizedEndTime}" required>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">Set the start and end time for this event.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="editEventDescription" class="form-label">Description</label>
                                    <textarea class="form-control" id="editEventDescription" name="description" rows="3">${eventDescription}</textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Update Event</button>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            modalEl.innerHTML = modalContent;
            document.body.appendChild(modalEl);

            // Initialize Bootstrap modal
            const modal = new bootstrap.Modal(modalEl, {
                backdrop: true,
                keyboard: true
            });
            
            // Prevent body scroll jump when modal opens
            modalEl.addEventListener('show.bs.modal', function () {
                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                if (scrollbarWidth > 0) {
                    document.body.style.paddingRight = scrollbarWidth + 'px';
                }
            });
            
            modalEl.addEventListener('hidden.bs.modal', function () {
                document.body.style.paddingRight = '';
            });
            
            modal.show();

            // Set minimum date to today to prevent selecting past dates
            const today = new Date();
            const todayFormatted = today.toISOString().split('T')[0];
            const dateInput = modalEl.querySelector('#editEventDate');
            if (dateInput) {
                dateInput.setAttribute('min', todayFormatted);
            }

            // Handle form submission
            const form = modalEl.querySelector('#editEventForm');
            form.onsubmit = function(e) {
                e.preventDefault();
                const formData = new FormData(form);
                
                fetch('edit_event.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        calendar.refetchEvents();
                        
                        // Show notification before closing modal
                        showEventNotification('Event Updated.', 'success');
                        
                        // Close modal after a brief delay to ensure notification is visible
                        setTimeout(() => {
                        modal.hide();
                        modalEl.remove();
                        }, 100);
                    } else {
                        showEventNotification('Error updating event: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showEventNotification('Error updating event. Please try again.', 'error');
                });
            };

            // Remove modal from DOM after it's hidden
            modalEl.addEventListener('hidden.bs.modal', function () {
                modalEl.remove();
            });
        };

        // Open the same Add Event modal used by the "+" button.
        // When called from a date click, we also pre-fill the date picker.
        function showAddEventForm(date) {
            // Prevent adding events on past dates
            if (date) {
                const selectedDate = new Date(date + 'T00:00:00');
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (selectedDate < today) {
                    showEventNotification('You cannot add events to past dates.', 'error');
                    return;
                }
            }

            const openExistingAddEventModal = () => {
                const addEventModal = document.getElementById('addEventModal');
                if (!addEventModal) return;

                // Clear any inline message state (we use toast notifications elsewhere)
                const addEventMessage = document.getElementById('addEventMessage');
                if (addEventMessage) {
                    addEventMessage.classList.add('d-none');
                    addEventMessage.classList.remove('alert-success', 'alert-danger');
                    addEventMessage.textContent = '';
                }

                // Pre-fill the date and keep Flatpickr in sync (if initialized)
                if (date) {
                    const dateInput = document.getElementById('eventDate');
                    if (dateInput) {
                        if (dateInput._flatpickr) {
                            dateInput._flatpickr.setDate(date, true);
                        } else {
                            dateInput.value = date;
                        }
                    }
                }

                addEventModal.classList.add('active');
            };

            // If the Bootstrap "events for this date" modal is open, close it cleanly first
            const existingDateModalEl = document.getElementById('dateEventsModal');
            if (existingDateModalEl) {
                const existingModal = bootstrap.Modal.getInstance(existingDateModalEl);
                if (existingModal) {
                    existingDateModalEl.addEventListener('hidden.bs.modal', function () {
                        existingDateModalEl.remove();
                        openExistingAddEventModal();
                    }, { once: true });
                    existingModal.hide();
                    return;
                }
                existingDateModalEl.remove();
            }

            openExistingAddEventModal();
        }
    </script>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="calendar-header">Interment Calendar</div>
        <div class="calendar-wrapper">
            <div class="calendar-actions">
                <button class="dashboard-box-btn interment-action-btn" id="viewListEventBtn" style="background: #e0eaff; color: #2b4c7e; border: none; border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500; cursor: pointer;">View List Event</button>
                <button class="calendar-add-btn" id="addEventBtn" aria-label="Add Event">+</button>
        </div>
        <div class="calendar-tabs">
            <button class="calendar-tab" id="tab-dayGridWeek" onclick="setCalendarView('dayGridWeek')">Week</button>
            <button class="calendar-tab active" id="tab-dayGridMonth" onclick="setCalendarView('dayGridMonth')">Month</button>
            <button class="calendar-tab" id="tab-dayGridDay" onclick="setCalendarView('dayGridDay')">Day</button>
        </div>
        <div class="calendar-controls">
            <button class="calendar-arrow" id="calendar-prev" onclick="calendarPrevMonth()">&#8592;</button>
            <div class="calendar-date-label" id="calendar-date-label"></div>
            <button class="calendar-arrow" id="calendar-next" onclick="calendarNextMonth()">&#8594;</button>
        </div>
        <div id="calendar"></div>
            <div class="calendar-legend">
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #000000;"></span>
                    <span>Burial</span>
                        </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #198754;"></span>
                    <span>Maintenance</span>
                            </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #ffc107;"></span>
                    <span>Funeral</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #fd7e14;"></span>
                    <span>Chapel</span>
            </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #2b4c7e;"></span>
                    <span>Appointment</span>
                        </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #6f42c1;"></span>
                    <span>Holiday</span>
                            </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #8b4513;"></span>
                    <span>Exhumation</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #800000;"></span>
                    <span>Cremation</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #6c757d;"></span>
                    <span>Other</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background-color: #dc3545;"></span>
                    <span>Expired/Overdue</span>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal for List Events -->
<div class="modal-bg" id="eventListModal">
    <div class="modal-content modal-events">
        <div class="modal-events-header">
            <div>
                <div class="modal-title">Event List</div>
                <div class="modal-subtitle">Browse schedules with type and date filters.</div>
            </div>
        <button class="modal-close" onclick="closeEventListModal()">&times;</button>
        </div>
        <div class="modal-events-body">
            <div class="modal-filter-panel">
                <div class="filter-title">Event Types</div>
                <div class="filter-chips">
                    <button class="filter-chip active" data-type="all">All</button>
                    <button class="filter-chip" data-type="burial">Burial</button>
                    <button class="filter-chip" data-type="maintenance">Maintenance</button>
                    <button class="filter-chip" data-type="funeral">Funeral</button>
                    <button class="filter-chip" data-type="chapel">Chapel</button>
                    <button class="filter-chip" data-type="appointment">Meeting / Appointment</button>
                    <button class="filter-chip" data-type="holiday">Public Events / Holiday</button>
                    <button class="filter-chip" data-type="exhumation">Exhumation</button>
                    <button class="filter-chip" data-type="cremation">Cremation</button>
                    <button class="filter-chip" data-type="other">Other</button>
                </div>
            </div>
            <div class="modal-events-main">
                <div class="events-section">
                        <span>Today's Schedule</span>
                    <div class="events-list events-list--today" id="todayEventsList">
                        <!-- Today's events rendered here -->
                    </div>
                </div>
                <div class="events-section">
                    <div class="events-section-header">
                        <span>Upcoming Events</span>
                        <select id="upcomingRangeSelect" class="form-select form-select-sm events-range-select">
                            <option value="3">Next 3 days</option>
                            <option value="7">Next week</option>
                            <option value="30">Next month</option>
                            <option value="90">Next 3 months</option>
                            <option value="180">Next 6 months</option>
                            <option value="365">Next year</option>
                        </select>
                    </div>
                    <div class="events-list events-list--stacked" id="upcomingEventsList">
                        <!-- Upcoming events rendered here -->
                    </div>
                </div>
                <div class="events-section">
                    <div class="events-section-header">
                        <span>Overdue Events</span>
                        <select id="overdueRangeSelect" class="form-select form-select-sm events-range-select">
                            <option value="3">Past 3 days</option>
                            <option value="7">Past week</option>
                            <option value="30">Past month</option>
                            <option value="90">Past 3 months</option>
                            <option value="180">Past 6 months</option>
                            <option value="365">Past year</option>
                        </select>
                    </div>
                    <div class="events-list events-list--stacked" id="overdueEventsList">
                        <!-- Overdue events rendered here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Add Event Modal -->
<div class="modal-bg" id="addEventModal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeAddEventModal()">&times;</button>
        <div class="modal-title">Add New Event</div>
        <form id="addEventForm" class="mt-3">
            <div id="addEventMessage" class="alert d-none mb-3" role="alert"></div>
            <div class="form-group mb-3">
                <label for="eventTitle">Event Title</label>
                <input type="text" class="form-control" id="eventTitle" name="title" required>
            </div>
            <div class="form-group mb-3">
                <label for="eventType">Event Type</label>
                <select class="form-control" id="eventType" name="type" required>
                    <option value="burial">Burial</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="funeral">Funeral</option>
                    <option value="chapel">Chapel</option>
                    <option value="appointment">Appointment</option>
                    <option value="exhumation">Exhumation</option>
                    <option value="holiday">Holiday</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group mb-3">
                <label for="eventDate">Event Date</label>
                <input type="text" class="form-control date-mdY" id="eventDate" name="date" required placeholder="mm/dd/yyyy">
            </div>
            <div class="form-group mb-3">
                <label>Event Time</label>
                <div class="row">
                    <div class="col-6">
                        <label for="eventStartTime" class="small">Start Time</label>
                        <input type="time" class="form-control" id="eventStartTime" name="start_time" required>
                    </div>
                    <div class="col-6">
                        <label for="eventEndTime" class="small">End Time</label>
                        <input type="time" class="form-control" id="eventEndTime" name="end_time" required>
                    </div>
                </div>
                <small class="form-text text-muted">Set the start and end time for this event.</small>
            </div>
            <div class="form-group mb-3">
                <label for="eventDescription">Description</label>
                <textarea class="form-control" id="eventDescription" name="description" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Add Event</button>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="../assets/js/ui-settings.js"></script>
<script>
    // Function to show delete event confirmation modal
    function showDeleteEventModal(eventId, currentModalEl, currentModal, calendarInstance) {
        // Remove any existing delete modal first
        const existingModal = document.getElementById('deleteEventModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        const modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'deleteEventModal';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-labelledby', 'deleteEventModalLabel');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.style.zIndex = '1060'; // Higher than event details modal (usually 1055)

        modalEl.innerHTML = `
            <div class="modal-dialog" style="z-index: 1061; margin-top: 80px; max-width: 400px;">
                <div class="modal-content" style="z-index: 1061;">
                    <div class="modal-body" style="padding: 24px;">
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 10px;">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class='bx bx-trash' style="font-size: 24px; color: #dc3545;"></i>
                            </div>
                            <div style="flex: 1;">
                                <p style="margin: 0; font-size: 16px; color: #212529; font-weight: 500;">Delete this event?</p>
                                <p style="margin: 8px 0 0 0; font-size: 14px; color: #6c757d;">This action cannot be undone.</p>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px; cursor: pointer; z-index: 1062; position: relative;">Cancel</button>
                            <button type="button" class="btn btn-danger delete-confirm-btn" style="padding: 8px 16px; border-radius: 8px; font-weight: 500; font-size: 14px; background-color: #dc3545; border-color: #dc3545; color: #fff; cursor: pointer; z-index: 1062; position: relative;">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const deleteModal = new bootstrap.Modal(modalEl, {
            backdrop: true,
            keyboard: true
        });
        
        // Prevent body scroll jump when modal opens
        modalEl.addEventListener('show.bs.modal', function () {
            const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
            if (scrollbarWidth > 0) {
                document.body.style.paddingRight = scrollbarWidth + 'px';
            }
        });
        
        modalEl.addEventListener('hidden.bs.modal', function () {
            document.body.style.paddingRight = '';
        });
        
        deleteModal.show();

        // Handle delete confirmation
        modalEl.querySelector('.delete-confirm-btn').onclick = function() {
            // Extract numeric ID if it's in format like "event_123"
            let numericId = eventId;
            if (eventId && eventId.includes('_')) {
                numericId = eventId.split('_').pop();
            }
            
            fetch('delete_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ event_id: numericId })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Show success notification
                    showEventNotification('Event Deleted.', 'success');
                    
                    if (calendarInstance) {
                        calendarInstance.refetchEvents();
                    } else if (window.calendar) {
                        window.calendar.refetchEvents();
                    }
                    deleteModal.hide();
                    modalEl.remove();
                    if (currentModal && currentModalEl) {
                        currentModal.hide();
                        currentModalEl.remove();
                    }
                } else {
                    showEventNotification('Error deleting event: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showEventNotification('Error deleting event. Please try again.', 'error');
            });
        };

        // Clean up when modal is hidden
        modalEl.addEventListener('hidden.bs.modal', function() {
            modalEl.remove();
        });
    }
    
    // Function to show event notification
    function showEventNotification(message, type) {
        // Remove any existing notification
        const existingNotification = document.getElementById('eventNotification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        const notification = document.createElement('div');
        notification.id = 'eventNotification';
        notification.className = `notification-bubble ${type}-notification`;
        notification.innerHTML = `
            <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(notification);
        
        // Show notification
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Hide notification after 4 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            notification.classList.add('hide');
            setTimeout(() => {
                notification.remove();
            }, 250);
        }, 4000);
    }
</script>
</body>
</html>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <?php include '../admin/includes/styles.php'; ?>
    <style>
        .calendar-header {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: 1px;
        }
        .calendar-wrapper {
            position: relative;
        }
        .calendar-actions {
            position: absolute;
            top: 16px;
            left: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            /* Keep calendar controls fully clickable.
               Some FullCalendar elements (and/or tabs area) can overlap this region;
               use a higher stacking level so the whole button surface receives clicks. */
            z-index: 50;
            pointer-events: none;
        }
        .calendar-actions > * {
            pointer-events: auto;
        }
        .calendar-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            margin-bottom: 24px;
        }
        .calendar-arrow {
            background: #e0e0e0;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #444;
            cursor: pointer;
            transition: background 0.2s;
        }
        .calendar-arrow:hover {
            background: #d0d0d0;
        }
        .calendar-date-label {
            text-align: center;
            font-size: 1.2rem;
            color: #444;
            font-weight: 700;
            min-width: 120px;
        }
        .calendar-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
            justify-content: center;
            position: relative;
            z-index: 10;
        }
        .calendar-tab {
            background: #e0e0e0;
            border: none;
            border-radius: 20px;
            padding: 8px 32px;
            font-size: 16px;
            color: #222;
            cursor: pointer;
            outline: none;
            transition: background 0.2s, color 0.2s;
            position: relative;
            z-index: 11;
            pointer-events: auto;
        }
        .calendar-tab.active {
            background: #fff;
            color: #2b4c7e;
            border: 2px solid #2b4c7e;
        }
        #calendar { 
            width: 100%;
            /* Match the fixed height used above so layout is consistent on all breakpoints */
            height: 650px;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .calendar-add-btn {
            position: relative;
            z-index: 51;
            pointer-events: auto;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            background: #2b4c7e;
            color: #fff;
            font-size: 30px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(43, 76, 126, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .calendar-add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(43, 76, 126, 0.4);
        }
        .calendar-add-btn:focus {
            outline: 3px solid rgba(43, 76, 126, 0.5);
            outline-offset: 4px;
        }
        
        /* Responsive header sizes for larger screens */
        @media (min-width: 1400px) {
            .calendar-header {
                font-size: 1.75rem;
            }
        }
        
        @media (min-width: 1600px) {
            .calendar-header {
                font-size: 2rem;
            }
        }
        
        @media (min-width: 1920px) {
            .calendar-header {
                font-size: 2.25rem;
            }
        }
        
        @media (max-width: 1100px) {
            .calendar-header {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 900px) {
            .calendar-header {
                font-size: 1.25rem;
            }
            .calendar-wrapper {
                padding-right: 0;
            }
            .calendar-add-btn {
                position: static;
                margin-left: auto;
                margin-bottom: 16px;
            }
        }
        
        @media (max-width: 768px) {
            .calendar-header {
                font-size: 1.1rem;
                margin-bottom: 16px;
            }
        }
        
        @media (max-width: 700px) {
            .calendar-header {
                font-size: 0.55rem;
            }
        }
        
        @media (max-width: 576px) {
            .calendar-header {
                font-size: 0.9rem;
            }
        }
        /* Add cursor styles for better accessibility */
        .fc .fc-daygrid-day {
            cursor: pointer;
        }
        .fc .fc-daygrid-day:hover {
            cursor: pointer;
        }
        .fc .fc-daygrid-day.fc-day-today {
            cursor: pointer;
        }
        .fc .fc-daygrid-day-number, 
        .fc .fc-daygrid-day-number a {
            cursor: pointer;
        }
        .fc .fc-daygrid-day.fc-day-other .fc-daygrid-day-number, 
        .fc .fc-daygrid-day.fc-day-other .fc-daygrid-day-number a {
            cursor: not-allowed;
        }
        .fc .fc-event {
            cursor: pointer;
        }
        .fc-custom-event {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .fc-event-time-label {
            font-weight: 600;
            font-size: 13px;
        }
        .fc-event-title-label {
            font-size: 13px;
        }
        /* Responsive Design - Calendar Specific */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            #calendar {
                height: 500px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            #calendar {
                height: 400px;
                padding: 10px;
            }
        }
    </style>
    <style>
    /* Force all calendar text to black in staff calendar */
    #calendar, #calendar *,
    #calendar .fc-col-header-cell-cushion,
    #calendar .fc-col-header-cell a,
    #calendar .fc-col-header-cell a:visited,
    #calendar .fc-col-header-cell a:active,
    #calendar .fc-col-header-cell a:hover,
    #calendar .fc-daygrid-day-number,
    #calendar .fc-daygrid-day-number a,
    #calendar .fc-daygrid-day-number a:visited,
    #calendar .fc-daygrid-day-number a:active,
    #calendar .fc-daygrid-day-number a:hover {
        color: #111 !important;
        text-shadow: 0 0 0 #111 !important;
    }
    </style>
    <script>
        let currentView = 'dayGridMonth';
        function setCalendarView(view) {
            currentView = view;
            var calendar = window.calendar;
            if (calendar) calendar.changeView(view);
            document.querySelectorAll('.calendar-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById('tab-' + view).classList.add('active');
        }
        function updateCalendarDateLabel() {
            var calendar = window.calendar;
            if (calendar) {
                var view = calendar.view;
                var date = view.currentStart;
                var month = date.toLocaleString('default', { month: 'long' });
                var year = date.getFullYear();
                document.getElementById('calendar-date-label').textContent = month + ' ' + year;
            }
        }
        function calendarPrevMonth() {
            var calendar = window.calendar;
            if (calendar) {
                calendar.prev();
            }
        }