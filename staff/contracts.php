<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// Run contract maintenance on each page load so expired contracts
// immediately archive deceased records and free plots while keeping
// contract details intact.
require_once __DIR__ . '/contract_maintenance.php';
run_contract_maintenance($conn, false);

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Handle search parameters
$search_name = $_GET['search_name'] ?? '';
$search_plot = $_GET['search_plot'] ?? '';
$search_section = $_GET['search_section'] ?? '';
$search_row = $_GET['search_row'] ?? '';
$contract_status = $_GET['contract_status'] ?? '';

// Handle sorting parameters
$sort_by = $_GET['sort_by'] ?? '';
$sort_order = $_GET['sort_order'] ?? 'asc';

// Detect which deceased table to use
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
if ($table_check) {
    mysqli_free_result($table_check);
}

// Check if archive table exists (used only for displaying names of archived records)
$archive_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_deceased_records'");
$has_archive_table = $archive_check && mysqli_num_rows($archive_check) > 0;
if ($archive_check) {
    mysqli_free_result($archive_check);
}

// Check if deceased_records has an address column (optional)
$has_address_column = false;
if ($use_deceased_records) {
    $address_check = mysqli_query($conn, "SHOW COLUMNS FROM deceased_records LIKE 'address'");
    $has_address_column = $address_check && mysqli_num_rows($address_check) > 0;
}

// Add pagination
$records_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build current URL with query parameters for "from" parameter
$current_url_params = $_GET;
unset($current_url_params['success']); // Remove success message from URL
$current_url = 'contracts.php' . (!empty($current_url_params) ? '?' . http_build_query($current_url_params) : '');

// Fetch archived contracts for modal
$archived_contracts = [];
$archived_total_count = 0;
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_contracts'");
$has_archived_table = $table_check && mysqli_num_rows($table_check) > 0;
if ($table_check) {
    mysqli_free_result($table_check);
}

if ($has_archived_table) {
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM archived_contracts";
    $count_result = mysqli_query($conn, $count_query);
    if ($count_result) {
        $count_row = mysqli_fetch_assoc($count_result);
        $archived_total_count = (int)$count_row['total'];
        mysqli_free_result($count_result);
    }
    
    // Fetch all archived contracts (will be paginated client-side)
    $archived_query = "SELECT * FROM archived_contracts ORDER BY archived_at DESC";
    $archived_result = mysqli_query($conn, $archived_query);
    if ($archived_result) {
        while ($archived = mysqli_fetch_assoc($archived_result)) {
            $archived_contracts[] = $archived;
        }
    }
}

// Build search query for contracts
if ($use_deceased_records) {
    $address_select = $has_address_column ? "d.address AS address,\n                " : "";

    // Ensure only one active/archived deceased row is joined per plot to avoid duplicates
    $active_join = "LEFT JOIN deceased_records d ON d.record_id = (
                SELECT dr.record_id
                FROM deceased_records dr
                WHERE dr.plot_id = p.plot_id
                ORDER BY dr.burial_date DESC, dr.record_id DESC
                LIMIT 1
              )";
    $archived_join = $has_archive_table ? "LEFT JOIN archived_deceased_records ad ON ad.deceased_id = (
                SELECT ad2.deceased_id
                FROM archived_deceased_records ad2
                WHERE ad2.plot_id = p.plot_id
                ORDER BY ad2.date_of_burial DESC, ad2.deceased_id DESC
                LIMIT 1
              )" : "";

    // When using the modern deceased_records table, we still want to show
    // contracts even after a record has been archived. To do that we:
    // - Base the main query on plots (not deceased_records)
    // - LEFT JOIN both deceased_records (active) and archived_deceased_records (archived)
    // - Use COALESCE to derive display name / burial date from whichever table has data
    $name_expr = "COALESCE(d.full_name, " . ($has_archive_table ? "CONCAT(ad.first_name, ' ', ad.last_name)" : "NULL") . ")";
    $burial_expr = "COALESCE(d.burial_date" . ($has_archive_table ? ", ad.date_of_burial" : "") . ")";
    $record_id_expr = "COALESCE(d.record_id" . ($has_archive_table ? ", ad.deceased_id" : "") . ")";

    $date_of_birth_expr = "COALESCE(d.date_of_birth" . ($has_archive_table ? ", ad.date_of_birth" : "") . ")";
    $date_of_death_expr = "COALESCE(d.date_of_death" . ($has_archive_table ? ", ad.date_of_death" : "") . ")";
    $next_of_kin_expr = "COALESCE(d.next_of_kin" . ($has_archive_table ? ", NULL" : "") . ")";
    $contact_number_expr = "COALESCE(d.contact_number" . ($has_archive_table ? ", NULL" : "") . ")";
    
    $query = "SELECT 
                {$record_id_expr} AS record_id,
                {$name_expr} AS display_name,
                {$burial_expr} AS date_of_burial,
                {$date_of_birth_expr} AS date_of_birth,
                {$date_of_death_expr} AS date_of_death,
                {$next_of_kin_expr} AS next_of_kin,
                {$contact_number_expr} AS contact_number,
                {$address_select}p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                (SELECT COUNT(*) FROM plots p2 
                 WHERE p2.section_id = p.section_id 
                 AND p2.plot_id <= p.plot_id) as lot_number
              FROM plots p
              {$active_join} " .
              ($has_archive_table ? "{$archived_join} " : "") . "
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE 1=1
                AND (
                    d.record_id IS NOT NULL" . ($has_archive_table ? " OR ad.deceased_id IS NOT NULL" : "") . "
                )";
} else {
    $query = "SELECT 
                d.deceased_id AS record_id,
                CONCAT(d.first_name, ' ', d.last_name) AS display_name,
                d.date_of_burial AS date_of_burial,
                d.date_of_birth AS date_of_birth,
                d.date_of_death AS date_of_death,
                d.next_of_kin_contact AS next_of_kin,
                d.emergency_contact AS contact_number,
                p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                (SELECT COUNT(*) FROM plots p2 
                 WHERE p2.section_id = p.section_id 
                 AND p2.plot_id <= p.plot_id) as lot_number
              FROM deceased d
              JOIN plots p ON d.plot_id = p.plot_id
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.status = 'occupied' AND 1=1";
}

$params = [];
$types = '';

if (!empty($search_name)) {
    if ($use_deceased_records) {
        // Match against either active deceased_records or archived_deceased_records names
        $query .= " AND ";
        if ($has_archive_table) {
            $query .= "COALESCE(d.full_name, CONCAT(ad.first_name, ' ', ad.last_name)) LIKE ?";
        } else {
            $query .= "d.full_name LIKE ?";
        }
        $params[] = "%$search_name%";
        $types .= 's';
    } else {
        $query .= " AND (d.first_name LIKE ? OR d.last_name LIKE ?)";
        $params[] = "%$search_name%";
        $params[] = "%$search_name%";
        $types .= 'ss';
    }
}

if (!empty($search_plot)) {
    $query .= " AND p.plot_number LIKE ?";
    $params[] = "%$search_plot%";
    $types .= 's';
}

if (!empty($search_section)) {
    $query .= " AND s.section_name LIKE ?";
    $params[] = "%$search_section%";
    $types .= 's';
}

if (!empty($search_row)) {
    // Convert row letter to number if it's a letter (A=1, B=2, etc.)
    $row_search = trim(strtoupper($search_row));
    if (preg_match('/^[A-Z]+$/', $row_search)) {
        // It's a letter, convert to number
        $row_number = 0;
        $len = strlen($row_search);
        for ($i = 0; $i < $len; $i++) {
            $row_number = $row_number * 26 + (ord($row_search[$i]) - ord('A') + 1);
        }
        $query .= " AND p.row_number = ?";
        $params[] = $row_number;
        $types .= 'i';
    } else if (is_numeric($row_search)) {
        // It's a number
        $query .= " AND p.row_number = ?";
        $params[] = (int)$row_search;
        $types .= 'i';
    }
}

if (!empty($contract_status)) {
    if ($contract_status === 'expired') {
        $query .= " AND p.contract_status = 'expired'";
    } else {
        $query .= " AND p.contract_status = ?";
        $params[] = $contract_status;
        $types .= 's';
        // Exclude expired when searching for non-expired statuses
        $query .= " AND p.contract_status != 'expired'";
    }
} else {
    // Default view hides expired contracts
    $query .= " AND p.contract_status != 'expired'";
}

// Handle sorting - removed expired contract prioritization since they're excluded
if ($sort_by === 'plot_location') {
    $order_direction = ($sort_order === 'desc') ? 'DESC' : 'ASC';
    $query .= " ORDER BY 
                    (p.contract_status = 'renewal_needed') DESC,
                    s.section_name $order_direction, 
                    lot_number $order_direction";
} else {
    if ($use_deceased_records) {
        $query .= " ORDER BY 
                    (p.contract_status = 'renewal_needed') DESC,
                    s.section_name ASC, 
                    lot_number ASC";
    } else {
        $query .= " ORDER BY 
                    (p.contract_status = 'renewal_needed') DESC,
                    s.section_name ASC, 
                    lot_number ASC";
    }
}

// Get total count for pagination using a safe subquery (avoids messing with SELECT list)
$count_base_query = preg_replace('/ORDER BY.*$/i', '', $query);
$count_query = "SELECT COUNT(*) as total FROM ({$count_base_query}) AS contract_sub";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $count_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $count_result = mysqli_stmt_get_result($stmt);
        $total_records = mysqli_fetch_assoc($count_result)['total'];
    } else {
        $total_records = 0;
    }
} else {
    $count_result = mysqli_query($conn, $count_query);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Add limit and offset to main query
$query .= " LIMIT $records_per_page OFFSET $offset";

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
    $result = mysqli_query($conn, $query);
}

// Fetch expired contracts only for alert banner
$priority_result = null;
$priority_total = 0;

