<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';
require_once 'includes/logging.php';

$message = '';
$error = '';

// Handle plot price update
if (isset($_POST['update_plot_prices'])) {
    // Check if price columns exist in settings table
    $check_niche_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'niche_price'");
    $check_lawn_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'lawn_price'");
    $check_mausoleum_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'mausoleum_price'");

    if (
        !$check_niche_price || mysqli_num_rows($check_niche_price) === 0 ||
        !$check_lawn_price || mysqli_num_rows($check_lawn_price) === 0 ||
        !$check_mausoleum_price || mysqli_num_rows($check_mausoleum_price) === 0
    ) {
        $_SESSION['error'] = 'Plot price columns do not exist in the settings table. Please run the necessary database migration.';
        log_action('Warning', 'Failed to update plot prices: settings table columns missing', $_SESSION['user_id'] ?? null);
        header('Location: reports.php');
        exit();
    } else {
        // Normalize price inputs (strip non-numeric except decimal point)
        $sanitize_price = function ($value) {
            $clean = preg_replace('/[^\d.]/', '', $value ?? '');
            return $clean === '' ? 0 : (float)$clean;
        };

        $niche_price = $sanitize_price($_POST['niche_price'] ?? '');
        $lawn_price = $sanitize_price($_POST['lawn_price'] ?? '');
        $mausoleum_price = $sanitize_price($_POST['mausoleum_price'] ?? '');

        $sql = sprintf(
            "UPDATE settings SET niche_price = %f, lawn_price = %f, mausoleum_price = %f WHERE id = 1",
            $niche_price,
            $lawn_price,
            $mausoleum_price
        );

        if (mysqli_query($conn, $sql)) {
            $_SESSION['message'] = 'Plot prices updated successfully.';
            log_action('Info', 'Plot prices updated from Reports & Analytics page', $_SESSION['user_id'] ?? null);
            header('Location: reports.php');
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update plot prices: ' . mysqli_error($conn);
            log_action('Error', 'Failed to update plot prices: ' . mysqli_error($conn), $_SESSION['user_id'] ?? null);
            header('Location: reports.php');
            exit();
        }
    }
}

// Get messages from session (if redirected from form submission)
$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';

// Clear session messages after displaying
unset($_SESSION['message']);
unset($_SESSION['error']);

// Get current year for annual trends
$current_year = date('Y');
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Get annual burial trends (monthly data for selected year)
$burial_trends = [];
for ($month = 1; $month <= 12; $month++) {
    $month_start = sprintf('%04d-%02d-01', $selected_year, $month);
    $month_end = sprintf('%04d-%02d-28', $selected_year, $month);
    $query = "SELECT COUNT(*) as count FROM deceased 
              WHERE date_of_burial >= '$month_start' AND date_of_burial <= '$month_end'";
$result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    $burial_trends[] = (int)$row['count'];
}

// Get plot utilization data
$plot_stats_query = "SELECT status, COUNT(*) as count FROM plots GROUP BY status";
$plot_stats_result = mysqli_query($conn, $plot_stats_query);
$plot_utilization = ['available' => 0, 'reserved' => 0, 'occupied' => 0];
while ($row = mysqli_fetch_assoc($plot_stats_result)) {
    $plot_utilization[$row['status']] = (int)$row['count'];
}

// Get plots by section
$plots_by_section_query = "SELECT section, status, COUNT(*) as count 
                          FROM plots 
                          WHERE section IS NOT NULL AND section != ''
                          GROUP BY section, status
                          ORDER BY section, status";
$plots_by_section_result = mysqli_query($conn, $plots_by_section_query);
$section_data = [];
while ($row = mysqli_fetch_assoc($plots_by_section_result)) {
    if (!isset($section_data[$row['section']])) {
        $section_data[$row['section']] = ['available' => 0, 'reserved' => 0, 'occupied' => 0];
    }
    $section_data[$row['section']][$row['status']] = (int)$row['count'];
}

// Get date range for burial history (default: last 30 days)
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? mysqli_real_escape_string($conn, $_GET['date_to']) : date('Y-m-d');

// Get burial history by date range
$burial_history_query = "SELECT d.*, p.section, p.row_number, p.plot_number 
                        FROM deceased d 
                        JOIN plots p ON d.plot_id = p.plot_id 
                        WHERE d.date_of_burial >= '$date_from' AND d.date_of_burial <= '$date_to'
                        ORDER BY d.date_of_burial DESC";
