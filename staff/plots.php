<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $plot_id = intval($_POST['plot_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    
    // Get current plot status and deceased info
    $current_query = "SELECT p.status, d.* FROM plots p 
                     LEFT JOIN deceased_records d ON p.plot_id = d.plot_id 
                     WHERE p.plot_id = ?";
    $stmt = mysqli_prepare($conn, $current_query);
    mysqli_stmt_bind_param($stmt, "i", $plot_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $current_data = mysqli_fetch_assoc($result);
    
    // Validate status
    $valid_statuses = ['available', 'reserved', 'occupied'];
    if (in_array($new_status, $valid_statuses)) {
        // Start transaction
        mysqli_begin_transaction($conn);
        try {
            // If changing from occupied to available, archive the deceased record
            if ($current_data['status'] === 'occupied' && $new_status === 'available' && $current_data['record_id']) {
                // Insert into archived_deceased_records
                $archive_query = "INSERT INTO archived_deceased_records 
                                (deceased_id, plot_id, first_name, last_name, date_of_birth, 
                                date_of_death, date_of_burial, reason, archived_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $archive_stmt = mysqli_prepare($conn, $archive_query);
                $reason = "Plot status changed from occupied to available";
                
                // Split full_name into first_name and last_name for archiving
                $name_parts = explode(' ', $current_data['full_name'], 2);
                $first_name = $name_parts[0];
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                
                mysqli_stmt_bind_param($archive_stmt, "iissssssi", 
                    $current_data['record_id'],
                    $current_data['plot_id'],
                    $first_name,
                    $last_name,
                    $current_data['date_of_birth'],
                    $current_data['date_of_death'],
                    $current_data['burial_date'],
                    $reason,
                    $_SESSION['user_id']
                );
                mysqli_stmt_execute($archive_stmt);
                
                // Delete from deceased_records
                $delete_query = "DELETE FROM deceased_records WHERE record_id = ?";
                $delete_stmt = mysqli_prepare($conn, $delete_query);
                mysqli_stmt_bind_param($delete_stmt, "i", $current_data['record_id']);
                mysqli_stmt_execute($delete_stmt);
            }
            
            // Update plot status
            $update_query = "UPDATE plots SET status = ? WHERE plot_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "si", $new_status, $plot_id);
            mysqli_stmt_execute($update_stmt);
            
            // Commit transaction
            mysqli_commit($conn);
            $success_message = "Plot status updated successfully";
            
            // Refresh the page to show updated status
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            // Rollback on error
            mysqli_rollback($conn);
            $error_message = "Error updating plot status: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid status value";
    }
}

// Handle new plot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Check if all required fields are present
    $required_fields = ['section', 'max_levels', 'start_lat', 'start_lng', 'end_lat', 'end_lng'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            $missing_fields[] = $field;
        }
    }
    
    if (empty($missing_fields)) {
        $section_id = intval($_POST['section']);
        $max_levels = intval($_POST['max_levels']);
        $start_lat = floatval($_POST['start_lat']);
        $start_lng = floatval($_POST['start_lng']);
        $end_lat = floatval($_POST['end_lat']);
        $end_lng = floatval($_POST['end_lng']);
        $status = 'available'; // Fixed status

        // Get section code for plot number generation
        $section_query = "SELECT section_code FROM sections WHERE section_id = ?";
        $stmt = mysqli_prepare($conn, $section_query);
        mysqli_stmt_bind_param($stmt, "i", $section_id);
        mysqli_stmt_execute($stmt);
        $section_result = mysqli_stmt_get_result($stmt);
        $section_data = mysqli_fetch_assoc($section_result);
        $section_code = $section_data['section_code'];

        // Calculate steps for interpolation - use a fixed grid size
        $grid_size = 10; // Fixed number of plots per row
        $lat_step = ($end_lat - $start_lat) / ($grid_size - 1);
        $lng_step = ($end_lng - $start_lng) / ($grid_size - 1);
        $row_step = 0.00003; // Vertical spacing between rows

        $success_count = 0;
        $error_count = 0;
        $duplicate_count = 0;
        $duplicate_plots = array();

        // Generate plots in grid pattern - rows start from A and continue
        $row_letter = 'A'; // Start from A
        $row_number = 1;
        
        // Generate plots for each level
        for ($level = 1; $level <= $max_levels; $level++) {
            // Generate plots for each row in this level
            for ($row = 1; $row <= $max_levels; $row++) {
                for ($col = 1; $col <= $grid_size; $col++) {
                    $lat = $start_lat + ($col - 1) * $lat_step - ($row_number - 1) * $row_step;
                    $lng = $start_lng + ($col - 1) * $lng_step;
                    $plot_number = $section_code . "-" . $row_letter . $col . "-L" . $level;

                    // Check if plot number already exists
                    $check_query = "SELECT plot_number FROM plots WHERE plot_number = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "s", $plot_number);
                    mysqli_stmt_execute($stmt);
                    $check_result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($check_result) > 0) {
                        $duplicate_count++;
                        $duplicate_plots[] = $plot_number;
                        continue; // Skip this plot and continue with the next one
                    }

                    $query = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    $is_multi_level = $max_levels > 1 ? 1 : 0;
                    mysqli_stmt_bind_param($stmt, "iisddssiii", $section_id, $row_number, $plot_number, $lat, $lng, $status, $level, $max_levels, $is_multi_level);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                }
                $row_number++;
                $row_letter++; // Move to next letter (A, B, C, etc.)
            }
        }

        if ($success_count > 0) {
            $success_message = "Successfully added $success_count plots!";
        }
        if ($duplicate_count > 0) {
            $duplicate_message = "Skipped $duplicate_count duplicate plots: " . implode(", ", $duplicate_plots);
        }
        if ($error_count > 0) {
            $error_message = "Failed to add $error_count plots.";
        }
    } else {
        $error_message = "Missing required fields: " . implode(", ", $missing_fields);
    }
}

// Handle status filter
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$valid_statuses = ['available', 'reserved', 'occupied'];

// Handle row filter
$row_filter = isset($_GET['row_filter']) ? $_GET['row_filter'] : '';

// Handle section filter
$section_filter = isset($_GET['section_filter']) ? $_GET['section_filter'] : '';
// Track if containers should remain expanded (when "All" is clicked)
$expand_sections = isset($_GET['expand_sections']) ? $_GET['expand_sections'] : '0';
$expand_rows = isset($_GET['expand_rows']) ? $_GET['expand_rows'] : '0';
$expand_status = isset($_GET['expand_status']) ? $_GET['expand_status'] : '0';

// Handle search parameters
$search_status = $_GET['search_status'] ?? '';
$search_plot = $_GET['search_plot'] ?? '';
$search_section = $_GET['search_section'] ?? '';
$search_row = $_GET['search_row'] ?? '';

// Helper function to convert row number to letter
function rowNumberToLetter($row_number) {
    return chr(64 + (int)$row_number);
}

// Get rows data for search form (when search_section is selected)
$rows_data = [];
if (!empty($search_section)) {
    // Use GROUP BY instead of DISTINCT for better compatibility
    $rows_query = "SELECT row_number
                   FROM plots
                   WHERE section_id = ?
                   GROUP BY row_number
                   ORDER BY row_number";
    $stmt = mysqli_prepare($conn, $rows_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $search_section);
        mysqli_stmt_execute($stmt);
        $rows_result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($rows_result)) {
            $rows_data[] = [
                'row_number' => $row['row_number'],
                'row_letter' => rowNumberToLetter($row['row_number'])
            ];
        }
        mysqli_stmt_close($stmt);
    }
}
$sections_list = [];
$sections_query = "SELECT s.section_id, s.section_name, s.section_code, COUNT(p.plot_id) as plot_count 
                   FROM sections s 
                   LEFT JOIN plots p ON s.section_id = p.section_id AND p.status = 'available'
                   WHERE LOWER(s.section_name) NOT LIKE '%test%' 
                   AND LOWER(s.section_code) NOT LIKE '%test%'
                   AND UPPER(TRIM(s.section_name)) != 'AP'
                   AND UPPER(TRIM(s.section_code)) != 'AP'
                   AND s.section_name NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                   AND s.section_code NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                   GROUP BY s.section_id, s.section_name, s.section_code
                   HAVING COUNT(p.plot_id) > 0
                   ORDER BY s.section_code";