if ($use_deceased_records) {
    $priority_address_select = $has_address_column ? "d.address AS address,\n                " : "";
    $priority_select = "SELECT 
                d.record_id AS record_id,
                d.full_name AS display_name,
                d.burial_date AS date_of_burial,
                d.date_of_birth AS date_of_birth,
                d.date_of_death AS date_of_death,
                d.next_of_kin AS next_of_kin,
                d.contact_number AS contact_number,
                {$priority_address_select}p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                (SELECT COUNT(*) FROM plots p2 
                 WHERE p2.section_id = p.section_id 
                 AND p2.plot_id <= p.plot_id) as lot_number";
    $priority_from_where = " FROM deceased_records d
              JOIN plots p ON d.plot_id = p.plot_id
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.status = 'occupied'
                AND p.contract_status = 'expired'";
} else {
    $priority_select = "SELECT 
                d.deceased_id AS record_id,
                CONCAT(d.first_name, ' ', d.last_name) AS display_name,
                d.date_of_burial AS date_of_burial,
                d.date_of_birth AS date_of_birth,
                d.date_of_death AS date_of_death,
                d.next_of_kin_contact AS next_of_kin,
                d.emergency_contact AS contact_number,
                p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                (SELECT COUNT(*) FROM plots p2 
                 WHERE p2.section_id = p.section_id 
                 AND p2.plot_id <= p.plot_id) as lot_number";
    $priority_from_where = " FROM deceased d
              JOIN plots p ON d.plot_id = p.plot_id
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.status = 'occupied'
                AND p.contract_status = 'expired'";
}

// Count total expired contracts
$priority_count_query = "SELECT COUNT(*) as total" . $priority_from_where;
$priority_count_result = mysqli_query($conn, $priority_count_query);
if ($priority_count_result) {
    $priority_total = (int) mysqli_fetch_assoc($priority_count_result)['total'];
}

// Fetch all expired contracts (no pagination, will be limited by CSS)
$priority_query = $priority_select . $priority_from_where . "
              ORDER BY 
                p.contract_end_date ASC";

$priority_result = mysqli_query($conn, $priority_query);

// Fetch expired contracts from archived_contracts table
$archived_expired_contracts = [];
$archived_expired_total = 0;
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_contracts'");
$has_archived_table = $table_check && mysqli_num_rows($table_check) > 0;
if ($table_check) {
    mysqli_free_result($table_check);
}

if ($has_archived_table) {
    // Count archived expired contracts
    $archived_expired_count_query = "SELECT COUNT(*) as total FROM archived_contracts WHERE contract_status = 'expired'";
    $archived_expired_count_result = mysqli_query($conn, $archived_expired_count_query);
    if ($archived_expired_count_result) {
        $archived_expired_total = (int) mysqli_fetch_assoc($archived_expired_count_result)['total'];
    }
    
    // Fetch archived expired contracts with deceased information
    // Join with deceased_records and archived_deceased_records to get date_of_birth, date_of_death, and contact_number
    // Join by record_id first, then fallback to plot_id for archived_deceased_records
    $archived_expired_query = "SELECT 
        ac.*";
    
    if ($use_deceased_records) {
        // Modern table structure: deceased_records uses record_id and contact_number
        $archived_expired_query .= ",
            COALESCE(d.date_of_birth, ad1.date_of_birth, ad2.date_of_birth) AS date_of_birth_from_deceased,
            COALESCE(ac.date_of_death, d.date_of_death, ad1.date_of_death, ad2.date_of_death) AS date_of_death_from_deceased,
            d.contact_number AS contact_number_from_deceased
        FROM archived_contracts ac
        LEFT JOIN deceased_records d ON d.record_id = ac.record_id";
        if ($has_archive_table) {
            // Join archived_deceased_records by record_id first, then by plot_id as fallback
            $archived_expired_query .= " LEFT JOIN archived_deceased_records ad1 ON ad1.deceased_id = ac.record_id
            LEFT JOIN archived_deceased_records ad2 ON ad2.plot_id = ac.plot_id AND ad1.deceased_id IS NULL";
        }
    } else {
        // Old table structure: uses deceased_id and emergency_contact
        $archived_expired_query .= ",
            COALESCE(d.date_of_birth, ad1.date_of_birth, ad2.date_of_birth) AS date_of_birth_from_deceased,
            COALESCE(ac.date_of_death, d.date_of_death, ad1.date_of_death, ad2.date_of_death) AS date_of_death_from_deceased,
            d.emergency_contact AS contact_number_from_deceased
        FROM archived_contracts ac
        LEFT JOIN deceased d ON d.deceased_id = ac.record_id";
        if ($has_archive_table) {
            // Join archived_deceased_records by record_id first, then by plot_id as fallback
            $archived_expired_query .= " LEFT JOIN archived_deceased_records ad1 ON ad1.deceased_id = ac.record_id
            LEFT JOIN archived_deceased_records ad2 ON ad2.plot_id = ac.plot_id AND ad1.deceased_id IS NULL";
        }
    }
    
    $archived_expired_query .= " WHERE ac.contract_status = 'expired' 
        ORDER BY ac.contract_end_date ASC, ac.archived_at DESC 
        LIMIT 100";
    $archived_expired_result = mysqli_query($conn, $archived_expired_query);
    if ($archived_expired_result) {
        while ($archived_expired = mysqli_fetch_assoc($archived_expired_result)) {
            $archived_expired_contracts[] = $archived_expired;
        }
    }
}

// Fetch recent archived contracts (all statuses) for display on main page
$recent_archived_contracts = [];
$recent_archived_total = 0;
if ($has_archived_table) {
    // Count all archived contracts
    $recent_archived_count_query = "SELECT COUNT(*) as total FROM archived_contracts";
    $recent_archived_count_result = mysqli_query($conn, $recent_archived_count_query);
    if ($recent_archived_count_result) {
        $recent_archived_total = (int) mysqli_fetch_assoc($recent_archived_count_result)['total'];
    }
    
    // Fetch recent archived contracts (all statuses) with deceased information
    $recent_archived_query = "SELECT 
        ac.*";
    
    if ($use_deceased_records) {
        // Modern table structure: deceased_records uses record_id and contact_number
        $recent_archived_query .= ",
            COALESCE(d.date_of_birth, ad1.date_of_birth, ad2.date_of_birth) AS date_of_birth_from_deceased,
            COALESCE(ac.date_of_death, d.date_of_death, ad1.date_of_death, ad2.date_of_death) AS date_of_death_from_deceased,
            d.contact_number AS contact_number_from_deceased
        FROM archived_contracts ac
        LEFT JOIN deceased_records d ON d.record_id = ac.record_id";
        if ($has_archive_table) {
            // Join archived_deceased_records by record_id first, then by plot_id as fallback
            $recent_archived_query .= " LEFT JOIN archived_deceased_records ad1 ON ad1.deceased_id = ac.record_id
            LEFT JOIN archived_deceased_records ad2 ON ad2.plot_id = ac.plot_id AND ad1.deceased_id IS NULL";
        }
    } else {
        // Old table structure: uses deceased_id and emergency_contact
        $recent_archived_query .= ",
            COALESCE(d.date_of_birth, ad1.date_of_birth, ad2.date_of_birth) AS date_of_birth_from_deceased,
            COALESCE(ac.date_of_death, d.date_of_death, ad1.date_of_death, ad2.date_of_death) AS date_of_death_from_deceased,
            d.emergency_contact AS contact_number_from_deceased
        FROM archived_contracts ac
        LEFT JOIN deceased d ON d.deceased_id = ac.record_id";
        if ($has_archive_table) {
            // Join archived_deceased_records by record_id first, then by plot_id as fallback
            $recent_archived_query .= " LEFT JOIN archived_deceased_records ad1 ON ad1.deceased_id = ac.record_id
            LEFT JOIN archived_deceased_records ad2 ON ad2.plot_id = ac.plot_id AND ad1.deceased_id IS NULL";
        }
    }
    
    $recent_archived_query .= " ORDER BY ac.archived_at DESC LIMIT 15";
    $recent_archived_result = mysqli_query($conn, $recent_archived_query);
    if ($recent_archived_result) {
        while ($recent_archived = mysqli_fetch_assoc($recent_archived_result)) {
            $recent_archived_contracts[] = $recent_archived;
        }
    }
}

// Helpers
function numberToLetter($number) {
    if (!$number || $number <= 0) {
        return '';
    }
    $letter = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $number = intval(($number - 1) / 26);
    }
    return $letter;
}
function formatDisplayDate($date) {
    if (!$date || $date === '0000-00-00') {
        return null;
    }
    return date('M d, Y', strtotime($date));
}
function calculateYearsBetween($startDate, $endDate) {
    if (!$startDate || !$endDate || $startDate === '0000-00-00' || $endDate === '0000-00-00') {
        return null;
    }
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) {
            return null;
        }
        return $start->diff($end)->y;
    } catch (Exception $e) {
        return null;
    }
}
function isValidDateValue($date) {
    return !empty($date) && $date !== '0000-00-00';
}
function calculateContractYears($startDate, $endDate, $burialDate, $contractType) {
    // Primary: if both dates exist, use exact difference
    $years = calculateYearsBetween($startDate, $endDate);
    if ($years !== null) {
        return $years;
    }
    // Fallbacks:
    // 1) If only start date exists and contract type implies fixed duration (default 5 years)
    if (isValidDateValue($startDate) && !isValidDateValue($endDate)) {
        // For now, default to 5 years for temporary/unspecified contracts
        return 5;
    }
    // 2) If only end date exists, derive from burial date when available
    if (!isValidDateValue($startDate) && isValidDateValue($endDate) && isValidDateValue($burialDate)) {
        $years = calculateYearsBetween($burialDate, $endDate);
        if ($years !== null) {
            return $years;
        }
    }
    // 3) If neither contract date exists but burial date is present, assume default 5-year contract
    if (!isValidDateValue($startDate) && !isValidDateValue($endDate) && isValidDateValue($burialDate)) {
        return 5;
    }
    return null;
}
function deriveRenewalReminderDate($renewalRaw, $endRaw, $startRaw, $burialRaw) {
    if (isValidDateValue($renewalRaw)) {
        return formatDisplayDate($renewalRaw);
    }
    try {
        if (isValidDateValue($endRaw)) {
            $end = new DateTime($endRaw);
        } else {
            $base = null;
            if (isValidDateValue($startRaw)) {
                $base = new DateTime($startRaw);
            } elseif (isValidDateValue($burialRaw)) {
                $base = new DateTime($burialRaw);
            } else {
                return null;
            }
            $end = clone $base;
            $end->modify('+5 years');
        }
        $reminder = clone $end;
        $reminder->modify('-30 days');
        return $reminder->format('M d, Y');
    } catch (Exception $e) {
        return null;
    }
}
/**
 * Check if a contract is expired but within the 7-day grace period before archiving
 * @param string|null $endDate Contract end date
 * @param string|null $status Current contract status
 * @return array{is_pending: bool, days_remaining: int|null} Returns array with is_pending flag and days remaining until archive
 */