$burial_history_result = mysqli_query($conn, $burial_history_query);
$burial_history = [];
while ($row = mysqli_fetch_assoc($burial_history_result)) {
    $burial_history[] = $row;
}

// Get upcoming scheduled funerals (from events table)
$upcoming_funerals_query = "SELECT e.event_id, e.title, e.type, e.event_date, e.event_time, e.description, u.full_name as created_by_name
                            FROM events e
                            LEFT JOIN users u ON e.created_by = u.user_id
                            WHERE e.type = 'funeral' 
                            AND e.event_date >= CURDATE()
                            ORDER BY e.event_date ASC, e.event_time ASC
                            LIMIT 20";
$upcoming_funerals_result = mysqli_query($conn, $upcoming_funerals_query);
$upcoming_funerals = [];
while ($row = mysqli_fetch_assoc($upcoming_funerals_result)) {
    $upcoming_funerals[] = $row;
}

// Get past scheduled funerals
$past_funerals_query = "SELECT e.event_id, e.title, e.type, e.event_date, e.event_time, e.description, u.full_name as created_by_name
                       FROM events e
                       LEFT JOIN users u ON e.created_by = u.user_id
                       WHERE e.type = 'funeral' 
                       AND e.event_date < CURDATE()
                       ORDER BY e.event_date DESC, e.event_time DESC
                       LIMIT 20";
$past_funerals_result = mysqli_query($conn, $past_funerals_query);
$past_funerals = [];
while ($row = mysqli_fetch_assoc($past_funerals_result)) {
    $past_funerals[] = $row;
}

// Get reserved vs purchased plots
$reserved_plots_query = "SELECT COUNT(*) as count FROM plots WHERE status = 'reserved'";
$reserved_result = mysqli_query($conn, $reserved_plots_query);
$reserved_count = mysqli_fetch_assoc($reserved_result)['count'];

$occupied_plots_query = "SELECT COUNT(*) as count FROM plots WHERE status = 'occupied'";
$occupied_result = mysqli_query($conn, $occupied_plots_query);
$occupied_count = mysqli_fetch_assoc($occupied_result)['count'];

// Get plot prices from settings table (if columns exist)
$plot_prices = [
    'niche' => '₱0.00',
    'lawn' => '₱0.00',
    'mausoleum' => '₱0.00'
];

$plot_raw_prices = [
    'niche' => '',
    'lawn' => '',
    'mausoleum' => ''
];

// Check if price columns exist in settings table
$check_niche_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'niche_price'");
$check_lawn_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'lawn_price'");
$check_mausoleum_price = mysqli_query($conn, "SHOW COLUMNS FROM settings LIKE 'mausoleum_price'");

// Check if all columns exist (for displaying error on page load)
$all_price_columns_exist = $check_niche_price && mysqli_num_rows($check_niche_price) > 0 &&
                           $check_lawn_price && mysqli_num_rows($check_lawn_price) > 0 &&
                           $check_mausoleum_price && mysqli_num_rows($check_mausoleum_price) > 0;

// Display error on page load if columns don't exist (only if not already set from session/redirect)
if (!$all_price_columns_exist && empty($error)) {
    $error = 'Plot price columns do not exist in the settings table. Please run the necessary database migration.';
}