$sections_result = mysqli_query($conn, $sections_query);
// Define apartment-type niches sections (all sections starting with A except ARIES)
$apartmentTypeSections = ['AION', 'APHRODITE', 'ATHENA', 'ATLAS', 'ARTEMIS', 'APOLLO', 'AURA', 'ASTREA', 'ARIA'];

while ($row = mysqli_fetch_assoc($sections_result)) {
    // Double-check in PHP as well
    $upperName = strtoupper(trim($row['section_name'] ?? ''));
    $upperCode = strtoupper(trim($row['section_code'] ?? ''));
    if ($upperName !== 'AP' && $upperCode !== 'AP' &&
        !preg_match('/^BLK\s*[1-4]$/i', $upperName) && !preg_match('/^BLK\s*[1-4]$/i', $upperCode)) {
        // Determine plot type based on section name/code
        $isApartmentType = in_array($upperName, $apartmentTypeSections) || in_array($upperCode, $apartmentTypeSections);
        $row['plot_type'] = $isApartmentType ? 'niche' : 'lawn';
        $row['plot_type_label'] = $isApartmentType ? 'Niches' : 'Lawn-lot';
        $sections_list[] = $row;
    }
}

// Get available rows for the currently selected section (for row filter options)
$rows_list = [];
if ($section_filter !== '' && ctype_digit($section_filter)) {
    // Use GROUP BY instead of DISTINCT for better compatibility
    $rows_query = "SELECT row_number
                   FROM plots
                   WHERE section_id = " . intval($section_filter) . "
                   GROUP BY row_number
                   ORDER BY row_number";
    $rows_result = mysqli_query($conn, $rows_query);
    while ($row = mysqli_fetch_assoc($rows_result)) {
        $rows_list[] = (int)$row['row_number'];
    }
}

// Helper function to build pagination URL
function buildPaginationUrl($page_num, $section_filter_val, $row_filter_val, $status_filter_val) {
    $params = array();
    $params['page'] = $page_num;
    if ($section_filter_val !== '') $params['section_filter'] = $section_filter_val;
    if ($row_filter_val !== '') $params['row_filter'] = $row_filter_val;
    if ($status_filter_val !== '') $params['status_filter'] = $status_filter_val;
    if (isset($_GET['expand_sections']) && $_GET['expand_sections'] === '1') $params['expand_sections'] = '1';
    if (isset($_GET['expand_rows']) && $_GET['expand_rows'] === '1') $params['expand_rows'] = '1';
    if (isset($_GET['expand_status']) && $_GET['expand_status'] === '1') $params['expand_status'] = '1';
    // Include search parameters
    if (isset($_GET['search_status']) && $_GET['search_status'] !== '') $params['search_status'] = $_GET['search_status'];
    if (isset($_GET['search_plot']) && $_GET['search_plot'] !== '') $params['search_plot'] = $_GET['search_plot'];
    if (isset($_GET['search_section']) && $_GET['search_section'] !== '') $params['search_section'] = $_GET['search_section'];
    if (isset($_GET['search_row']) && $_GET['search_row'] !== '') $params['search_row'] = $_GET['search_row'];
    return '?' . http_build_query($params);
}

// Add pagination
$plots_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $plots_per_page;

$query = "SELECT 
            p.*,
            s.section_code,
            s.section_name,
            GROUP_CONCAT(d.full_name ORDER BY d.burial_date SEPARATOR ',') AS deceased_names,
            COUNT(d.record_id) AS deceased_count
          FROM plots p 
          LEFT JOIN deceased_records d ON p.plot_id = d.plot_id 
          JOIN sections s ON p.section_id = s.section_id
          WHERE LOWER(s.section_name) NOT LIKE '%test%' 
          AND LOWER(s.section_code) NOT LIKE '%test%'";
$where = [];
$params = [];
$types = '';

// Add search filters
if (!empty($search_status) && in_array($search_status, $valid_statuses)) {
    $where[] = "p.status = ?";
    $params[] = $search_status;
    $types .= 's';
}

if (!empty($search_plot)) {
    $where[] = "p.plot_number LIKE ?";
    $params[] = "%$search_plot%";
    $types .= 's';
}

if (!empty($search_section)) {
    $where[] = "s.section_id = ?";
    $params[] = $search_section;
    $types .= 'i';
}

if (!empty($search_row)) {
    $where[] = "p.row_number = ?";
    $params[] = $search_row;
    $types .= 'i';
}

// Add existing filters
if (in_array($status_filter, $valid_statuses)) {
    $where[] = "p.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if ($section_filter !== '' && ctype_digit($section_filter)) {
    $where[] = "p.section_id = " . intval($section_filter);
}
if ($row_filter !== '' && ctype_digit($row_filter)) {
    $where[] = "p.row_number = " . intval($row_filter);
}

if (!empty($where)) {
    $query .= " AND " . implode(' AND ', $where);
}

// Group by plot so multiple deceased records on the same plot are combined
$query .= " GROUP BY p.plot_id";

// Apply ordering
$query .= " ORDER BY s.section_code, p.row_number ASC, CAST(p.plot_number AS UNSIGNED) ASC, p.level_number ASC";

// Get total count for pagination using a safe subquery (after grouping)
$count_base_query = preg_replace('/ORDER BY.*$/i', '', $query);
$count_query = "SELECT COUNT(*) as total FROM ({$count_base_query}) AS plot_sub";