function getPendingArchiveStatus($endDate, $status) {
    if (!$endDate || $endDate === '0000-00-00' || $status !== 'expired') {
        return ['is_pending' => false, 'days_remaining' => null];
    }
    
    try {
        $end = new DateTime($endDate);
        $today = new DateTime();
        $archiveDate = clone $end;
        $archiveDate->modify('+7 days');
        
        // If today is before the archive date (within 7 days of expiry), it's pending
        if ($today < $archiveDate && $today >= $end) {
            $daysRemaining = $today->diff($archiveDate)->days;
            return ['is_pending' => true, 'days_remaining' => $daysRemaining];
        }
        
        return ['is_pending' => false, 'days_remaining' => null];
    } catch (Exception $e) {
        return ['is_pending' => false, 'days_remaining' => null];
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Add Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Add Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        :root {
            /* Align primary palette with other staff pages (e.g., dashboard, map) */
            --primary-color: #2b4c7e;
            --primary-dark: #1f3659;
            --secondary-color: #1f3659;
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

        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--light-bg);
            color: var(--gray-800);
            line-height: 1.6;
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #2b4c7e;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
        }

        /* Header */
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
        .page-subtitle { color: var(--gray-500); font-size: 1rem; margin-top: 0.5rem; }
        .btn-archive-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            font-size: 20px;
        }
        .btn-archive-icon:hover {
            background: #e5e7eb;
            color: #1f2937;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            text-decoration: none;
        }
        .btn-archive-icon i {
            font-size: 22px;
        }
        .page-title-icon {
            position: relative;
            display: inline-block;
        }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .notification-badge.large {
            width: 28px;
            height: 28px;
            font-size: 0.8rem;
            top: -10px;
            right: -10px;
        }

        /* Cards */
        .card { background: var(--white); border-radius: var(--border-radius-lg); box-shadow: var(--shadow); border: 1px solid var(--gray-200); overflow: hidden; }
        .card-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--gray-200); background: var(--gray-50); }
        .card-title { font-size: 1.25rem; font-weight: 600; color: var(--gray-900); margin: 0; }
        .card-body { padding: 2rem; }

        /* Filters */
        .filters-container { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 0.5rem; min-width: 200px; }
        .filter-label { font-weight: 600; color: var(--gray-700); font-size: 0.875rem; }
        .form-control-modern { padding: 0.75rem 1rem; border: 2px solid var(--gray-200); border-radius: var(--border-radius); font-size: 0.875rem; transition: all 0.2s ease; background: var(--white); }
        .form-control-modern:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .btn-modern { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem; border-radius: var(--border-radius); font-weight: 600; font-size: 0.875rem; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s ease; box-shadow: var(--shadow-sm); }
        .btn-primary-modern { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; }
        .btn-primary-modern:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); color: white; }
        .btn-neutral { background: var(--gray-100); color: var(--gray-700); }
        .btn-neutral:hover { background: var(--gray-200); color: var(--gray-800); }

        /* Table */
        .table-modern { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--white); border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow-sm); }
        .table-modern thead { background: linear-gradient(135deg, var(--gray-50), var(--gray-100)); }
        .table-modern th { padding: 1rem 1.5rem; text-align: left; font-weight: 600; color: var(--gray-700); font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid var(--gray-200); font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .table-modern td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .table-modern tbody tr { transition: all 0.2s ease; }
        .table-modern tbody tr:hover { background: var(--gray-50); }
        .table-modern tbody tr.expired-row { 
            background: #fef2f2; 
            border-left: 4px solid var(--danger-color);
        }
        .table-modern tbody tr.expired-row:hover { 
            background: #fee2e2; 
        }

        /* Priority alert for expiring contracts */
        .priority-alert-card {
            border-radius: var(--border-radius-lg);
            border: 1px solid rgba(239, 68, 68, 0.3);
            background: #fef2f2;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        
        /* Two-column container for expired and archived contracts */
        .contracts-two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 1200px) {
            .contracts-two-column {
                grid-template-columns: 1fr;
            }
        }
        
        .contracts-two-column .priority-alert-card {
            margin-bottom: 0;
        }
        .priority-alert-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            gap: 0.75rem;
        }
        .priority-alert-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--danger-color);
        }
        .priority-alert-subtitle {
            font-size: 0.85rem;
            color: var(--gray-600);
        }
        .priority-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 0.6rem;
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 8px;
            scrollbar-width: thin;
            scrollbar-color: var(--gray-400) var(--gray-100);
        }
        .priority-list::-webkit-scrollbar {
            width: 8px;
        }
        .priority-list::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 4px;
        }
        .priority-list::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 4px;
        }
        .priority-list::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }
        .priority-item {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            padding: 0.75rem 0.9rem;
            border-radius: var(--border-radius);
            background: #ffffff;
            border: 1px solid rgba(239, 68, 68, 0.25);
            box-shadow: var(--shadow-sm);
        }
        .priority-item-main {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .priority-item-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        .priority-item-meta {
            font-size: 0.8rem;
            color: var(--gray-700);
        }
        .priority-reminder-date {
            font-weight: 600;
            color: var(--danger-color);
        }
        .priority-item-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.25rem;
        }
        .priority-item-actions a {
            font-size: 0.75rem;
        }

        /* Status */
        .contract-status { display: inline-block; padding: 0.375rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .contract-status.active { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }
        .contract-status.expired { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
        .contract-status.expired.pending-archive { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .contract-status.renewal-needed { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
        .contract-status.cancelled { background: var(--gray-100); color: var(--gray-700); }
        .pending-archive-info { font-size: 0.7rem; color: #d97706; margin-top: 0.25rem; font-weight: 500; }
        .contract-info { font-size: 0.75rem; color: var(--gray-600); margin-top: 0.25rem; }

        /* Actions - Consistent Button System */
        .action-buttons { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 0.5rem; 
            flex-wrap: wrap;
        }
        /* Unified action button sizing so all actions look consistent */
        .btn-action { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            gap: 0.375rem; 
            /* Fixed size for all action buttons */
            width: 90px;
            height: 34px;
            padding: 0; 
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
            background: var(--primary-color); 
            color: white; 
        }
        .btn-edit:hover { 
            background: var(--primary-dark); 
            color: white; 
        }
        .btn-approve { 
            background: var(--success-color); 
            color: white; 
        }
        .btn-approve:hover { 
            background: #059669; 
            color: white; 
        }
        .btn-reject { 
            background: var(--danger-color); 
            color: white; 
        }
        .btn-reject:hover { 
            background: #dc2626; 
            color: white; 
        }
        .btn-delete { 
            background: var(--danger-color); 
            color: white; 
        }
        .btn-delete:hover { 
            background: #dc2626; 
            color: white; 
        }
        
        /* Table action column alignment */
        .table-modern td:last-child,
        .table-modern th:last-child {
            text-align: center;
        }

        /* Modal */
        .modal-content { border-radius: var(--border-radius-lg); border: none; box-shadow: var(--shadow-xl); }
        .modal-header { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0; border-bottom: none; padding: 1.5rem 2rem; }
        .modal-header .btn-close { filter: invert(1); opacity: 0.8; }
        .modal-header .btn-close:hover { opacity: 1; }
        .modal-body { padding: 2rem; }
        .modal-footer { border-top: 1px solid var(--gray-200); padding: 1.5rem 2rem; background: var(--gray-50); }

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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100vw;
                min-height: 60px;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }
            
            .sidebar.collapsed {
                width: 100vw;
                min-height: 60px;
            }
            
            .main {
                padding: 16px 12px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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

        /* Modal backdrop opacity */
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5) !important;
            opacity: 1 !important;
        }
        .modal-backdrop.show {
            opacity: 1 !important;
        }
        /* Ensure modal is centered */
        .modal-dialog-centered {
            display: flex;
            align-items: center;
            min-height: calc(100% - 1rem);
        }
        
        /* Fix modal scrolling - prevent page scroll when modal is open */
        body.modal-open {
            overflow: hidden !important;
            padding-right: 0 !important;
        }
        
        /* Contract details modal specific fixes */
        #contractViewModal .modal-dialog {
            margin: 1rem auto;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 800px;
        }
        
        #contractViewModal .modal-content {
            display: flex;
            flex-direction: column;
            overflow: visible;
        }
        
        #contractViewModal .modal-body {
            overflow: visible;
            overflow-x: hidden;
            flex: 1;
            padding: 2rem;
        }
        
        /* Hide scrollbar completely */
        #contractViewModal .modal-body::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        
        #contractViewModal .modal-body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        
        /* Ensure modal stays fixed and centered */
        #contractViewModal.show .modal-dialog {
            position: fixed !important;
        }
        
        /* Delete Confirmation Modal Styles */
        .delete-confirm-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .delete-confirm-modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .delete-confirm-modal {
            background: #fff;
            border-radius: 12px;
            padding: 0;
            max-width: 550px;
            width: 90%;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            transform: scale(0.9);
            transition: transform 0.25s ease;
            overflow: hidden;
        }
        
        .delete-confirm-modal-overlay.show .delete-confirm-modal {
            transform: scale(1);
        }
        
        .delete-modal-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
            padding: 20px 24px;
            font-weight: 600;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .delete-modal-close-btn {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s ease;
        }
        
        .delete-modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .delete-modal-body {
            padding: 24px;
            color: #1d2a38;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .delete-modal-body .warning-icon {
            font-size: 32px;
            margin-bottom: 12px;
            display: block;
        }
        
        .delete-modal-footer {
            padding: 16px 24px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e0e0e0;
        }
        
        .delete-modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .delete-modal-btn-cancel {
            background: #fff;
            color: #6c757d;
            border: 1px solid #e0e0e0;
        }
        
        .delete-modal-btn-cancel:hover {
            background: #f8f9fa;
            border-color: #d0d0d0;
        }
        
        .delete-modal-btn-confirm {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
        }
        
        .delete-modal-btn-confirm:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
        }
        
        /* Archived Contracts Modal Styles */
        .archived-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .archived-modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .archived-modal {
            background: #fff;
            border-radius: 12px;
            padding: 0;
            max-width: 900px;
            width: 90%;
            max-height: 85vh;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            transform: scale(0.9);
            transition: transform 0.25s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .archived-modal-overlay.show .archived-modal {
            transform: scale(1);
        }
        
        .archived-modal-header {
            background: linear-gradient(135deg, #2b4c7e, #1f3659);
            color: #fff;
            padding: 20px 24px;
            font-weight: 600;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .archived-modal-close-btn {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s ease;
        }
        
        .archived-modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .archived-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
            max-height: calc(85vh - 200px);
        }
        
        .archived-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 20px 24px;
            border-top: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        
        .archived-pagination-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            background: #fff;
            color: #374151;
        }
        
        .archived-pagination-btn:hover:not(:disabled) {
            background: #f3f4f6;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }
        
        .archived-pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .archived-pagination-btn.active {
            background: linear-gradient(135deg, #2b4c7e, #1f3659);
            color: #fff;
            pointer-events: none;
        }
        
        .archived-pagination-info {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0 0.5rem;
        }
        
        .archived-item.hidden {
            display: none;
        }
        
        .archived-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .archived-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }
        
        .archived-item:hover {
            background: #f0f0f0;
            border-color: #d0d0d0;
        }
        
        .archived-item-info {
            flex: 1;
        }
        
        .archived-item-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 16px;
            margin-bottom: 8px;
        }
        
        .archived-item-details {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .archived-item-detail {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .archived-item-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-restore {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-restore:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }
        
        .archived-empty {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .archived-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
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
            font-weight: 500;
            font-size: 15px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            max-width: 500px;
            word-wrap: break-word;
        }
        
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-bubble span {
            flex: 1;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            border-left: 4px solid #065f46;
        }
        
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-left: 4px solid #991b1b;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Contract Management</h1>
                <p class="page-subtitle">Search and manage contract details for occupied plots</p>
            </div>
            <button type="button" class="btn-archive-icon" onclick="openArchivedContractsModal()" title="View Archived Contracts">
                <i class="bx bx-archive"></i>
            </button>
        </div>

        <?php
        // Handle success/error messages from URL parameters
        if (isset($_GET['success']) && $_GET['success'] === 'contract_archived') {
            echo '<div class="alert alert-success" style="margin-bottom: 1.5rem;">Contract has been successfully archived.</div>';
        }
        if (isset($_GET['error'])) {
            $error_msg = 'An error occurred.';
            if ($_GET['error'] === 'missing_plot_id') {
                $error_msg = 'Missing plot ID.';
            } elseif ($_GET['error'] === 'contract_not_found') {
                $error_msg = 'Contract not found.';
            } elseif ($_GET['error'] === 'archive_failed') {
                $error_msg = 'Failed to archive contract.';
            }
            echo '<div class="alert alert-danger" style="margin-bottom: 1.5rem;">' . htmlspecialchars($error_msg) . '</div>';
        }
        ?>

        <?php 
        // Store priority contracts HTML for modal
        $priority_contracts_html = '';
        if ($priority_result && mysqli_num_rows($priority_result) > 0) {
            ob_start();
            mysqli_data_seek($priority_result, 0); // Reset pointer
            ?>
            <div class="priority-alert-header">
                <div>
                    <div class="priority-alert-title">Expired Contracts</div>
                    <div class="priority-alert-subtitle">
                        Showing <?php echo $priority_total; ?> expired contract<?php echo $priority_total != 1 ? 's' : ''; ?>. Scroll to see more.
                    </div>
                </div>
            </div>
            <div class="priority-list">
                <?php while ($priority = mysqli_fetch_assoc($priority_result)): ?>
                    <?php
                        $p_start_raw = $priority['contract_start_date'] ?? null;
                        $p_end_raw = $priority['contract_end_date'] ?? null;
                        $p_start = formatDisplayDate($p_start_raw);
                        $p_end = formatDisplayDate($p_end_raw);
                        $p_years = calculateContractYears(
                            $p_start_raw,
                            $p_end_raw,
                            $priority['date_of_burial'] ?? null,
                            $priority['contract_type'] ?? null
                        );
                        $p_renew_display = deriveRenewalReminderDate(
                            $priority['renewal_reminder_date'] ?? null,
                            $p_end_raw,
                            $p_start_raw,
                            $priority['date_of_burial'] ?? null
                        );
                        $p_status = $priority['contract_status'] ?? 'active';
                        $p_status_class = str_replace('_', '-', $p_status);

                        // Pre-compute display values for the modal in the priority section.
                        $priority_for_modal = $priority;
                        $priority_for_modal['display_contract_years'] = $p_years;
                        $priority_for_modal['display_start_date'] = $p_start;
                        $priority_for_modal['display_end_date'] = $p_end;
                        $priority_for_modal['display_renewal_date'] = $p_renew_display ?: '—';

                        // Derive missing dates for active priority contracts based on burial.
                        if ($p_status === 'active' && $p_years !== null && !$p_start) {
                            if (!empty($priority['date_of_burial']) && $priority['date_of_burial'] !== '0000-00-00') {
                                try {
                                    $pDerivedStart = new DateTime($priority['date_of_burial']);
                                    $pFormattedStart = $pDerivedStart->format('M d, Y');
                                    // Keep both the table/meta values and modal payload in sync
                                    $p_start = $pFormattedStart;
                                    $priority_for_modal['display_start_date'] = $pFormattedStart;
                                } catch (Exception $e) {
                                    // ignore invalid date
                                }
                            }
                        }
                        if ($p_status === 'active' && $p_years !== null && !$p_end) {
                            if (!empty($priority['date_of_burial']) && $priority['date_of_burial'] !== '0000-00-00') {
                                try {
                                    $pDerivedEnd = new DateTime($priority['date_of_burial']);
                                    $pDerivedEnd->modify('+' . $p_years . ' years');
                                    $pFormattedEnd = $pDerivedEnd->format('M d, Y');
                                    // Keep both the table/meta values and modal payload in sync
                                    $p_end = $pFormattedEnd;
                                    $priority_for_modal['display_end_date'] = $pFormattedEnd;
                                } catch (Exception $e) {
                                    // ignore invalid date
                                }
                            }
                        }
                    ?>
                    <div class="priority-item">
                        <div class="priority-item-main">
                            <div class="priority-item-name">
                                <?php echo htmlspecialchars($priority['display_name']); ?>
                            </div>
                            <div class="priority-item-meta">
                                <?php 
                                    $rowLetter = numberToLetter($priority['row_number'] ?? 1);
                                    $plotNum = $priority['plot_number'] ?? '';
                                    $section = $priority['section_name'] ?? 'Unknown Section';
                                    echo htmlspecialchars($section . '-' . $rowLetter . $plotNum);
                                ?>
                            </div>
                            <div class="priority-item-meta">
                                <strong>Status:</strong>
                                <?php 
                                    $pendingArchive = getPendingArchiveStatus($priority['contract_end_date'] ?? null, $p_status);
                                    if ($pendingArchive['is_pending']) {
                                        $p_status_class .= ' pending-archive';
                                    }
                                ?>
                                <span class="contract-status <?php echo $p_status_class; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $p_status)); ?>
                                    <?php if ($pendingArchive['is_pending']): ?>
                                        <span style="display: block; font-size: 0.65rem; margin-top: 0.25rem; font-weight: 500;">(Pending Archive - <?php echo $pendingArchive['days_remaining']; ?> day<?php echo $pendingArchive['days_remaining'] != 1 ? 's' : ''; ?> left)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="priority-item-meta">
                                <strong>Renewal Reminder:</strong>
                                <?php if ($p_renew_display): ?>
                                    <span class="priority-reminder-date"><?php echo $p_renew_display; ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="priority-item-actions">
                            <div class="action-buttons">
                                <a class="btn-action btn-view view-contract-btn" href="#" 
                                   data-contract='<?php echo htmlspecialchars(json_encode($priority_for_modal), ENT_QUOTES, 'UTF-8'); ?>' 
                                   title="View contract">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php
            $priority_contracts_html = ob_get_clean();
        }
        ?>

        <?php 
        // Check if we have either expired contracts or recent archived contracts to display
        $has_expired = $priority_result && mysqli_num_rows($priority_result) > 0;
        $has_archived = !empty($recent_archived_contracts);
        
        if ($has_expired || $has_archived): ?>
            <div class="contracts-two-column">
                <?php if ($has_expired): ?>
                    <div class="priority-alert-card">
                        <div class="priority-alert-header">
                            <div>
                                <div class="priority-alert-title">Expired Contracts</div>
                                <div class="priority-alert-subtitle">
                                    Showing <?php echo $priority_total; ?> expired contract<?php echo $priority_total != 1 ? 's' : ''; ?>. Scroll to see more.
                                </div>
                            </div>
                        </div>
                        <div class="priority-list">
                            <?php 
                            mysqli_data_seek($priority_result, 0); // Reset pointer
                            while ($priority = mysqli_fetch_assoc($priority_result)): 
                            ?>
                                <?php
                                    $p_start_raw = $priority['contract_start_date'] ?? null;
                                    $p_end_raw = $priority['contract_end_date'] ?? null;
                                    $p_start = formatDisplayDate($p_start_raw);
                                    $p_end = formatDisplayDate($p_end_raw);
                                    $p_years = calculateContractYears(
                                        $p_start_raw,
                                        $p_end_raw,
                                        $priority['date_of_burial'] ?? null,
                                        $priority['contract_type'] ?? null
                                    );
                                    $p_renew_display = deriveRenewalReminderDate(
                                        $priority['renewal_reminder_date'] ?? null,
                                        $p_end_raw,
                                        $p_start_raw,
                                        $priority['date_of_burial'] ?? null
                                    );
                                    $p_status = $priority['contract_status'] ?? 'expired';
                                    $p_status_class = str_replace('_', '-', $p_status);

                                    // Pre-compute display values for the modal
                                    $priority_for_modal = $priority;
                                    $priority_for_modal['display_contract_years'] = $p_years;
                                    $priority_for_modal['display_start_date'] = $p_start;
                                    $priority_for_modal['display_end_date'] = $p_end;
                                    $priority_for_modal['display_renewal_date'] = $p_renew_display ?: '—';
                                ?>
                                <div class="priority-item">
                                    <div class="priority-item-main">
                                        <div class="priority-item-name">
                                            <?php echo htmlspecialchars($priority['display_name']); ?>
                                        </div>
                                        <div class="priority-item-meta">
                                            <?php 
                                                $rowLetter = numberToLetter($priority['row_number'] ?? 1);
                                                $plotNum = $priority['plot_number'] ?? '';
                                                $section = $priority['section_name'] ?? 'Unknown Section';
                                                echo htmlspecialchars($section . '-' . $rowLetter . $plotNum);
                                            ?>
                                        </div>
                                        <div class="priority-item-meta">
                                            <strong>Status:</strong>
                                            <?php 
                                                $pendingArchive = getPendingArchiveStatus($priority['contract_end_date'] ?? null, $p_status);
                                                if ($pendingArchive['is_pending']) {
                                                    $p_status_class .= ' pending-archive';
                                                }
                                            ?>
                                            <span class="contract-status <?php echo $p_status_class; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $p_status)); ?>
                                                <?php if ($pendingArchive['is_pending']): ?>
                                                    <span style="display: block; font-size: 0.65rem; margin-top: 0.25rem; font-weight: 500;">(Pending Archive - <?php echo $pendingArchive['days_remaining']; ?> day<?php echo $pendingArchive['days_remaining'] != 1 ? 's' : ''; ?> left)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="priority-item-meta">
                                            <strong>Renewal Reminder:</strong>
                                            <?php if ($p_renew_display): ?>
                                                <span class="priority-reminder-date"><?php echo $p_renew_display; ?></span>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="priority-item-actions">
                                        <div class="action-buttons">
                                            <a class="btn-action btn-view view-contract-btn" href="#" 
                                               data-contract='<?php echo htmlspecialchars(json_encode($priority_for_modal), ENT_QUOTES, 'UTF-8'); ?>' 
                                               title="View contract">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($has_archived): ?>
                    <div class="priority-alert-card" style="background: #f0f9ff; border-color: rgba(59, 130, 246, 0.3);">
                        <div class="priority-alert-header">
                            <div>
                                <div class="priority-alert-title" style="color: #2563eb;">Recent Archived Contracts</div>
                                <div class="priority-alert-subtitle">
                                    Showing <?php echo count($recent_archived_contracts); ?> of <?php echo $recent_archived_total; ?> archived contract<?php echo $recent_archived_total != 1 ? 's' : ''; ?>. These contracts have been archived and can only be viewed.
                                </div>
                            </div>
                        </div>
                        <div class="priority-list">
                            <?php foreach ($recent_archived_contracts as $recent_archived): ?>
                                <?php
                                    $rowLetter = numberToLetter($recent_archived['row_number'] ?? 1);
                                    $plotLocation = ($recent_archived['section_name'] ?? 'Unknown') . '-' . $rowLetter . ($recent_archived['plot_number'] ?? '');
                                    $p_start_raw = $recent_archived['contract_start_date'] ?? null;
                                    $p_end_raw = $recent_archived['contract_end_date'] ?? null;
                                    $p_start = formatDisplayDate($p_start_raw);
                                    $p_end = formatDisplayDate($p_end_raw);
                                    $p_years = calculateContractYears(
                                        $p_start_raw,
                                        $p_end_raw,
                                        $recent_archived['burial_date'] ?? null,
                                        $recent_archived['contract_type'] ?? null
                                    );
                                    $p_renew_display = deriveRenewalReminderDate(
                                        $recent_archived['renewal_reminder_date'] ?? null,
                                        $p_end_raw,
                                        $p_start_raw,
                                        $recent_archived['burial_date'] ?? null
                                    );
                                    $p_status = $recent_archived['contract_status'] ?? 'active';
                                    $p_status_class = str_replace('_', '-', $p_status);
                                    
                                    // Prepare data for modal
                                    $archived_for_modal = [
                                        'display_name' => $recent_archived['deceased_name'],
                                        'section_name' => $recent_archived['section_name'] ?? 'Unknown',
                                        'row_number' => $recent_archived['row_number'] ?? 1,
                                        'plot_number' => $recent_archived['plot_number'] ?? '',
                                        'contract_start_date' => $recent_archived['contract_start_date'],
                                        'contract_end_date' => $recent_archived['contract_end_date'],
                                        'contract_status' => $p_status,
                                        'contract_type' => $recent_archived['contract_type'] ?? 'temporary',
                                        'contract_notes' => $recent_archived['contract_notes'] ?? '',
                                        'renewal_reminder_date' => $recent_archived['renewal_reminder_date'],
                                        'date_of_burial' => $recent_archived['burial_date'],
                                        'date_of_death' => $recent_archived['date_of_death_from_deceased'] ?? $recent_archived['date_of_death'] ?? null,
                                        'date_of_birth' => $recent_archived['date_of_birth_from_deceased'] ?? null,
                                        'next_of_kin' => $recent_archived['next_of_kin'] ?? '',
                                        'contact_number' => $recent_archived['contact_number_from_deceased'] ?? '',
                                        'address' => $recent_archived['address'] ?? '',
                                        'display_contract_years' => $p_years,
                                        'display_start_date' => $p_start,
                                        'display_end_date' => $p_end,
                                        'display_renewal_date' => $p_renew_display ?: '—'
                                    ];
                                ?>
                                <div class="priority-item" style="border-color: rgba(59, 130, 246, 0.25);">
                                    <div class="priority-item-main">
                                        <div class="priority-item-name">
                                            <?php echo htmlspecialchars($recent_archived['deceased_name']); ?>
                                        </div>
                                        <div class="priority-item-meta">
                                            <?php echo htmlspecialchars($plotLocation); ?>
                                        </div>
                                        <div class="priority-item-meta">
                                            <strong>Status:</strong>
                                            <span class="contract-status <?php echo $p_status_class; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $p_status)); ?> (Archived)
                                            </span>
                                        </div>
                                        <div class="priority-item-meta">
                                            <strong>Contract Period:</strong>
                                            <?php if ($p_start && $p_end): ?>
                                                <span><?php echo $p_start; ?> - <?php echo $p_end; ?></span>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">—</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="priority-item-meta">
                                            <strong>Archived Date:</strong>
                                            <?php 
                                                $archivedDate = !empty($recent_archived['archived_at']) ? date('M d, Y', strtotime($recent_archived['archived_at'])) : '—';
                                                echo $archivedDate;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="priority-item-actions">
                                        <div class="action-buttons">
                                            <a class="btn-action btn-view view-contract-btn" href="#" 
                                               data-contract='<?php echo htmlspecialchars(json_encode($archived_for_modal), ENT_QUOTES, 'UTF-8'); ?>' 
                                               title="View archived contract">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="contracts.php" class="filters-container" id="searchForm">
            <div class="filter-group">
                <label for="search_name" class="filter-label">Search by Name</label>
                <input type="text" id="search_name" name="search_name" class="form-control-modern" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Enter deceased name...">
            </div>
            <div class="filter-group">
                <label for="search_plot" class="filter-label">Search by Plot</label>
                <input type="text" id="search_plot" name="search_plot" class="form-control-modern" value="<?php echo htmlspecialchars($search_plot); ?>" placeholder="Enter plot number...">
            </div>
            <div class="filter-group">
                <label for="search_section" class="filter-label">Search by Section</label>
                <input type="text" id="search_section" name="search_section" class="form-control-modern" value="<?php echo htmlspecialchars($search_section); ?>" placeholder="Enter section...">
            </div>
            <div class="filter-group">
                <label for="search_row" class="filter-label">Search by Row</label>
                <input type="text" id="search_row" name="search_row" class="form-control-modern" value="<?php echo htmlspecialchars($search_row); ?>" placeholder="Enter row (A, B, 1, 2...)">
            </div>
            <div class="filter-group">
                <label for="contract_status" class="filter-label">Contract Status</label>
                <select id="contract_status" name="contract_status" class="form-control-modern">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $contract_status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="renewal_needed" <?php echo $contract_status === 'renewal_needed' ? 'selected' : ''; ?>>Renewal Needed</option>
                    <option value="cancelled" <?php echo $contract_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="expired" <?php echo $contract_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
            <div class="filter-group" style="min-width:auto; gap: 0.75rem;">
                <label class="filter-label" style="visibility:hidden;">Actions</label>
                <div style="display:flex; gap:0.5rem;">
                    <button type="submit" class="btn-modern btn-primary-modern" id="searchBtn">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <button type="button" class="btn-modern btn-neutral" onclick="clearSearch()">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>
            </div>
        </form>

        <!-- Table Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">All Contract Details (<?php echo $total_records; ?> records found)</h2>
            </div>
            <div class="card-body">
                <div style="overflow-x:auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Deceased Name</th>
                            <th style="cursor: pointer;" onclick="toggleSort('plot_location')">
                                Plot Location 
                                <?php if ($sort_by === 'plot_location'): ?>
                                    <?php if ($sort_order === 'asc'): ?>
                                        <i class="bi bi-arrow-up"></i>
                                    <?php else: ?>
                                        <i class="bi bi-arrow-down"></i>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <i class="bi bi-arrow-up-down"></i>
                                <?php endif; ?>
                            </th>
                            <th>Contract Status</th>
                            <th>Contract Period</th>
                            <th>Renewal Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($contract = mysqli_fetch_assoc($result)): ?>
                            <?php 
                                $status = $contract['contract_status'] ?? 'active';
                                $rowClass = ($status === 'expired') ? 'expired-row' : '';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($contract['display_name']); ?></strong>
                                    <?php if (!empty($contract['address'] ?? '')): ?>
                                        <div class="contract-info">
                                            <strong>Address:</strong> <?php echo htmlspecialchars($contract['address']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="contract-info">Buried: <?php echo formatDisplayDate($contract['date_of_burial']) ?? '<span style="color:#999;">—</span>'; ?></div>
                                </td>
                                <td>
                                    <?php 
                                        $rowLetter = numberToLetter($contract['row_number'] ?? 1);
                                        $plotNum = $contract['plot_number'] ?? '';
                                        $section = $contract['section_name'] ?? 'Unknown Section';
                                        echo htmlspecialchars($section . '-' . $rowLetter . $plotNum);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $statusClass = str_replace('_', '-', $status);
                                    $pendingArchive = getPendingArchiveStatus($contract['contract_end_date'] ?? null, $status);
                                    if ($pendingArchive['is_pending']) {
                                        $statusClass .= ' pending-archive';
                                    }
                                    ?>
                                    <span class="contract-status <?php echo $statusClass; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                        <?php if ($pendingArchive['is_pending']): ?>
                                            <span style="display: block; font-size: 0.65rem; margin-top: 0.25rem; font-weight: 500;">(Pending Archive - <?php echo $pendingArchive['days_remaining']; ?> day<?php echo $pendingArchive['days_remaining'] != 1 ? 's' : ''; ?> left)</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $startRaw = $contract['contract_start_date'] ?? null;
                                        $endRaw = $contract['contract_end_date'] ?? null;
                                        $start = formatDisplayDate($startRaw);
                                        $end = formatDisplayDate($endRaw);
                                        $years = calculateContractYears(
                                            $startRaw,
                                            $endRaw,
                                            $contract['date_of_burial'] ?? null,
                                            $contract['contract_type'] ?? null
                                        );

                                        // Pre-compute display values for the modal so it can
                                        // show the *same* derived information as the table.
                                        $contract_for_modal = $contract;
                                        $contract_for_modal['display_contract_years'] = $years;
                                        $contract_for_modal['display_start_date'] = $start;
                                        $contract_for_modal['display_end_date'] = $end;

                                        // For active contracts that don't have explicit start/end
                                        // dates stored, derive them from the burial date and the
                                        // computed contract years so the modal can still show
                                        // meaningful dates.
                                        if ($status === 'active' && $years !== null && !$start) {
                                            if (!empty($contract['date_of_burial']) && $contract['date_of_burial'] !== '0000-00-00') {
                                                try {
                                                    $derivedStart = new DateTime($contract['date_of_burial']);
                                                    $formattedStart = $derivedStart->format('M d, Y');
                                                    // Use in both table column and modal payload
                                                    $start = $formattedStart;
                                                    $contract_for_modal['display_start_date'] = $formattedStart;
                                                } catch (Exception $e) {
                                                    // leave as-is if date is invalid
                                                }
                                            }
                                        }
                                        if ($status === 'active' && $years !== null && !$end) {
                                            if (!empty($contract['date_of_burial']) && $contract['date_of_burial'] !== '0000-00-00') {
                                                try {
                                                    $derivedEnd = new DateTime($contract['date_of_burial']);
                                                    $derivedEnd->modify('+' . $years . ' years');
                                                    $formattedEnd = $derivedEnd->format('M d, Y');
                                                    // Use in both table column and modal payload
                                                    $end = $formattedEnd;
                                                    $contract_for_modal['display_end_date'] = $formattedEnd;
                                                } catch (Exception $e) {
                                                    // leave as-is if date is invalid
                                                }
                                            }
                                        }
                                    ?>
                                    <?php if ($years !== null): ?>
                                        <div><strong><?php echo $years; ?> year<?php echo $years == 1 ? '' : 's'; ?></strong></div>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                    <div class="contract-info">
                                        <?php if ($start): ?>
                                            <div><strong>Start:</strong> <?php echo $start; ?></div>
                                        <?php endif; ?>
                                        <?php if ($end): ?>
                                            <div><strong>End:</strong> <?php echo $end; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $renew = deriveRenewalReminderDate(
                                            $contract['renewal_reminder_date'] ?? null,
                                            $endRaw,
                                            $startRaw,
                                            $contract['date_of_burial'] ?? null
                                        );

                                        // Also pre-compute renewal reminder display for the modal.
                                        $contract_for_modal['display_renewal_date'] = $renew ?: '—';

                                        if ($renew) {
                                            echo $renew;
                                        } else {
                                            echo '<span style="color: #999;">—</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="btn-action btn-view view-contract-btn" href="#" 
                                           data-contract='<?php echo htmlspecialchars(json_encode($contract_for_modal), ENT_QUOTES, 'UTF-8'); ?>' 
                                           title="View contract">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <?php if ($status !== 'expired'): ?>
                                            <a class="btn-action btn-edit" href="contract_management.php?record_id=<?php echo $contract['record_id']; ?>&plot_id=<?php echo $contract['plot_id']; ?>&from=<?php echo urlencode($current_url); ?>" title="Edit contract">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No contract records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: center; align-items: center; margin-top: 2rem; gap: 0.5rem;">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn-modern btn-neutral">Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="btn-modern btn-primary-modern" style="pointer-events: none;"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="btn-modern btn-neutral"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn-modern btn-neutral">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="delete-confirm-modal-overlay">
    <div class="delete-confirm-modal">
        <div class="delete-modal-header">
            <span>⚠️ Notice</span>
            <button type="button" class="delete-modal-close-btn" onclick="closeDeleteConfirmModal()">&times;</button>
        </div>
        <div class="delete-modal-body">
            <span class="warning-icon">⚠️</span>
            <p style="margin: 0; font-weight: 500; margin-bottom: 12px;">Are you sure you want to delete this contract?</p>
            <p style="margin: 0; font-size: 14px; color: #6c757d;">
                <strong>Reminder:</strong> This action will archive the contract information, including all contract details, dates, and status. The contract data will be preserved in the archive for future reference. The plot and deceased record will remain intact.
            </p>
        </div>
        <div class="delete-modal-footer">
            <button type="button" class="delete-modal-btn delete-modal-btn-cancel" onclick="closeDeleteConfirmModal()">Cancel</button>
            <button type="button" class="delete-modal-btn delete-modal-btn-confirm" onclick="confirmDeleteContract()">Confirm Delete</button>
        </div>
    </div>
</div>

<!-- Priority Contracts Modal -->
<div class="modal fade" id="priorityContractsModal" tabindex="-1" aria-labelledby="priorityContractsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered" style="max-width: 95vw; width: 95vw; margin: 1rem auto;">
        <div class="modal-content" style="height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="background: #2b4c7e; color: white; border-bottom: none; flex-shrink: 0;">
                <h5 class="modal-title" id="priorityContractsModalLabel" style="font-weight: 600; font-size: 1.5rem;">Contracts Needing Immediate Attention</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body" id="priorityContractsModalBody" style="padding: 2rem; overflow-y: auto; flex: 1;">
                <?php if (!empty($priority_contracts_html)): ?>
                    <div class="priority-alert-card" style="margin: 0;">
                        <?php echo $priority_contracts_html; ?>
                    </div>
                <?php else: ?>
                    <p>No contracts needing immediate attention.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Contract View Modal -->
<div class="modal fade" id="contractViewModal" tabindex="-1" aria-labelledby="contractViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #2b4c7e; color: white; border-bottom: none;">
                <h5 class="modal-title" id="contractViewModalLabel" style="font-weight: 600;">Contract Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body" id="contractModalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- Archived Contracts Modal -->
<div id="archivedContractsModal" class="archived-modal-overlay">
    <div class="archived-modal">
        <div class="archived-modal-header">
            <span>📦 Archived Contract Records</span>
            <button type="button" class="archived-modal-close-btn" onclick="closeArchivedContractsModal()">&times;</button>
        </div>
        <div class="archived-modal-body">
            <?php if (empty($archived_contracts)): ?>
                <div class="archived-empty">
                    <i class="bx bx-archive"></i>
                    <p style="margin: 0; font-size: 16px; font-weight: 500;">No archived contracts found</p>
                    <p style="margin: 8px 0 0 0; font-size: 14px; color: #9ca3af;">Archived contracts will appear here when contracts are expired or deleted.</p>
                </div>
            <?php else: ?>
                <div class="archived-list" id="archivedList">
                    <?php foreach ($archived_contracts as $index => $archived): ?>
                        <?php
                            $rowLetter = numberToLetter($archived['row_number'] ?? 1);
                            $plotLocation = ($archived['section_name'] ?? 'Unknown') . '-' . $rowLetter . ($archived['plot_number'] ?? '');
                            $archivedDate = !empty($archived['archived_at']) ? date('M d, Y', strtotime($archived['archived_at'])) : '—';
                            $contractEnd = !empty($archived['contract_end_date']) && $archived['contract_end_date'] !== '0000-00-00' ? date('M d, Y', strtotime($archived['contract_end_date'])) : '—';
                        ?>
                        <div class="archived-item" data-index="<?php echo $index; ?>" <?php echo $index >= 10 ? 'style="display: none;"' : ''; ?>>
                            <div class="archived-item-info">
                                <div class="archived-item-name"><?php echo htmlspecialchars($archived['deceased_name']); ?></div>
                                <div class="archived-item-details">
                                    <div class="archived-item-detail">
                                        <i class="bx bx-map"></i>
                                        <span><strong>Plot:</strong> <?php echo htmlspecialchars($plotLocation); ?></span>
                                    </div>
                                    <div class="archived-item-detail">
                                        <i class="bx bx-calendar"></i>
                                        <span><strong>End Date:</strong> <?php echo $contractEnd; ?></span>
                                    </div>
                                    <div class="archived-item-detail">
                                        <i class="bx bx-time"></i>
                                        <span><strong>Archived:</strong> <?php echo $archivedDate; ?></span>
                                    </div>
                                    <?php if (!empty($archived['next_of_kin'])): ?>
                                        <div class="archived-item-detail">
                                            <i class="bx bx-user"></i>
                                            <span><strong>Lessee:</strong> <?php echo htmlspecialchars($archived['next_of_kin']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="archived-item-actions">
                                <button type="button" class="btn-restore" onclick="restoreArchivedContract(<?php echo htmlspecialchars(json_encode($archived), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="bx bx-refresh"></i> Restore
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($archived_total_count > 10): ?>
                <div class="archived-pagination" id="archivedPagination">
                    <button type="button" class="archived-pagination-btn" id="archivedPrevBtn" onclick="changeArchivedPage(-1)">Previous</button>
                    <span class="archived-pagination-info" id="archivedPageInfo">Page 1 of <?php echo ceil($archived_total_count / 10); ?></span>
                    <button type="button" class="archived-pagination-btn" id="archivedNextBtn" onclick="changeArchivedPage(1)">Next</button>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Ensure the Search button always submits the form (defensive)
    (function(){
        var searchBtn = document.getElementById('searchBtn');
        var searchForm = document.getElementById('searchForm');
        if (searchBtn && searchForm) {
            searchBtn.addEventListener('click', function(){
                searchForm.submit();
            });
        }
    })();

    // Event delegation for view contract buttons
    document.addEventListener('DOMContentLoaded', function() {
        // Priority contracts modal trigger
        const priorityModalTrigger = document.getElementById('priorityModalTrigger');
        if (priorityModalTrigger) {
            priorityModalTrigger.addEventListener('click', function() {
                const modal = new bootstrap.Modal(document.getElementById('priorityContractsModal'));
                modal.show();
            });
            priorityModalTrigger.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.1)';
            });
            priorityModalTrigger.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        }

        // View contract buttons
        document.addEventListener('click', function(e) {
            if (e.target.closest('.view-contract-btn')) {
                e.preventDefault();
                const btn = e.target.closest('.view-contract-btn');
                const contractData = btn.getAttribute('data-contract');
                if (contractData) {
                    try {
                        const contract = JSON.parse(contractData);
                        openContractModal(contract);
                    } catch (err) {
                        console.error('Error parsing contract data:', err);
                    }
                }
            }
        });
    });
    function clearSearch() {
        document.getElementById('search_name').value = '';
        document.getElementById('search_plot').value = '';
        document.getElementById('search_section').value = '';
        document.getElementById('search_row').value = '';
        document.getElementById('contract_status').value = '';
        window.location.href = window.location.pathname;
    }
    
    function toggleSort(column) {
        const urlParams = new URLSearchParams(window.location.search);
        const currentSort = urlParams.get('sort_by');
        const currentOrder = urlParams.get('sort_order') || 'asc';
        
        if (currentSort === column) {
            // Toggle order
            urlParams.set('sort_order', currentOrder === 'asc' ? 'desc' : 'asc');
        } else {
            // New column, start with asc
            urlParams.set('sort_by', column);
            urlParams.set('sort_order', 'asc');
        }
        
        window.location.search = urlParams.toString();
    }

    function numberToLetter(number) {
        if (!number || number <= 0) {
            return '';
        }
        let letter = '';
        let num = number;
        while (num > 0) {
            const remainder = (num - 1) % 26;
            letter = String.fromCharCode(65 + remainder) + letter;
            num = Math.floor((num - 1) / 26);
        }
        return letter;
    }

    function formatDate(dateStr) {
        if (!dateStr || dateStr === '0000-00-00' || dateStr === 'null') {
            return '—';
        }
        try {
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return '—';
            }
            // Match PHP formatDisplayDate format: "M d, Y" (e.g., "Dec 08, 2020")
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const month = months[date.getMonth()];
            const day = String(date.getDate()).padStart(2, '0'); // Pad with leading zero to match PHP
            const year = date.getFullYear();
            return `${month} ${day}, ${year}`;
        } catch (e) {
            return '—';
        }
    }

    function calculateYears(startDate, endDate) {
        if (!startDate || !endDate || startDate === '0000-00-00' || endDate === '0000-00-00') {
            return null;
        }
        try {
            const start = new Date(startDate);
            const end = new Date(endDate);
            if (end < start) return null;
            const diffTime = Math.abs(end - start);
            const diffYears = Math.floor(diffTime / (1000 * 60 * 60 * 24 * 365));
            return diffYears;
        } catch (e) {
            return null;
        }
    }

    // Delete confirmation modal functions
    let deletePlotId = null;
    let deleteRecordId = null;
    
    function openDeleteConfirmModal(plotId, recordId) {
        deletePlotId = plotId;
        deleteRecordId = recordId;
        const modal = document.getElementById('deleteConfirmModal');
        modal.classList.add('show');
    }
    
    function closeDeleteConfirmModal() {
        const modal = document.getElementById('deleteConfirmModal');
        modal.classList.remove('show');
        deletePlotId = null;
        deleteRecordId = null;
    }
    
    function confirmDeleteContract() {
        if (deletePlotId && deleteRecordId) {
            window.location.href = 'delete_contract.php?plot_id=' + deletePlotId + '&record_id=' + deleteRecordId;
        } else if (deletePlotId) {
            window.location.href = 'delete_contract.php?plot_id=' + deletePlotId;
        }
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('deleteConfirmModal');
        if (e.target === modal) {
            closeDeleteConfirmModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDeleteConfirmModal();
        }
    });
    
    function openContractModal(contract) {
        const modal = new bootstrap.Modal(document.getElementById('contractViewModal'));
        const modalBody = document.getElementById('contractModalBody');
        
        // Prefer server-side computed / formatted values so the modal matches
        // the table exactly (especially for active contracts where dates may
        // be derived from burial date or default rules).
        // Keep raw date for editing (YYYY-MM-DD format)
        const rawStartDate = contract.contract_start_date && contract.contract_start_date !== '0000-00-00' ? contract.contract_start_date : '';
        let startDate = contract.display_start_date || formatDate(contract.contract_start_date);
        let endDate = contract.display_end_date || formatDate(contract.contract_end_date);
        const burialDate = formatDate(contract.date_of_burial);
        const dateOfBirth = formatDate(contract.date_of_birth);
        const dateOfDeath = formatDate(contract.date_of_death);
        const lessee = contract.next_of_kin || '—';
        const contactNumber = contract.contact_number && contract.contact_number !== '—' && contract.contact_number !== 'null' && contract.contact_number !== '' ? contract.contact_number : '—';
        let renewalDate = contract.display_renewal_date || formatDate(contract.renewal_reminder_date);

        // Extra safety: if display fields came through as placeholders, but raw
        // dates exist, recompute them to avoid showing "—" when we have data.
        if ((startDate === '—' || !startDate) && contract.contract_start_date) {
            startDate = formatDate(contract.contract_start_date);
        }
        if ((endDate === '—' || !endDate) && contract.contract_end_date) {
            endDate = formatDate(contract.contract_end_date);
        }
        if ((renewalDate === '—' || !renewalDate) && contract.renewal_reminder_date) {
            renewalDate = formatDate(contract.renewal_reminder_date);
        }
        
        // Use pre-computed contract years when available, with JS fallback.
        let contractYears = (typeof contract.display_contract_years === 'number')
            ? contract.display_contract_years
            : calculateYears(contract.contract_start_date, contract.contract_end_date);
        if (contractYears === null && contract.contract_start_date && contract.contract_start_date !== '0000-00-00') {
            contractYears = 5; // Default fallback
        }
        
        // Format status
        const status = contract.contract_status || 'active';
        let statusClass = status.replace('_', '-');
        let statusText = status.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        
        // Check if contract is pending archive (expired but within 7 days)
        let pendingArchiveInfo = null;
        if (status === 'expired' && contract.contract_end_date && contract.contract_end_date !== '0000-00-00') {
            try {
                const endDate = new Date(contract.contract_end_date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const archiveDate = new Date(endDate);
                archiveDate.setDate(archiveDate.getDate() + 7);
                
                if (today >= endDate && today < archiveDate) {
                    const daysRemaining = Math.ceil((archiveDate - today) / (1000 * 60 * 60 * 24));
                    pendingArchiveInfo = {
                        isPending: true,
                        daysRemaining: daysRemaining
                    };
                    statusClass += ' pending-archive';
                }
            } catch (e) {
                // Ignore date parsing errors
            }
        }
        
        // Build modal content
        let html = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div>
                    <h6 style="font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Deceased Information</h6>
                    <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                        <p style="margin: 0; font-weight: 600; font-size: 1.1rem; color: var(--gray-900); margin-bottom: 0.75rem;">${contract.display_name || '—'}</p>
                        ${contract.address ? `<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Address:</strong></span><span style="text-align: right;">${contract.address}</span></div>` : ''}
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Date of Birth:</strong></span><span style="text-align: right;">${dateOfBirth}</span></div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Date of Death:</strong></span><span style="text-align: right;">${dateOfDeath}</span></div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Date of Burial:</strong></span><span style="text-align: right;">${burialDate}</span></div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Name of Lessee:</strong></span><span style="text-align: right;">${lessee}</span></div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0; font-size: 0.85rem; color: var(--gray-700);"><span><strong>Contact Number:</strong></span><span style="text-align: right;">${contactNumber}</span></div>
                    </div>
                </div>
                <div>
                    <h6 style="font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Plot Information</h6>
                    <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                        <p style="margin: 0; font-size: 0.95rem; color: var(--gray-800);"><strong>Plot:</strong> ${(() => {
                            const rowNumber = contract.row_number || 1;
                            const rowLetter = numberToLetter(rowNumber);
                            const section = contract.section_name || 'Unknown Section';
                            const plotNum = contract.plot_number || '';
                            return section + '-' + rowLetter + plotNum;
                        })()}</p>
                    </div>
                </div>
            </div>
            <div style="margin-top: 1.5rem;">
                <h6 style="font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Contract Details</h6>
                <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--border-radius);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">Contract Status</p>
                            <span class="contract-status ${statusClass}" style="display: inline-block;">
                                ${statusText}
                                ${pendingArchiveInfo ? `<span style="display: block; font-size: 0.65rem; margin-top: 0.25rem; font-weight: 500;">(Pending Archive - ${pendingArchiveInfo.daysRemaining} day${pendingArchiveInfo.daysRemaining !== 1 ? 's' : ''} left)</span>` : ''}
                            </span>
                        </div>
                        ${contractYears !== null ? `
                        <div>
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">Contract Duration</p>
                            <p style="margin: 0; font-size: 0.95rem; color: var(--gray-800); font-weight: 600;">${contractYears} year${contractYears === 1 ? '' : 's'}</p>
                        </div>
                        ` : ''}
                        <div>
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">Renewal Reminder</p>
                            <p style="margin: 0; font-size: 0.95rem; color: var(--gray-800);" id="displayRenewalDate">${renewalDate}</p>
                        </div>
                        <div>
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">Start Date</p>
                            ${status === 'expired' && contract.plot_id ? `
                                <div style="display: flex; gap: 0.5rem; align-items: flex-start;">
                                    <div style="flex: 1;">
                                        <input type="text" 
                                               id="editStartDate" 
                                               class="form-control-modern date-mdY" 
                                               value="${rawStartDate}" 
                                               style="width: 100%;"
                                               data-plot-id="${contract.plot_id}"
                                               placeholder="Select start date">
                                    </div>
                                    <button type="button" 
                                            id="updateStartDateBtn" 
                                            class="btn-action btn-edit" 
                                            style="padding: 0.75rem 1rem; white-space: nowrap; margin-top: 0;"
                                            onclick="updateContractStartDate(${contract.plot_id})">
                                        <i class="bi bi-pencil"></i> Update
                                    </button>
                                </div>
                                <p style="margin: 0.5rem 0 0 0; font-size: 0.75rem; color: var(--gray-500);">End date and renewal reminder will be auto-calculated</p>
                            ` : `
                                <p style="margin: 0; font-size: 0.95rem; color: var(--gray-800);">${startDate}</p>
                            `}
                        </div>
                        <div>
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">End Date</p>
                            <p style="margin: 0; font-size: 0.95rem; color: var(--gray-800);" id="displayEndDate">${endDate}</p>
                        </div>
                    </div>
                    ${contract.contract_notes ? `
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <p style="margin: 0 0 0.5rem 0; font-size: 0.85rem; color: var(--gray-600);">Notes</p>
                        <p style="margin: 0; font-size: 0.9rem; color: var(--gray-700); white-space: pre-wrap;">${contract.contract_notes}</p>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
        
        modalBody.innerHTML = html;
        modal.show();
        
        // Store contract data for update function
        if (status === 'expired' && contract.plot_id) {
            window.currentContractData = contract;
            
            // Initialize Flatpickr for the date input
            const startDateInput = document.getElementById('editStartDate');
            if (startDateInput && window.flatpickr) {
                // Ensure the value is in Y-m-d format for Flatpickr
                let initialValue = startDateInput.value;
                if (initialValue) {
                    // If value is in a different format, try to parse it
                    const dateMatch = initialValue.match(/(\d{4})-(\d{2})-(\d{2})/);
                    if (!dateMatch) {
                        // Try to parse other formats
                        const altMatch = initialValue.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
                        if (altMatch) {
                            const month = altMatch[1].padStart(2, '0');
                            const day = altMatch[2].padStart(2, '0');
                            const year = altMatch[3];
                            initialValue = `${year}-${month}-${day}`;
                            startDateInput.value = initialValue;
                        }
                    }
                }
                
                const fpInstance = flatpickr(startDateInput, {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "m/d/Y",
                    allowInput: true,
                    defaultDate: initialValue || null,
                    onChange: function(selectedDates, dateStr, instance) {
                        // Ensure the original input value is updated when date changes
                        if (selectedDates.length > 0) {
                            const formattedDate = instance.formatDate(selectedDates[0], 'Y-m-d');
                            instance.input.value = formattedDate;
                        }
                    }
                });
                
                // Ensure the value is synced on initialization
                if (initialValue && fpInstance) {
                    fpInstance.setDate(initialValue, false);
                    // Also set the input value directly
                    fpInstance.input.value = initialValue;
                }
            }
        }
    }
    
    // Function to show notification bubble
    function showNotification(type, message) {
        const notificationId = type === 'success' ? 'successNotification' : 'errorNotification';
        const notification = document.getElementById(notificationId);
        
        if (!notification) return;
        
        const span = notification.querySelector('span');
        if (span) span.textContent = message;
        
        notification.style.display = 'flex';
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        setTimeout(() => {
            notification.classList.remove('show');
            notification.classList.add('hide');
            setTimeout(() => {
                notification.style.display = 'none';
                notification.classList.remove('hide');
            }, 250);
        }, 4000);
    }
    
    // Function to update contract start date
    function updateContractStartDate(plotId) {
        const startDateInput = document.getElementById('editStartDate');
        const updateBtn = document.getElementById('updateStartDateBtn');
        
        if (!startDateInput) {
            showNotification('error', 'Date input field not found');
            return;
        }
        
        // Get the actual date value (from Flatpickr if initialized, or direct value)
        let dateValue = '';
        
        if (startDateInput._flatpickr) {
            // Flatpickr is initialized
            const fpInstance = startDateInput._flatpickr;
            
            // Method 1: Get from selectedDates if available (most reliable)
            if (fpInstance.selectedDates && fpInstance.selectedDates.length > 0) {
                dateValue = fpInstance.formatDate(fpInstance.selectedDates[0], 'Y-m-d');
            } 
            // Method 2: Get from the original input element (contains Y-m-d when altInput is true)
            // The original input should have the Y-m-d value stored
            else if (fpInstance.input && fpInstance.input.value) {
                dateValue = fpInstance.input.value.trim();
            }
            // Method 3: Get from startDateInput directly
            else if (startDateInput.value) {
                dateValue = startDateInput.value.trim();
            }
            // Method 4: Parse from alt input if available (fallback)
            else if (fpInstance.altInput && fpInstance.altInput.value) {
                const altValue = fpInstance.altInput.value.trim();
                // Parse m/d/Y format
                const dateMatch = altValue.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
                if (dateMatch) {
                    const month = dateMatch[1].padStart(2, '0');
                    const day = dateMatch[2].padStart(2, '0');
                    const year = dateMatch[3];
                    dateValue = `${year}-${month}-${day}`;
                }
            }
        } else {
            // No Flatpickr, use direct value
            dateValue = startDateInput.value ? startDateInput.value.trim() : '';
        }
        
        if (!dateValue || dateValue === '') {
            showNotification('error', 'Please select a start date');
            return;
        }
        
        // Validate date format (should be YYYY-MM-DD)
        const datePattern = /^\d{4}-\d{2}-\d{2}$/;
        if (!datePattern.test(dateValue)) {
            showNotification('error', 'Invalid date format. Please select a valid date.');
            return;
        }
        
        // Disable button during update
        updateBtn.disabled = true;
        updateBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';
        
        // Send update request
        fetch('../api/update_contract_start_date.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                plot_id: plotId,
                contract_start_date: dateValue
            })
        })
        .then(response => {
            // Log response status
            console.log('Response status:', response.status);
            
            // Check if response is ok
            if (!response.ok) {
                return response.json().then(data => {
                    console.error('Error response:', data);
                    throw new Error(data.message || 'Server error');
                }).catch(err => {
                    // If JSON parsing fails, throw the original error
                    throw new Error('Server error: ' + response.statusText);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.success) {
                // Update the displayed dates in the modal
                const displayEndDate = document.getElementById('displayEndDate');
                
                if (data.contract_end_date) {
                    const formattedEndDate = formatDate(data.contract_end_date);
                    if (displayEndDate) {
                        displayEndDate.textContent = formattedEndDate;
                    }
                }
                
                if (data.renewal_reminder_date) {
                    const formattedRenewalDate = formatDate(data.renewal_reminder_date);
                    const displayRenewalDate = document.getElementById('displayRenewalDate');
                    if (displayRenewalDate) {
                        displayRenewalDate.textContent = formattedRenewalDate;
                    }
                }
                
                // Show success notification
                showNotification('success', 'Contract has been renewed successfully! The contract dates have been updated and the contract is now active.');
                
                // Reload the page after a short delay to show the notification
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                showNotification('error', 'Error: ' + (data.message || 'Failed to update contract'));
                updateBtn.disabled = false;
                updateBtn.innerHTML = '<i class="bi bi-pencil"></i> Update';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Error: ' + (error.message || 'An error occurred while updating the contract. Please try again.'));
            updateBtn.disabled = false;
            updateBtn.innerHTML = '<i class="bi bi-pencil"></i> Update';
        });
    }
    
    // Archived Contracts Modal Functions
    let currentArchivedPage = 1;
    const archivedRecordsPerPage = 10;
    let totalArchivedRecords = 0;
    
    function openArchivedContractsModal() {
        const modal = document.getElementById('archivedContractsModal');
        if (modal) {
            modal.classList.add('show');
            // Reset to first page when opening
            currentArchivedPage = 1;
            updateArchivedPagination();
        }
    }
    
    function closeArchivedContractsModal() {
        const modal = document.getElementById('archivedContractsModal');
        if (modal) {
            modal.classList.remove('show');
        }
    }
    
    function updateArchivedPagination() {
        const items = document.querySelectorAll('#archivedList .archived-item');
        totalArchivedRecords = items.length;
        const totalPages = Math.ceil(totalArchivedRecords / archivedRecordsPerPage);
        
        // Show/hide items based on current page
        items.forEach((item, index) => {
            const itemIndex = parseInt(item.getAttribute('data-index'));
            const startIndex = (currentArchivedPage - 1) * archivedRecordsPerPage;
            const endIndex = startIndex + archivedRecordsPerPage;
            
            if (itemIndex >= startIndex && itemIndex < endIndex) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
        
        // Update pagination controls
        const prevBtn = document.getElementById('archivedPrevBtn');
        const nextBtn = document.getElementById('archivedNextBtn');
        const pageInfo = document.getElementById('archivedPageInfo');
        
        if (prevBtn) {
            prevBtn.disabled = currentArchivedPage === 1;
        }
        if (nextBtn) {
            nextBtn.disabled = currentArchivedPage >= totalPages;
        }
        if (pageInfo) {
            pageInfo.textContent = `Page ${currentArchivedPage} of ${totalPages}`;
        }
    }
    
    function changeArchivedPage(direction) {
        const items = document.querySelectorAll('#archivedList .archived-item');
        const totalPages = Math.ceil(items.length / archivedRecordsPerPage);
        
        if (direction === 1 && currentArchivedPage < totalPages) {
            currentArchivedPage++;
        } else if (direction === -1 && currentArchivedPage > 1) {
            currentArchivedPage--;
        }
        
        updateArchivedPagination();
        
        // Scroll to top of modal body
        const modalBody = document.querySelector('.archived-modal-body');
        if (modalBody) {
            modalBody.scrollTop = 0;
        }
    }
    
    function restoreArchivedContract(archivedData) {
        // Redirect to plot details page for the specific plot
        const plotId = archivedData.plot_id;
        if (!plotId) {
            showNotification('error', 'Error: Plot ID not found in archived contract data.');
            return;
        }
        
        const params = new URLSearchParams({
            restore: '1',
            archive_id: archivedData.archive_id || ''
        });
        
        window.location.href = 'plot_details.php?id=' + plotId + '&' + params.toString();
    }
    
    // Close archived modal when clicking outside
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('archivedContractsModal');
        if (e.target === modal) {
            closeArchivedContractsModal();
        }
    });
    
    // Close archived modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeArchivedContractsModal();
        }
    });
</script>

<!-- Notification Bubbles -->
<div id="successNotification" class="notification-bubble success-notification" style="display: none;">
    <i class="bx bx-check-circle"></i>
    <span></span>
</div>
<div id="errorNotification" class="notification-bubble error-notification" style="display: none;">
    <i class="bx bx-error-alt"></i>
    <span></span>
</div>
</body>
</html>

