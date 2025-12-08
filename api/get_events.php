<?php
require_once '../config/database.php';
header('Content-Type: application/json');

try {
    $events = [];
    $date_filter = isset($_GET['date']) ? $_GET['date'] : null;

    // Get interments
    $query = "SELECT d.*, p.section, p.row_number, p.plot_number 
              FROM deceased d 
              JOIN plots p ON d.plot_id = p.plot_id 
              WHERE d.date_of_burial IS NOT NULL";
    
    if ($date_filter) {
        $date_filter = mysqli_real_escape_string($conn, $date_filter);
        $query .= " AND DATE(d.date_of_burial) = '$date_filter'";
    }
    
    $query .= " ORDER BY d.date_of_burial DESC";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new Exception("Error fetching interments: " . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $plot_location = $row['section'] . '-' . $row['row_number'] . '-' . $row['plot_number'];
        $events[] = [
            'id' => 'interment_' . $row['deceased_id'],
            'title' => $row['first_name'] . ' ' . $row['last_name'],
            'start' => $row['date_of_burial'],
            'allDay' => true,
            'color' => '#dc3545', // Red color for interment dates
            'extendedProps' => [
                'plot_id' => $row['plot_id'],
                'plot_location' => $plot_location,
                'section' => $row['section'],
                'date_of_death' => $row['date_of_death'],
                'type' => 'interment'
            ]
        ];
    }

    // Get other events - explicitly select type column to ensure it's included
    $query = "SELECT e.event_id, e.title, e.type, e.event_date, e.event_time, e.end_time, e.description, e.created_by, e.created_at, u.role as creator_role, u.full_name as creator_name 
              FROM events e 
              LEFT JOIN users u ON e.created_by = u.user_id";
    if ($date_filter) {
        $query .= " WHERE DATE(e.event_date) = '$date_filter'";
    }
    $query .= " ORDER BY e.event_date DESC";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new Exception("Error fetching events: " . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($result)) {
        // Get the type from database - explicitly check and ensure it's returned
        // The type column should always have a value for new events
        $type = null;
        if (isset($row['type'])) {
            $type = $row['type'];
            // Trim whitespace and ensure it's not an empty string
            if ($type !== null && $type !== '') {
                $type = trim($type);
            } else {
                $type = null;
            }
        }
        // We no longer use background colors for event type; keep colors neutral
        $color = null;
        
        // Check if event is due (expired). 
        // If it has a time, compare full datetime; otherwise compare date only.
        $is_due = false;
        $event_time = isset($row['event_time']) ? $row['event_time'] : null;
        if ($event_time) {
            $event_datetime = $row['event_date'] . ' ' . $event_time;
            $current_datetime = date('Y-m-d H:i:s');
            if (strtotime($event_datetime) < strtotime($current_datetime)) {
                $is_due = true;
            }
        } else {
            $event_date_only = $row['event_date'];
            $today_date_only = date('Y-m-d');
            if (strtotime($event_date_only) < strtotime($today_date_only)) {
                $is_due = true;
            }
        }
        
        // Format title with time if available
        $event_title = $row['title'];
        
        // Combine date and time for start
        $start_datetime = $row['event_date'];
        if ($event_time) {
            $start_datetime = $row['event_date'] . 'T' . $event_time;
        }
        
        $end_time = isset($row['end_time']) ? $row['end_time'] : null;
        $time_display = null;
        if ($event_time) {
            if ($end_time) {
                $time_display = date('g:i A', strtotime($event_time)) . ' - ' . date('g:i A', strtotime($end_time));
            } else {
                $time_display = date('g:i A', strtotime($event_time));
            }
        }
        
        $events[] = [
            'id' => 'event_' . $row['event_id'],
            'title' => $event_title,
            'start' => $start_datetime,
            'allDay' => empty($event_time),
            'extendedProps' => [
                'description' => $row['description'],
                'type' => $type,
                'event_time' => $event_time,
                'end_time' => $end_time,
                'event_time_formatted' => $time_display,
                'is_due' => $is_due,
                'created_by' => $row['created_by'],
                'creator_role' => $row['creator_role'],
                'creator_name' => $row['creator_name'],
                'created_at' => $row['created_at']
            ]
        ];
    }

    echo json_encode($events);

} catch (Exception $e) {
    error_log("Calendar Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}
?> 