if ($all_price_columns_exist) {
    $price_query = "SELECT niche_price, lawn_price, mausoleum_price FROM settings WHERE id = 1";
    $price_result = mysqli_query($conn, $price_query);
    if ($price_result && $row = mysqli_fetch_assoc($price_result)) {
        $plot_raw_prices['niche'] = $row['niche_price'] ?? '';
        $plot_raw_prices['lawn'] = $row['lawn_price'] ?? '';
        $plot_raw_prices['mausoleum'] = $row['mausoleum_price'] ?? '';

        $plot_prices['niche'] = !empty($row['niche_price']) ? '₱' . number_format($row['niche_price'], 2) : '₱0.00';
        $plot_prices['lawn'] = !empty($row['lawn_price']) ? '₱' . number_format($row['lawn_price'], 2) : '₱0.00';
        $plot_prices['mausoleum'] = !empty($row['mausoleum_price']) ? '₱' . number_format($row['mausoleum_price'], 2) : '₱0.00';
    }
}
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
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php include 'includes/styles.php'; ?>
    <style>
        .main {
            padding: 48px 40px 32px 40px !important;
            width: 100%;
            max-width: 100%;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 32px;
            letter-spacing: 1px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            border: 1px solid #e0e0e0;
            max-width: 100%;
            width: 100%;
        }
        .card-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header-actions {
            display: flex;
            gap: 8px;
        }
        .btn-export {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-export:hover {
            background: #1e3a5e;
        }
        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 20px;
        }
        .table-responsive { 
            width: 100%; 
            overflow-x: auto;
            max-width: 100%;
        }
        table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            table-layout: auto;
        }
        th, td {
            padding: 14px 18px;
            text-align: left;
            font-size: 15px;
        }
        th { 
            background: #fafafa; 
            color: #333; 
            border-bottom: 1px solid #e0e0e0; 
            font-weight: 600;
        }
        tr { background: #fff; }
        tr:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
        .filter-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-controls label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        .filter-controls input, .filter-controls select {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .filter-controls button {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
        }
        .filter-controls button:hover {
            background: #1e3a5e;
        }
        .report-section {
            margin-bottom: 40px;
        }
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #222;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2b4c7e;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        .price-info-container {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
        }
        .price-info-title {
            font-size: 18px;
            font-weight: 600;
            color: #222;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .price-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .price-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .price-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .price-type {
            font-size: 16px;
            font-weight: 600;
            color: #2b4c7e;
            margin-bottom: 8px;
        }
        .price-value {
            font-size: 24px;
            font-weight: 700;
            color: #1d2a38;
        }
        .btn-edit-price {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
        }
        .btn-edit-price i {
            font-size: 14px;
        }
        .btn-edit-price:hover {
            background: #1e3a5e;
            box-shadow: 0 4px 10px rgba(43, 76, 126, 0.3);
            transform: translateY(-1px);
        }
        /* Alert styling consistent with system */
        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 15px;
            border: 1px solid;
        }
        .alert-success {
            background: #e6f4ea;
            color: #217a3c;
            border-color: #b7e0c2;
        }
        .alert-danger {
            background: #fdeaea;
            color: #b94a48;
            border-color: #f5c6cb;
        }
        .alert .btn {
            margin-top: 12px;
            padding: 8px 16px;
            background: #2b4c7e;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            display: inline-block;
            transition: background 0.2s;
        }
        .alert .btn:hover {
            background: #1e3a5e;
            color: #fff;
        }
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                padding: 48px 60px 32px 60px !important;
            }
            .card {
                padding: 40px 32px 32px 32px;
            }
            .chart-container {
                height: 450px;
            }
            table {
                font-size: 16px;
            }
            th, td {
                padding: 16px 20px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }
            .stat-card {
                padding: 20px;
            }
            .stat-value {
                font-size: 32px;
            }
        }
        
        @media (min-width: 1600px) {
            .main {
                padding: 48px 80px 32px 80px !important;
            }
            .card {
                padding: 48px 40px 40px 40px;
            }
            .page-title {
                font-size: 2.25rem;
            }
            .card-title {
                font-size: 20px;
            }
            .chart-container {
                height: 500px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 24px;
        }
            .stat-value {
                font-size: 36px;
            }
        }
        
        @media (min-width: 1920px) {
            .main {
                padding: 48px 120px 32px 120px !important;
            }
            .card {
                padding: 56px 48px 48px 48px;
            }
            .page-title {
                font-size: 2.5rem;
            }
            .card-title {
                font-size: 22px;
            }
            .chart-container {
                height: 550px;
            }
            table {
                font-size: 17px;
            }
            th, td {
                padding: 18px 24px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 28px;
            }
            .stat-card {
                padding: 24px;
            }
            .stat-value {
                font-size: 40px;
            }
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main {
                padding: 40px 32px 24px 32px !important;
            }
            .card {
                padding: 28px 20px 20px 20px;
            }
        }
        
        @media (max-width: 1100px) {
            .main { 
                padding: 24px 20px !important; 
                margin-left: 0 !important;
            }
            .page-title {
                font-size: 1.75rem;
            }
            .card {
                padding: 24px 18px 18px 18px;
            }
            .card-title {
                font-size: 16px;
                flex-wrap: wrap;
                gap: 12px;
            }
            .chart-container {
                height: 350px;
            }
            table {
                font-size: 14px;
            }
            th, td {
                padding: 12px 14px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            .price-info-container {
                padding: 20px;
            }
            .price-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 12px;
            }
        }
        
        @media (max-width: 900px) {
            .main {
                padding: 20px 16px !important;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .card {
                padding: 20px 16px 16px 16px;
            }
            .card-title {
                font-size: 15px;
                flex-direction: column;
                align-items: flex-start;
            }
            .card-header-actions {
                width: 100%;
                justify-content: flex-start;
                margin-top: 8px;
            }
            .chart-container {
                height: 300px;
            }
            .filter-controls {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .filter-controls label {
                width: 100%;
            }
            .filter-controls input,
            .filter-controls select,
            .filter-controls button {
                width: 100%;
            }
            table {
                font-size: 13px;
            }
            th, td {
                padding: 10px 12px;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 12px;
            }
            .stat-card {
                padding: 14px;
            }
            .stat-value {
                font-size: 24px;
            }
            .stat-label {
                font-size: 13px;
            }
            .price-info-container {
                padding: 18px;
            }
            .price-info-title {
                font-size: 16px;
            }
            .price-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 10px;
            }
            .price-item {
                padding: 16px;
            }
            .price-type {
                font-size: 15px;
            }
            .price-value {
                font-size: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .main { 
                padding: 16px 12px !important; 
                margin-left: 0 !important;
            }
            .page-title {
                font-size: 1.25rem;
                margin-bottom: 24px;
            }
            .card {
                padding: 16px 12px 12px 12px;
            }
            .card-title {
                font-size: 14px;
            }
            .btn-export {
                padding: 5px 12px;
                font-size: 12px;
            }
            .chart-container {
                height: 250px;
            }
            .table-responsive {
                -webkit-overflow-scrolling: touch;
            }
            table {
                font-size: 12px;
                min-width: 600px;
            }
            th, td {
                padding: 8px 10px;
                white-space: nowrap;
            }
            .section-title {
                font-size: 18px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .stat-card {
                padding: 12px;
            }
            .stat-value {
                font-size: 22px;
            }
            .stat-label {
                font-size: 12px;
            }
            .report-section {
                margin-bottom: 32px;
            }
            .price-info-container {
                padding: 16px;
            }
            .price-info-title {
                font-size: 15px;
                margin-bottom: 16px;
            }
            .price-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .price-item {
                padding: 16px;
            }
            .price-type {
                font-size: 14px;
            }
            .price-value {
                font-size: 22px;
            }
        }
        
        @media (max-width: 576px) {
            .main { 
                padding: 12px 8px !important; 
            }
            .page-title {
                font-size: 1.1rem;
                margin-bottom: 20px;
            }
            .card {
                padding: 12px 10px 10px 10px;
            }
            .card-title {
                font-size: 13px;
            }
            .btn-export {
                padding: 4px 10px;
                font-size: 11px;
            }
            .chart-container {
                height: 200px;
            }
            table {
                font-size: 11px;
                min-width: 500px;
            }
            th, td {
                padding: 6px 8px;
            }
            .section-title {
                font-size: 16px;
            }
            .filter-controls {
                gap: 8px;
            }
            .filter-controls label {
                font-size: 13px;
            }
            .filter-controls input,
            .filter-controls select,
            .filter-controls button {
                padding: 6px 10px;
                font-size: 13px;
            }
            .stat-value {
                font-size: 20px;
            }
            .stat-label {
                font-size: 11px;
            }
        }
        
        @media (max-width: 400px) {
            .main { 
                padding: 10px 6px !important; 
            }
            .page-title {
                font-size: 1rem;
            }
            .card {
                padding: 10px 8px 8px 8px;
            }
            .card-title {
                font-size: 12px;
            }
            .btn-export {
                padding: 4px 8px;
                font-size: 10px;
            }
            .chart-container {
                height: 180px;
            }
            table {
                font-size: 10px;
                min-width: 450px;
            }
            th, td {
                padding: 5px 6px;
            }
            .section-title {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-title">Reports & Analytics</div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <?php if (strpos($error, 'Plot price columns do not exist') !== false): ?>
                    <br>
                    <a href="../database/migrate_plot_prices.php" class="btn">
                        Run Database Migration
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Plot Price Information -->
        <div class="price-info-container">
            <div class="price-info-title">
                <span>Plot Price Information</span>
                <button type="button" class="btn-edit-price" data-bs-toggle="modal" data-bs-target="#editPlotPricesModal">
                    <i class="bi bi-pencil-square"></i>
                    Edit
                </button>
            </div>
            <div class="price-grid">
                <div class="price-item">
                    <div class="price-type">Niches</div>
                    <div class="price-value"><?php echo htmlspecialchars($plot_prices['niche']); ?></div>
                </div>
                <div class="price-item">
                    <div class="price-type">Lawn</div>
                    <div class="price-value"><?php echo htmlspecialchars($plot_prices['lawn']); ?></div>
                </div>
                <div class="price-item">
                    <div class="price-type">Mausoleum</div>
                    <div class="price-value"><?php echo htmlspecialchars($plot_prices['mausoleum']); ?></div>
                </div>
            </div>
        </div>

        <!-- Edit Plot Prices Modal -->
        <div class="modal fade" id="editPlotPricesModal" tabindex="-1" aria-labelledby="editPlotPricesModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editPlotPricesModalLabel">Edit Plot Prices</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="niche_price" class="form-label">Niches Price (₱)</label>
                                <input 
                                    type="number" 
                                    class="form-control" 
                                    id="niche_price" 
                                    name="niche_price" 
                                    step="0.01" 
                                    min="0" 
                                    value="<?php echo htmlspecialchars($plot_raw_prices['niche']); ?>"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="lawn_price" class="form-label">Lawn Price (₱)</label>
                                <input 
                                    type="number" 
                                    class="form-control" 
                                    id="lawn_price" 
                                    name="lawn_price" 
                                    step="0.01" 
                                    min="0" 
                                    value="<?php echo htmlspecialchars($plot_raw_prices['lawn']); ?>"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label for="mausoleum_price" class="form-label">Mausoleum Price (₱)</label>
                                <input 
                                    type="number" 
                                    class="form-control" 
                                    id="mausoleum_price" 
                                    name="mausoleum_price" 
                                    step="0.01" 
                                    min="0" 
                                    value="<?php echo htmlspecialchars($plot_raw_prices['mausoleum']); ?>"
                                    required
                                >
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_plot_prices" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Annual Burial Service Trends -->
        <div class="card">
            <div class="card-title">
                <span>Annual Burial Service Trends</span>
                <div class="card-header-actions">
                    <button class="btn-export" onclick="exportChart('burialTrendsChart', 'Annual_Burial_Trends')">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
            <div class="filter-controls">
                <label>Year:</label>
                <select id="yearSelect" onchange="changeYear()">
                    <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="chart-container">
                <canvas id="burialTrendsChart"></canvas>
            </div>
        </div>

        <!-- Plot Utilization Chart -->
        <div class="card">
            <div class="card-title">
                <span>Plot Utilization Chart</span>
                <div class="card-header-actions">
                    <button class="btn-export" onclick="exportChart('plotUtilizationChart', 'Plot_Utilization')">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $plot_utilization['available']; ?></div>
                    <div class="stat-label">Available</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $plot_utilization['reserved']; ?></div>
                    <div class="stat-label">Reserved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $plot_utilization['occupied']; ?></div>
                    <div class="stat-label">Occupied</div>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="plotUtilizationChart"></canvas>
            </div>
        </div>

        <!-- Burial & Scheduling Reports -->
        <div class="report-section">
            <div class="section-title">Burial & Scheduling Reports</div>

            <!-- Past and Upcoming Scheduled Funerals -->
            <div class="card">
                <div class="card-title">
                    <span>Upcoming Scheduled Funerals</span>
                    <div class="card-header-actions">
                        <button class="btn-export" onclick="exportTable('upcomingFuneralsTable', 'Upcoming_Funerals', 'csv')">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </button>
                        <button class="btn-export" onclick="exportTable('upcomingFuneralsTable', 'Upcoming_Funerals', 'excel')">
                            <i class="bi bi-file-earmark-excel"></i> Excel
                        </button>
                        <button class="btn-export" onclick="exportTable('upcomingFuneralsTable', 'Upcoming_Funerals', 'pdf')">
                            <i class="bi bi-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="upcomingFuneralsTable">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($upcoming_funerals)): ?>
                                <?php foreach ($upcoming_funerals as $funeral): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($funeral['title'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['event_date'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['event_time'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['created_by_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 32px; color: #888;">No upcoming funerals scheduled.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <span>Past Scheduled Funerals</span>
                    <div class="card-header-actions">
                        <button class="btn-export" onclick="exportTable('pastFuneralsTable', 'Past_Funerals', 'csv')">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </button>
                        <button class="btn-export" onclick="exportTable('pastFuneralsTable', 'Past_Funerals', 'excel')">
                            <i class="bi bi-file-earmark-excel"></i> Excel
                        </button>
                        <button class="btn-export" onclick="exportTable('pastFuneralsTable', 'Past_Funerals', 'pdf')">
                            <i class="bi bi-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="pastFuneralsTable">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($past_funerals)): ?>
                                <?php foreach ($past_funerals as $funeral): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($funeral['title'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['event_date'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['event_time'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($funeral['created_by_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 32px; color: #888;">No past funerals found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Burial History by Date Range -->
            <div class="card">
                <div class="card-title">
                    <span>Burial History by Date Range</span>
                    <div class="card-header-actions">
                        <button class="btn-export" onclick="exportTable('burialHistoryTable', 'Burial_History', 'csv')">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </button>
                        <button class="btn-export" onclick="exportTable('burialHistoryTable', 'Burial_History', 'excel')">
                            <i class="bi bi-file-earmark-excel"></i> Excel
                        </button>
                        <button class="btn-export" onclick="exportTable('burialHistoryTable', 'Burial_History', 'pdf')">
                            <i class="bi bi-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="filter-controls">
                    <label>From:</label>
                    <input type="text" class="date-mdY" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>" onchange="filterBurialHistory()" placeholder="mm/dd/yyyy">
                    <label>To:</label>
                    <input type="text" class="date-mdY" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>" onchange="filterBurialHistory()" placeholder="mm/dd/yyyy">
                    <button onclick="filterBurialHistory()">Filter</button>
                </div>
            <div class="table-responsive">
                    <table id="burialHistoryTable">
                    <thead>
                        <tr>
                                <th>Name</th>
                                <th>Date of Death</th>
                                <th>Date of Burial</th>
                                <th>Plot</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php if (!empty($burial_history)): ?>
                                <?php foreach ($burial_history as $burial): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(($burial['first_name'] ?? '') . ' ' . ($burial['last_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($burial['date_of_death'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($burial['date_of_burial'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars(($burial['section'] ?? '') . '-' . ($burial['row_number'] ?? '') . '-' . ($burial['plot_number'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 32px; color: #888;">No burial records found for the selected date range.</td></tr>
                            <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Plot Inventory Reports -->
        <div class="report-section">
            <div class="section-title">Plot Inventory Reports</div>

            <!-- Available Plots by Section/Block -->
        <div class="card">
                <div class="card-title">
                    <span>Available Plots by Section/Block</span>
                    <div class="card-header-actions">
                        <button class="btn-export" onclick="exportTable('plotsBySectionTable', 'Plots_by_Section', 'csv')">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </button>
                        <button class="btn-export" onclick="exportTable('plotsBySectionTable', 'Plots_by_Section', 'excel')">
                            <i class="bi bi-file-earmark-excel"></i> Excel
                        </button>
                        <button class="btn-export" onclick="exportTable('plotsBySectionTable', 'Plots_by_Section', 'pdf')">
                            <i class="bi bi-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            <div class="table-responsive">
                    <table id="plotsBySectionTable">
                    <thead>
                        <tr>
                                <th>Section</th>
                                <th>Available</th>
                                <th>Reserved</th>
                                <th>Occupied</th>
                                <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php if (!empty($section_data)): ?>
                                <?php foreach ($section_data as $section => $data): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($section); ?></td>
                                        <td><?php echo $data['available']; ?></td>
                                        <td><?php echo $data['reserved']; ?></td>
                                        <td><?php echo $data['occupied']; ?></td>
                                        <td><?php echo $data['available'] + $data['reserved'] + $data['occupied']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 32px; color: #888;">No plot data available.</td></tr>
                            <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Reserved vs Purchased Plots -->
            <div class="card">
                <div class="card-title">
                    <span>Reserved vs Purchased Plots</span>
                    <div class="card-header-actions">
                        <button class="btn-export" onclick="exportChart('reservedVsPurchasedChart', 'Reserved_vs_Purchased')">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $reserved_count; ?></div>
                        <div class="stat-label">Reserved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $occupied_count; ?></div>
                        <div class="stat-label">Purchased/Occupied</div>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="reservedVsPurchasedChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
<script>
// Annual Burial Trends Chart
const burialTrendsCtx = document.getElementById('burialTrendsChart').getContext('2d');
const burialTrendsChart = new Chart(burialTrendsCtx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [{
            label: 'Burials',
            data: <?php echo json_encode($burial_trends); ?>,
            borderColor: '#2b4c7e',
            backgroundColor: 'rgba(43, 76, 126, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// Plot Utilization Chart
const plotUtilizationCtx = document.getElementById('plotUtilizationChart').getContext('2d');
const plotUtilizationChart = new Chart(plotUtilizationCtx, {
    type: 'doughnut',
    data: {
        labels: ['Available', 'Reserved', 'Occupied'],
        datasets: [{
            data: [
                <?php echo $plot_utilization['available']; ?>,
                <?php echo $plot_utilization['reserved']; ?>,
                <?php echo $plot_utilization['occupied']; ?>
            ],
            backgroundColor: [
                '#198754',
                '#f5a623',
                '#dc3545'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Reserved vs Purchased Chart
const reservedVsPurchasedCtx = document.getElementById('reservedVsPurchasedChart').getContext('2d');
const reservedVsPurchasedChart = new Chart(reservedVsPurchasedCtx, {
    type: 'bar',
    data: {
        labels: ['Reserved', 'Purchased/Occupied'],
        datasets: [{
            label: 'Number of Plots',
            data: [<?php echo $reserved_count; ?>, <?php echo $occupied_count; ?>],
            backgroundColor: ['#f5a623', '#2b4c7e']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

function changeYear() {
    const year = document.getElementById('yearSelect').value;
    window.location.href = '?year=' + year;
}

function filterBurialHistory() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    window.location.href = '?date_from=' + dateFrom + '&date_to=' + dateTo;
}

function exportChart(chartId, filename) {
    // Get the canvas element from the chart
    let canvas;
    if (chartId === 'burialTrendsChart') {
        canvas = burialTrendsChart.canvas;
    } else if (chartId === 'plotUtilizationChart') {
        canvas = plotUtilizationChart.canvas;
    } else if (chartId === 'reservedVsPurchasedChart') {
        canvas = reservedVsPurchasedChart.canvas;
    }
    
    if (canvas) {
        const url = canvas.toDataURL('image/png');
        const link = document.createElement('a');
        link.download = filename + '.png';
        link.href = url;
        link.click();
    }
}

function exportTable(tableId, filename, format) {
    const table = document.getElementById(tableId);
    const rows = table.querySelectorAll('tr');
    
    if (format === 'csv') {
        let csv = [];
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            let rowData = [];
            cols.forEach(col => {
                rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
            });
            csv.push(rowData.join(','));
        });
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename + '.csv';
        link.click();
    } else if (format === 'excel') {
        // For Excel, we'll use CSV format (Excel can open CSV)
        exportTable(tableId, filename, 'csv');
    } else if (format === 'pdf') {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        doc.autoTable({
            html: '#' + tableId,
            theme: 'striped',
            headStyles: { fillColor: [43, 76, 126] }
        });
        doc.save(filename + '.pdf');
    }
}
        </script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script>
            // Flatpickr setup for burial history date range filters
            document.addEventListener('DOMContentLoaded', function () {
                const dateInputs = document.querySelectorAll('input.date-mdY');
                if (!dateInputs.length || !window.flatpickr) return;

                dateInputs.forEach(function (input) {
                    flatpickr(input, {
                        dateFormat: "Y-m-d",    // value sent to PHP
                        altInput: true,
                        altFormat: "m/d/Y",     // what user sees
                        allowInput: true
                    });
                });
            });
        </script>
    </body>
</html> 