// Execute count query with prepared statement if search parameters are used
if (!empty($params)) {
    $count_stmt = mysqli_prepare($conn, $count_query);
    if ($count_stmt) {
        mysqli_stmt_bind_param($count_stmt, $types, ...$params);
        mysqli_stmt_execute($count_stmt);
        $count_result = mysqli_stmt_get_result($count_stmt);
        if ($count_result) {
            $total_plots = mysqli_fetch_assoc($count_result)['total'];
        } else {
            $total_plots = 0;
        }
        mysqli_stmt_close($count_stmt);
    } else {
        $total_plots = 0;
    }
} else {
    $count_result = mysqli_query($conn, $count_query);
    if ($count_result) {
        $total_plots = mysqli_fetch_assoc($count_result)['total'];
    } else {
        $total_plots = 0;
    }
}
$total_pages = ceil($total_plots / $plots_per_page);

// Add limit and offset to main query
$query .= " LIMIT $offset, $plots_per_page";

// Execute query with prepared statement if search parameters are used
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = false;
    }
} else {
    // Use regular query if no search parameters
    $result = mysqli_query($conn, $query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Raleway is used for general UI text, Inter matches the deceased records data font -->
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Add Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Add Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5a6fd8;
            --secondary-color: #764ba2;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --border-radius: 0.75rem;
            --border-radius-sm: 0.5rem;
            --border-radius-lg: 1rem;
        }

        * {
            box-sizing: border-box;
        }

        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background: #f5f5f5;
            color: var(--gray-800);
            line-height: 1.6;
            overflow-x: hidden;
        }
        html {
            overflow-x: hidden;
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
        }
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #2b4c7e;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
        }

        .layout { 
            display: flex; 
            min-height: 100vh; 
        }

        /* Main content styles are handled by sidebar.php for consistency */
        /* Override background color only if needed for this page */
        .main {
            background: var(--light-bg);
        }

        /* Modern Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: #000000;
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 1rem;
            margin-top: 0.5rem;
        }

        /* Modern Button */
        .btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary-modern {
            background: #2b4c7e;
            color: white;
        }

        .btn-primary-modern:hover {
            background: #1f3659;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
            color: white;
        }

        /* Modern Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }



        /* Modern Filters */
        .filters-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 200px;
        }

        .filter-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .form-control-modern {
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: var(--white);
        }

        .form-control-modern:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Button-based Filter Container */
        .filters-container-button {
            display: flex;
            flex-direction: row;
            gap: 1.5rem;
            margin-bottom: 2rem;
            flex-wrap: nowrap;
            align-items: stretch;
        }

        .filter-section-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            flex: 1 1 0;
            min-width: 320px;
            max-width: 400px;
            width: 100%;
            display: flex;
            flex-direction: column;
            min-height: 450px;
            transition: min-height 0.3s ease, padding 0.3s ease;
            overflow: hidden;
        }

        .filter-section-card.dropdown-container.collapsed {
            min-height: auto;
            padding-bottom: 1.25rem;
        }

        .filter-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--gray-600);
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .dropdown-container .filter-section-title {
            margin-bottom: 0;
        }

        .section-controls {
            padding: 0;
        }

        .section-filter-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            background: var(--white);
            border-radius: var(--border-radius-sm);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--gray-800);
        }

        .section-filter-select:focus {
            outline: none;
            border-color: #2b4c7e;
            box-shadow: 0 0 0 3px rgba(43, 76, 126, 0.15);
        }

        /* Plot Sections Card Styles */
        .plot-sections-card {
            /* Uses same sizing as .filter-section-card */
        }

        /* Dropdown Container Styles - Applied to all filter cards */
        .dropdown-container {
            overflow: hidden;
        }

        .dropdown-header {
            cursor: pointer;
            user-select: none;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .dropdown-header:hover {
            opacity: 0.9;
        }

        .dropdown-header-content {
            flex: 1;
        }

        .dropdown-toggle-icon {
            font-size: 1rem;
            color: var(--gray-600);
            transition: transform 0.3s ease;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .dropdown-container.collapsed .dropdown-toggle-icon {
            transform: rotate(-90deg);
        }

        .dropdown-content {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease, margin 0.3s ease, padding 0.3s ease;
            opacity: 1;
            margin-top: 0;
            pointer-events: auto;
        }

        .dropdown-container.collapsed .dropdown-content {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
            pointer-events: none;
        }

        .section-instruction {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-bottom: 0;
            margin-top: 4px;
            font-style: italic;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .section-cards-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
        }

        .section-cards-container::-webkit-scrollbar {
            width: 6px;
        }

        .section-cards-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }

        .section-cards-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }

        .section-cards-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .section-card {
            background: var(--white);
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            padding: 12px 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
            border-color: var(--gray-400);
        }

        .section-card.active {
            background: #f0f4ff;
            border-color: #2b4c7e;
            box-shadow: 0 0 0 2px rgba(43, 76, 126, 0.2);
        }

        .section-card-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .section-card-info {
            flex: 1;
        }

        .section-card-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 4px;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .section-card-count {
            font-size: 0.75rem;
            color: var(--gray-600);
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .section-card-btn {
            padding: 6px 14px;
            background: #e2e8f0;
            color: #475569;
            border: none;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .section-card.active .section-card-btn {
            background: #2b4c7e;
            color: white;
        }

        .section-card:hover .section-card-btn {
            background: #cbd5e1;
        }

        .section-card.active:hover .section-card-btn {
            background: #1f3659;
        }

        .row-controls,
        .status-controls {
            padding: 0;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .row-buttons,
        .status-filter-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .row-btn,
        .status-filter-btn {
            padding: 16px 20px;
            border: 1px solid var(--gray-300);
            background: var(--white);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 1rem;
            text-align: left;
            color: var(--gray-700);
            font-weight: 500;
            width: 100%;
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            /* Match deceased records font */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .row-btn:hover,
        .status-filter-btn:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }

        .row-btn.active,
        .status-filter-btn.active {
            background: #2b4c7e;
            color: white;
            border-color: #2b4c7e;
            font-weight: 600;
        }

        .status-filter-btn {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-indicator {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            flex-shrink: 0;
            border: 2px solid rgba(0, 0, 0, 0.2);
        }

        .status-filter-btn.active .status-indicator {
            border-color: rgba(255, 255, 255, 0.5);
        }

        .status-indicator.available {
            background-color: #10b981;
        }

        .status-indicator.reserved {
            background-color: #f59e0b;
        }

        .status-indicator.occupied {
            background-color: #ef4444;
        }

        .row-info {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-align: center;
            padding-top: 8px;
            margin-top: 8px;
            border-top: 1px solid var(--gray-200);
        }

        .row-info-text {
            font-style: italic;
            color: var(--gray-500);
            font-size: 0.875rem;
            padding: 8px;
            text-align: center;
        }

        /* Responsive styles for button filters */
        @media (max-width: 1200px) {
            .filters-container-button {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 768px) {
            .filters-container-button {
                flex-direction: column;
                flex-wrap: nowrap;
                gap: 1rem;
            }

            .filter-section-card {
                width: 100%;
                min-width: auto;
            }
        }

        /* Modern Table */
        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .table-modern thead {
            background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
        }

        .table-modern th {
            padding: 1rem 1.5rem;
            text-align: center;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--gray-200);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .table-modern td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            text-align: center;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .table-modern td .deceased-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .table-modern td .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .table-modern .section-header th,
        .table-modern .section-header td {
            /* Match primary blue button color, no gradient */
            background-color: #2b4c7e !important;
            color: white !important;
            font-weight: 700;
            font-size: 1rem;
            padding: 1rem 1.5rem;
            border: none !important;
            text-align: center;
        }

        .table-modern tbody tr {
            transition: all 0.2s ease;
        }

        .table-modern tbody tr:hover {
            background: var(--gray-50);
        }

        .table-modern tbody tr:last-child td {
            border-bottom: none;
        }

        /* Modern Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-available {
            background: #10b981;
            color: white;
        }

        .status-reserved {
            background: #fbbf24;
            color: white;
        }

        .status-occupied {
            background: #ef4444;
            color: white;
        }

        .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: currentColor;
        }

        /* Modern Action Buttons - Consistent System */
        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-view {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .btn-view:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .btn-edit {
            background: #2b4c7e;
            color: white;
        }

        .btn-edit:hover {
            background: #1f3659;
            color: white;
        }
        
        .btn-approve {
            background: var(--success-color, #10b981);
            color: white;
        }
        .btn-approve:hover {
            background: #059669;
            color: white;
        }
        
        .btn-reject {
            background: var(--danger-color, #ef4444);
            color: white;
        }
        .btn-reject:hover {
            background: #dc2626;
            color: white;
        }
        
        /* Table action column alignment */
        .table-modern td:last-child,
        .table-modern th:last-child {
            text-align: center;
        }

        /* Modern Status Select */
        .status-select-modern {
            padding: 0.375rem 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--white);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .status-select-modern:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Modern Alerts */
        .alert-modern {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success-modern {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-danger-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert-warning-modern {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
            border-color: rgba(245, 158, 11, 0.2);
        }

        /* Section Headers - Consolidated with table-modern rule above */

        /* Deceased Info */
        .deceased-info {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
            /* Match the font used in deceased_records.php for records data */
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        /* Pagination */
        .pagination-modern {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
            padding: 1.5rem;
        }

        .pagination-info {
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .pagination-controls-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination-jump-btn {
            font-size: 0.8rem;
            padding: 0.5rem 0.75rem;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--border-radius-lg);
            border: none;
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
            border-bottom: none;
            padding: 1.5rem 2rem;
        }

        .modal-header .btn-close {
            filter: invert(1);
            opacity: 0.8;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 1.5rem 2rem;
            background: var(--gray-50);
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .filters-container {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                min-width: auto;
            }

            .table-modern {
                font-size: 0.875rem;
            }

            .table-modern th,
            .table-modern td {
                padding: 0.75rem 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }
            
            .table-card {
                padding: 18px 16px;
                overflow-x: auto;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            
            .table-card {
                padding: 12px 8px;
                overflow-x: auto;
            }
            
            .table-modern {
                font-size: 0.75rem;
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .table-modern th,
            .table-modern td {
                padding: 0.5rem 0.75rem;
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

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid var(--gray-300);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Search Form Styles */
        .search-form {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: none;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .search-form form {
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }
        .search-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .search-group {
            flex: 1;
            min-width: 200px;
        }
        .search-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .search-group input, .search-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .search-group input:focus, .search-group select:focus {
            outline: none;
            border-color: #2b4c7e;
            box-shadow: 0 0 0 2px rgba(43, 76, 126, 0.1);
        }
        .search-buttons {
            display: flex;
            gap: 12px;
            margin-top: auto;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
        }
        .btn-search {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-search:hover {
            background: #1f3659;
        }
        .btn-clear {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-clear:hover {
            background: #5c636a;
        }
        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 16px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        /* Map Styles */
        #map {
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }

        .coordinates-display {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            border: 1px solid var(--gray-200);
        }


    </style>

</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <!-- Modern Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Plots Management</h1>
                <p class="page-subtitle">Manage cemetery plots, view status, and track occupancy</p>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
            <a href="add_plots.php" class="btn-modern btn-primary-modern">
                <i class="bi bi-plus-lg"></i> Add Plots
            </a>
                <a href="existing_plots.php" class="btn-modern" style="background: #6c757d; color: white; border: none; text-decoration: none;">
                    <i class="bi bi-eye"></i> View Existing Plots
                </a>
            </div>
        </div>
        
        <!-- Modern Alerts -->
        <?php if (isset($success_message)): ?>
        <div class="alert-modern alert-success-modern">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert-modern alert-danger-modern">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($duplicate_message)): ?>
        <div class="alert-modern alert-warning-modern">
            <i class="bi bi-info-circle-fill"></i>
            <?php echo htmlspecialchars($duplicate_message); ?>
        </div>
        <?php endif; ?>

        <!-- Search Form -->
        <div class="search-form">
            <div class="table-title">
                Search & Filter Plots
                <?php 
                $active_filters = array_filter([$search_status, $search_plot, $search_section, $search_row]);
                if (!empty($active_filters)): 
                ?>
                    <span style="font-size: 12px; background: #2b4c7e; color: white; padding: 2px 8px; border-radius: 12px; margin-left: 8px;">
                        <?php echo count($active_filters); ?> filter(s) active
                    </span>
                <?php endif; ?>
            </div>
            <form method="GET" action="">
                <div class="search-row">
                    <div class="search-group">
                        <label for="search_section">Filter by Section</label>
                        <select id="search_section" name="search_section">
                            <option value="">All Sections</option>
                            <?php 
                            // Get sections for search form, based purely on the database.
                            // This reads directly from `sections` + `plots`, so when you remove
                            // or change sections in the DB the filter will follow.
                            $search_sections_query = "SELECT s.section_id, s.section_name, s.section_code 
                                                     FROM sections s
                                                     JOIN plots p ON p.section_id = s.section_id
                                                     WHERE s.section_name NOT LIKE '%TES%' 
                                                     AND s.section_code NOT LIKE '%TES%'
                                                     AND UPPER(TRIM(s.section_name)) != 'AP'
                                                     AND UPPER(TRIM(s.section_code)) != 'AP'
                                                     AND s.section_name NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                                                     AND s.section_code NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                                                     GROUP BY s.section_id, s.section_name, s.section_code
                                                     ORDER BY s.section_code";
                            $search_sections_result = mysqli_query($conn, $search_sections_query);
                            if ($search_sections_result && mysqli_num_rows($search_sections_result) > 0): 
                                mysqli_data_seek($search_sections_result, 0); // Reset pointer
                                while ($section = mysqli_fetch_assoc($search_sections_result)): 
                            ?>
                                <option value="<?php echo $section['section_id']; ?>" <?php echo $search_section == $section['section_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['section_name']); ?>
                                </option>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    <div class="search-group">
                        <label for="search_row">Filter by Row</label>
                        <select id="search_row" name="search_row" <?php echo empty($search_section) ? 'disabled' : ''; ?>>
                            <?php if (empty($search_section)): ?>
                                <option value="">Select a section first</option>
                            <?php else: ?>
                                <option value="">All Rows</option>
                                <?php if (!empty($rows_data)): ?>
                                    <?php foreach ($rows_data as $row): ?>
                                        <option 
                                            value="<?php echo htmlspecialchars($row['row_number']); ?>" 
                                            <?php echo (string)$search_row === (string)$row['row_number'] ? 'selected' : ''; ?>>
                                            <?php echo 'Row ' . htmlspecialchars($row['row_letter']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No rows found</option>
                                <?php endif; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="search-group">
                        <label for="search_plot">Search by Plot</label>
                        <input type="text" id="search_plot" name="search_plot" value="<?php echo htmlspecialchars($search_plot); ?>" placeholder="Enter plot number...">
                    </div>
                    <div class="search-group">
                        <label for="search_status">Filter by Status</label>
                        <select id="search_status" name="search_status">
                            <option value="">All Status</option>
                            <option value="available" <?php echo $search_status === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="reserved" <?php echo $search_status === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                            <option value="occupied" <?php echo $search_status === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                        </select>
                    </div>
                </div>
                <div class="search-buttons">
                    <div style="display: flex; gap: 12px; margin-left: auto;">
                    <button type="submit" class="btn-search">Search</button>
                    <button type="button" class="btn-clear" onclick="clearSearch()">Clear</button>
                    </div>
                </div>
                <!-- Preserve existing filter parameters -->
                <?php if ($section_filter !== ''): ?>
                    <input type="hidden" name="section_filter" value="<?php echo htmlspecialchars($section_filter); ?>">
                <?php endif; ?>
                <?php if ($row_filter !== ''): ?>
                    <input type="hidden" name="row_filter" value="<?php echo htmlspecialchars($row_filter); ?>">
                <?php endif; ?>
                <?php if ($status_filter !== ''): ?>
                    <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                <?php endif; ?>
                <?php if ($expand_sections === '1'): ?>
                    <input type="hidden" name="expand_sections" value="1">
                <?php endif; ?>
                <?php if ($expand_rows === '1'): ?>
                    <input type="hidden" name="expand_rows" value="1">
                <?php endif; ?>
                <?php if ($expand_status === '1'): ?>
                    <input type="hidden" name="expand_status" value="1">
                <?php endif; ?>
            </form>
        </div>

        <!-- Modern Table Card -->
        <div class="card">
            <div class="card-body">
            <div style="overflow-x:auto;">
                    <table class="table-modern">
                    <thead>
                        <?php 
                        // Get the first section to display in header if section filter is active
                        $header_section_name = '';
                        if ($section_filter !== '' && ctype_digit($section_filter)) {
                            $header_query = "SELECT section_name FROM sections WHERE section_id = ? LIMIT 1";
                            $header_stmt = mysqli_prepare($conn, $header_query);
                            mysqli_stmt_bind_param($header_stmt, "i", $section_filter);
                            mysqli_stmt_execute($header_stmt);
                            $header_result = mysqli_stmt_get_result($header_stmt);
                            if ($header_row = mysqli_fetch_assoc($header_result)) {
                                $header_section_name = $header_row['section_name'];
                            }
                        }
                        ?>
                        <?php if ($header_section_name && $section_filter !== ''): ?>
                        <tr class="section-header">
                            <th colspan="5">
                                Section: <?php echo htmlspecialchars($header_section_name); ?> <i class="bi bi-geo-alt"></i>
                            </th>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Plot Number</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Deceased Information</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $last_section = null;
                        while ($plot = mysqli_fetch_assoc($result)): 
                            // Only show section header rows in tbody when no section filter is active
                            if ($section_filter === '' && $plot['section_code'] !== $last_section): 
                                $last_section = $plot['section_code']; ?>
                        <?php endif; ?>
                        <tr>
                                <td>
                                    <strong><?php 
                                // Compute consistent display label: RowLetterNumber (e.g., A1, A10)
                                $rowLetter = chr(64 + (int)($plot['row_number'] ?? 1));
                                $displayPlot = $rowLetter . $plot['plot_number'];
                                echo htmlspecialchars($displayPlot); 
                                    ?></strong>
                                </td>
                            <td><?php echo htmlspecialchars($plot['section_name']); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $plot['status'] === 'available' ? 'bg-success' : 
                                        ($plot['status'] === 'reserved' ? 'bg-warning' : 'bg-danger'); 
                                ?>">
                                    <?php echo ucfirst(htmlspecialchars($plot['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $deceasedCount = (int)($plot['deceased_count'] ?? 0);
                                $deceasedNamesRaw = $plot['deceased_names'] ?? '';
                                if ($deceasedCount > 0 && $deceasedNamesRaw !== ''):
                                    $namesArray = array_map('trim', explode(',', $deceasedNamesRaw));
                                    $displayNames = $namesArray;
                                    if ($deceasedCount > 2) {
                                        $displayNames = array_slice($namesArray, 0, 2);
                                    }
                                    $label = implode(', ', $displayNames);
                                    if ($deceasedCount > 2) {
                                        $label .= ' +' . ($deceasedCount - 2) . ' more';
                                    }
                                ?>
                                <div class="deceased-info" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; text-align:center;">
                                    <span style="position: relative; display: inline-flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-person-fill"></i>
                                        <?php if ($deceasedCount > 0): ?>
                                            <span style="
                                                position: absolute;
                                                top: -6px;
                                                right: -10px;
                                                background: #dc3545;
                                                color: #fff;
                                                border-radius: 999px;
                                                font-size: 10px;
                                                line-height: 1;
                                                padding: 2px 5px;
                                                min-width: 16px;
                                                text-align: center;
                                                border: 2px solid #fff;
                                            ">
                                                <?php echo $deceasedCount; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                </div>
                                <?php else: ?>
                                <div class="deceased-info" style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                                    <i class="bi bi-person"></i>
                                    No deceased information
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn-action btn-view" href="plot_details.php?id=<?php echo $plot['plot_id']; ?>" title="View plot details">
                                        <i class="bi bi-eye"></i> View Details
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- Modern Pagination -->
            <div class="pagination-modern">
                <div class="pagination-info">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $plots_per_page, $total_plots); ?> of <?php echo $total_plots; ?> plots
                </div>
                <div class="pagination-controls-wrapper">
                    <div class="pagination-controls">
                        <!-- Previous buttons -->
                        <?php if ($page > 1): ?>
                            <?php 
                            // Calculate pages to jump based on plot count (not page count)
                            // Since plots_per_page = 10, jumping 500 plots = 50 pages, 100 plots = 10 pages, 50 plots = 5 pages
                            $jump_500_plots = ceil(500 / $plots_per_page); // 50 pages
                            $jump_100_plots = ceil(100 / $plots_per_page); // 10 pages
                            $jump_50_plots = ceil(50 / $plots_per_page); // 5 pages
                            ?>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(max(1, $page - $jump_500_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn" style="background: var(--gray-100); color: var(--gray-700);" title="Go back 500 plots">
                                <i class="bi bi-chevron-double-left"></i> -500
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(max(1, $page - $jump_100_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn" style="background: var(--gray-100); color: var(--gray-700);" title="Go back 100 plots">
                                <i class="bi bi-chevron-left"></i> -100
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(max(1, $page - $jump_50_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn" style="background: var(--gray-100); color: var(--gray-700);" title="Go back 50 plots">
                                <i class="bi bi-chevron-left"></i> -50
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl($page - 1, $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern" style="background: var(--gray-100); color: var(--gray-700);">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <!-- Next buttons -->
                        <?php if ($page < $total_pages): ?>
                            <?php 
                            // Calculate pages to jump based on plot count (not page count)
                            $jump_50_plots = ceil(50 / $plots_per_page); // 5 pages
                            $jump_100_plots = ceil(100 / $plots_per_page); // 10 pages
                            $jump_500_plots = ceil(500 / $plots_per_page); // 50 pages
                            ?>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl($page + 1, $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern btn-primary-modern">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(min($total_pages, $page + $jump_50_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn btn-primary-modern" title="Go forward 50 plots">
                                +50 <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(min($total_pages, $page + $jump_100_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn btn-primary-modern" title="Go forward 100 plots">
                                +100 <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="<?php echo htmlspecialchars(buildPaginationUrl(min($total_pages, $page + $jump_500_plots), $section_filter, $row_filter, $status_filter)); ?>" class="btn-modern pagination-jump-btn btn-primary-modern" title="Go forward 500 plots">
                                +500 <i class="bi bi-chevron-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Toggle plot sections dropdown
            function togglePlotSections(element, event) {
                if (event) {
                    event.stopPropagation();
                    event.preventDefault();
                }
                
                const container = document.getElementById('plotSectionsContainer');
                if (container) {
                    // Determine whether we are about to collapse or expand
                    const willCollapse = !container.classList.contains('collapsed');
                    container.classList.toggle('collapsed');

                    // If user collapses the PLOT SECTIONS card,
                    // also collapse the dependent step cards (ROW and STATUS)
                    if (willCollapse) {
                        const rowContainer = document.getElementById('filterRowContainer');
                        const statusContainer = document.getElementById('filterStatusContainer');
                        if (rowContainer) rowContainer.classList.add('collapsed');
                        if (statusContainer) statusContainer.classList.add('collapsed');
                    }
                }
            }

            // Toggle filter row dropdown - ONLY affects FILTER BY ROW container
            function toggleFilterRow(element, event) {
                if (event) {
                    event.stopPropagation();
                    event.preventDefault();
                }
                
                // ONLY target the filter row container - do NOT touch any other containers
                const container = document.getElementById('filterRowContainer');
                if (container) {
                    container.classList.toggle('collapsed');
                }
                // End of function - no other containers are modified
            }

            // Toggle filter status dropdown - ONLY affects PLOT STATUS container
            function toggleFilterStatus(element, event) {
                if (event) {
                    event.stopPropagation();
                    event.preventDefault();
                }
                
                // ONLY target the filter status container - do NOT touch any other containers
                const container = document.getElementById('filterStatusContainer');
                if (container) {
                    container.classList.toggle('collapsed');
                }
                // End of function - no other containers are modified
            }

            // Section selection function (STEP 1: Section → then show Rows)
            function selectSection(sectionId, evt) {
                // Prevent event bubbling to avoid collapsing container
                if (evt) {
                    evt.stopPropagation();
                    // Don't prevent default - let the click work
                } else if (typeof event !== 'undefined' && event) {
                    event.stopPropagation();
                    // Don't prevent default - let the click work
                }
                
                // CRITICAL: Ensure plot sections container stays expanded BEFORE any other action
                const plotSectionsContainer = document.getElementById('plotSectionsContainer');
                if (plotSectionsContainer) {
                    plotSectionsContainer.classList.remove('collapsed');
                }
                
                const sectionFilterInput = document.getElementById('section_filter');
                const rowFilterInput = document.getElementById('row_filter');
                const statusFilterInput = document.getElementById('status_filter');
                const filtersForm = document.getElementById('filtersForm');
                const filterRowContainer = document.getElementById('filterRowContainer');
                const filterStatusContainer = document.getElementById('filterStatusContainer');
                
                // Update hidden input
                sectionFilterInput.value = sectionId;
                
                // Reset row and status filters when section changes
                rowFilterInput.value = '';
                if (statusFilterInput) {
                    statusFilterInput.value = '';
                }
                
                // Update active states
                document.querySelectorAll('.section-card').forEach(card => {
                    if (card.dataset.sectionId === sectionId || (sectionId === '' && card.dataset.sectionId === '')) {
                        card.classList.add('active');
                    } else {
                        card.classList.remove('active');
                    }
                });
                
                // Ensure container is still expanded before submit
                if (plotSectionsContainer) {
                    plotSectionsContainer.classList.remove('collapsed');
                }

                // STEP BEHAVIOR:
                // After choosing a section:
                //  - show the Row filter (expand)
                //  - hide the Status filter until a row is chosen
                if (filterRowContainer) {
                    filterRowContainer.classList.remove('collapsed');
                }
                if (filterStatusContainer) {
                    // Only collapse when a specific section is chosen;
                    // if "All Sections" is selected we leave status behavior to URL params.
                    if (sectionId !== '') {
                        filterStatusContainer.classList.add('collapsed');
                    }
                }
                
                // Set expand parameter ONLY when "All Sections" is selected (empty sectionId)
                // When a specific section is selected, container expands based on filter value, not expand parameter
                const expandSectionsInput = document.getElementById('expand_sections');
                if (expandSectionsInput) {
                    if (sectionId === '') {
                        // "All Sections" selected - keep container expanded
                        expandSectionsInput.value = '1';
                    } else {
                        // Specific section selected - don't use expand parameter
                        expandSectionsInput.value = '0';
                    }
                }
                
                // Submit form
                filtersForm.submit();
            }

            // Filter button functionality
            document.addEventListener('DOMContentLoaded', function() {
                const filtersForm = document.getElementById('filtersForm');
                const rowFilterInput = document.getElementById('row_filter');
                const statusFilterInput = document.getElementById('status_filter');

                // Get container references
                const plotSectionsContainer = document.getElementById('plotSectionsContainer');
                const filterRowContainer = document.getElementById('filterRowContainer');
                const filterStatusContainer = document.getElementById('filterStatusContainer');

                // Ensure containers stay expanded when interacting with content
                // Don't add generic listeners that might interfere - let specific handlers work
                
                // Ensure section cards work properly - handle clicks via JavaScript
                document.querySelectorAll('.section-card-content').forEach(card => {
                    card.addEventListener('click', function(e) {
                        e.stopPropagation(); // Prevent collapsing
                        // Ensure container stays expanded
                        const container = this.closest('.dropdown-container');
                        if (container) {
                            container.classList.remove('collapsed');
                        }
                        // Get section ID from parent card
                        const sectionCard = this.closest('.section-card');
                        const sectionId = sectionCard ? (sectionCard.dataset.sectionId || '') : '';
                        // Call selectSection
                        selectSection(sectionId, e);
                    });
                });
                
                // Also prevent button clicks from interfering
                document.querySelectorAll('.section-card-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        // Trigger parent card click
                        const cardContent = this.closest('.section-card-content');
                        if (cardContent) {
                            cardContent.click();
                        }
                    });
                });

                // Handle row filter button clicks
                document.querySelectorAll('.row-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation(); // Prevent collapsing, but don't prevent default
                        
                        // CRITICAL: Ensure filter row container stays expanded BEFORE any other action
                        if (filterRowContainer) {
                            filterRowContainer.classList.remove('collapsed');
                        }
                        
                        // Remove active class from all row buttons
                        document.querySelectorAll('.row-btn').forEach(b => b.classList.remove('active'));
                        // Add active class to clicked button
                        this.classList.add('active');
                        
                        // Update hidden input
                        const rowValue = this.dataset.row || '';
                        rowFilterInput.value = rowValue;
                        
                        // Update row info text
                        const rowInfoText = document.getElementById('row-info-text');
                        if (rowInfoText) {
                            if (rowValue === '' || rowValue === 'all') {
                                rowInfoText.textContent = 'Showing all rows';
                            } else {
                                const rowLetter = String.fromCharCode(64 + parseInt(rowValue));
                                rowInfoText.textContent = 'Showing Row ' + rowLetter;
                            }
                        }
                        
                        // Ensure container is still expanded before submit
                        if (filterRowContainer) {
                            filterRowContainer.classList.remove('collapsed');
                        }

                        // STEP BEHAVIOR:
                        // After choosing a row, automatically reveal the Status step.
                        if (filterStatusContainer) {
                            filterStatusContainer.classList.remove('collapsed');
                        }
                        
                        // Set expand parameter to keep container expanded when any row is selected (including "All Rows")
                        const expandRowsInput = document.getElementById('expand_rows');
                        if (expandRowsInput) {
                            expandRowsInput.value = '1';
                        }
                        
                        // Submit form
                        filtersForm.submit();
                    });
                });

                // Handle status filter button clicks
                document.querySelectorAll('.status-filter-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation(); // Prevent collapsing, but don't prevent default
                        
                        // CRITICAL: Ensure filter status container stays expanded BEFORE any other action
                        if (filterStatusContainer) {
                            filterStatusContainer.classList.remove('collapsed');
                        }
                        
                        // Remove active class from all status buttons
                        document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
                        // Add active class to clicked button
                        this.classList.add('active');
                        
                        // Update hidden input
                        const statusValue = this.dataset.status || '';
                        statusFilterInput.value = statusValue;
                        
                        // Ensure container is still expanded before submit
                        if (filterStatusContainer) {
                            filterStatusContainer.classList.remove('collapsed');
                        }
                        
                        // Set expand parameter ONLY when "All Status" is selected (empty statusValue)
                        // When a specific status is selected, container expands based on filter value, not expand parameter
                        const expandStatusInput = document.getElementById('expand_status');
                        if (expandStatusInput) {
                            if (statusValue === '') {
                                // "All Status" selected - keep container expanded
                                expandStatusInput.value = '1';
                            } else {
                                // Specific status selected - don't use expand parameter
                                expandStatusInput.value = '0';
                            }
                        }
                        
                        // Submit form
                        filtersForm.submit();
                    });
                });

                // Expand containers that have active filters on page load
                const sectionFilter = document.getElementById('section_filter')?.value;
                const rowFilter = document.getElementById('row_filter')?.value;
                const statusFilter = document.getElementById('status_filter')?.value;

                // STEP INITIAL STATE (on page load)
                // Respect the server-rendered collapsed/expanded state by default.
                // Only override when filters are present so the steps remain logical.

                // If a section is selected but no row yet → ensure Rows open, Status closed.
                if (sectionFilter && !rowFilter) {
                    if (filterRowContainer) {
                        filterRowContainer.classList.remove('collapsed');
                    }
                    if (filterStatusContainer) {
                        filterStatusContainer.classList.add('collapsed');
                    }
                }

                // If a row is selected → ensure both Rows and Status are open.
                if (rowFilter) {
                    if (filterRowContainer) {
                        filterRowContainer.classList.remove('collapsed');
                    }
                    if (filterStatusContainer) {
                        filterStatusContainer.classList.remove('collapsed');
                    }
                }

                // If only status filter is set via URL → make sure Status is visible.
                if (!sectionFilter && !rowFilter && statusFilter) {
                    if (filterStatusContainer) {
                        filterStatusContainer.classList.remove('collapsed');
                    }
                }
            });

            // Initialize map
            let map;
            let startMarker = null;
            let endMarker = null;
            let rectangle = null;
            let isSelectingStart = true;
            let isDragging = false;
            let dragStartPoint = null;
            let originalBounds = null;

            // Modern status change confirmation with better UX
            function confirmStatusChange(selectElement) {
                const plotRow = selectElement.closest('tr');
                const plotNumber = plotRow.querySelector('td:first-child').textContent.trim();
                const newStatus = selectElement.value;
                const oldStatus = selectElement.options[selectElement.selectedIndex].text;
                
                // Add loading state
                selectElement.disabled = true;
                selectElement.style.opacity = '0.6';
                
                // Create modern confirmation dialog
                const confirmed = confirm(`Are you sure you want to change the status of plot ${plotNumber} from ${oldStatus} to ${newStatus}?`);
                
                if (confirmed) {
                    // Add loading indicator
                    const loadingSpinner = document.createElement('span');
                    loadingSpinner.className = 'spinner';
                    loadingSpinner.style.marginLeft = '0.5rem';
                    selectElement.parentNode.appendChild(loadingSpinner);
                    
                    // Submit form
                    selectElement.form.submit();
                } else {
                    // Reset to previous value and remove loading state
                    selectElement.value = oldStatus.toLowerCase();
                    selectElement.disabled = false;
                    selectElement.style.opacity = '1';
                }
            }

            // Initialize map when modal is shown
            document.getElementById('addPlotsModal').addEventListener('shown.bs.modal', function () {
                if (!map) {
                    map = L.map('map', {
                        center: [14.265243, 120.864874],
                        zoom: 19,
                        zoomControl: true
                    });
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 21,
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);
                }
                map.invalidateSize();
            });

            // Add click event to map
            document.getElementById('addPlotsModal').addEventListener('shown.bs.modal', function () {
                map.on('click', function(e) {
                    if (isDragging) return;
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    if (isSelectingStart) {
                        document.getElementById('start_lat').value = lat;
                        document.getElementById('start_lng').value = lng;
                        if (startMarker) map.removeLayer(startMarker);
                        startMarker = L.marker(e.latlng).addTo(map);
                        document.getElementById('start-coords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                        isSelectingStart = false;
                    } else {
                        document.getElementById('end_lat').value = lat;
                        document.getElementById('end_lng').value = lng;
                        if (endMarker) map.removeLayer(endMarker);
                        endMarker = L.marker(e.latlng).addTo(map);
                        document.getElementById('end-coords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                        if (rectangle) map.removeLayer(rectangle);
                        const bounds = [
                            [startMarker.getLatLng().lat, startMarker.getLatLng().lng],
                            [endMarker.getLatLng().lat, endMarker.getLatLng().lng]
                        ];
                        rectangle = L.rectangle(bounds, {
                            color: "#ff7800",
                            weight: 1,
                            interactive: true
                        }).addTo(map);
                        rectangle.on('mousedown', function(e) {
                            isDragging = true;
                            dragStartPoint = e.latlng;
                            originalBounds = rectangle.getBounds();
                        });
                        map.on('mousemove', function(e) {
                            if (isDragging && dragStartPoint) {
                                const latDiff = e.latlng.lat - dragStartPoint.lat;
                                const lngDiff = e.latlng.lng - dragStartPoint.lng;
                                const newBounds = [
                                    [originalBounds.getSouth() + latDiff, originalBounds.getWest() + lngDiff],
                                    [originalBounds.getNorth() + latDiff, originalBounds.getEast() + lngDiff]
                                ];
                                rectangle.setBounds(newBounds);
                                startMarker.setLatLng([newBounds[0][0], newBounds[0][1]]);
                                endMarker.setLatLng([newBounds[1][0], newBounds[1][1]]);
                                document.getElementById('start-coords').textContent = 
                                    `Lat: ${newBounds[0][0].toFixed(6)}, Lng: ${newBounds[0][1].toFixed(6)}`;
                                document.getElementById('end-coords').textContent = 
                                    `Lat: ${newBounds[1][0].toFixed(6)}, Lng: ${newBounds[1][1].toFixed(6)}`;
                                document.getElementById('start_lat').value = newBounds[0][0];
                                document.getElementById('start_lng').value = newBounds[0][1];
                                document.getElementById('end_lat').value = newBounds[1][0];
                                document.getElementById('end_lng').value = newBounds[1][1];
                            }
                        });
                        map.on('mouseup', function() {
                            if (isDragging) {
                                isDragging = false;
                                dragStartPoint = null;
                                originalBounds = null;
                            }
                        });
                        isSelectingStart = true;
                    }
                });
            });

            // Reset selection when modal is hidden
            document.getElementById('addPlotsModal').addEventListener('hidden.bs.modal', function () {
                if (startMarker) map.removeLayer(startMarker);
                if (endMarker) map.removeLayer(endMarker);
                if (rectangle) map.removeLayer(rectangle);
                document.getElementById('start-coords').textContent = '-';
                document.getElementById('end-coords').textContent = '-';
                document.getElementById('start_lat').value = '';
                document.getElementById('start_lng').value = '';
                document.getElementById('end_lat').value = '';
                document.getElementById('end_lng').value = '';
                isSelectingStart = true;
            });

            // Form validation
            document.getElementById('plotForm').addEventListener('submit', function(e) {
                const startLat = document.getElementById('start_lat').value;
                const startLng = document.getElementById('start_lng').value;
                const endLat = document.getElementById('end_lat').value;
                const endLng = document.getElementById('end_lng').value;
                const startRow = parseInt(document.getElementById('start_row').value);
                const endRow = parseInt(document.getElementById('end_row').value);
                
                if (!startLat || !startLng || !endLat || !endLng) {
                    e.preventDefault();
                    alert('Please select both start and end points on the map!');
                } else if (startRow > endRow) {
                    e.preventDefault();
                    alert('Start row must be less than or equal to end row!');
                }
            });

            // Initialize Bootstrap modal
            document.addEventListener('DOMContentLoaded', function() {
                var addPlotsModal = new bootstrap.Modal(document.getElementById('addPlotsModal'));
                
                // Add click event to the Add Plots button
                document.querySelector('[data-bs-target="#addPlotsModal"]').addEventListener('click', function() {
                    addPlotsModal.show();
                });
            });

            function showViewModal(plotId) {
                // Redirect to plot details page
                window.location.href = `plot_details.php?id=${plotId}`;
            }

            function showEditModal(plotId) {
                // Redirect to plot details page (edit functionality is now in modal)
                window.location.href = `plot_details.php?id=${plotId}`;
            }

            // Clear search function
            window.clearSearch = function() {
                document.getElementById('search_status').value = '';
                document.getElementById('search_plot').value = '';
                document.getElementById('search_section').value = '';
                const rowSelect = document.getElementById('search_row');
                if (rowSelect) {
                    rowSelect.value = '';
                    rowSelect.disabled = true;
                }
                window.location.href = window.location.pathname;
            };

            // Handle search section change to enable/disable row dropdown
            document.addEventListener('DOMContentLoaded', function() {
                const searchSection = document.getElementById('search_section');
                const searchRow = document.getElementById('search_row');
                
                if (searchSection && searchRow) {
                    searchSection.addEventListener('change', function() {
                        if (this.value === '') {
                            searchRow.disabled = true;
                            searchRow.value = '';
                        } else {
                            // Reload page with new section to get rows
                            this.form.submit();
                        }
                    });
                }
            });

            // Modern interactive features
            document.addEventListener('DOMContentLoaded', function() {
                // Add keyboard shortcuts
                document.addEventListener('keydown', function(e) {
                    // Ctrl/Cmd + K to focus search
                    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                        e.preventDefault();
                        const searchPlot = document.getElementById('search_plot');
                        if (searchPlot) {
                            searchPlot.focus();
                        }
                    }
                });

                // Add smooth scrolling for better UX
                document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                    anchor.addEventListener('click', function (e) {
                        e.preventDefault();
                        const target = document.querySelector(this.getAttribute('href'));
                        if (target) {
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    });
                });

                // Add loading states to form submissions
                document.querySelectorAll('form').forEach(form => {
                    form.addEventListener('submit', function() {
                        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
                        }
                    });
                });

                // Add tooltips for better UX
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });

                // Reset scroll position on page load to prevent layout shift
                window.scrollTo(0, 0);
            });

            // Prevent browser from restoring scroll position
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }
            
            // Immediate scroll reset (before DOM is ready)
            window.scrollTo(0, 0);
        </script>
    </div>
</div>

</body>
</html> 