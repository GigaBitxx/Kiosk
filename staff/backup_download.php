<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// Function to convert row number to letter (1=A, 2=B, etc.)
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }
    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1) {
        return '';
    }
    $letter = '';
    while ($rowNumber > 0) {
        $remainder = ($rowNumber - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $rowNumber = intval(($rowNumber - 1) / 26);
    }
    return $letter;
}

// Function to format date for CSV export (MM/DD/YYYY format)
function formatDateForCSV($date) {
    if (empty($date) || $date === '0000-00-00' || $date === null) {
        return '';
    }
    
    // Try to parse the date and output as month-first
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('m/d/Y', $timestamp);
    }
    
    return $date;
}

// Check if "All Sections" is selected
$all_sections = isset($_GET['all_sections']) && $_GET['all_sections'] == '1';

// Get selected sections
if ($all_sections) {
    // Fetch all section IDs from database
    $all_sections_query = "SELECT section_id FROM sections ORDER BY section_name";
    $all_sections_result = mysqli_query($conn, $all_sections_query);
    
    if (!$all_sections_result) {
        header('Location: deceased_records.php?error=Database error occurred');
        exit();
    }
    
    $section_ids = [];
    while ($row = mysqli_fetch_assoc($all_sections_result)) {
        $section_ids[] = (int)$row['section_id'];
    }
    
    if (empty($section_ids)) {
        header('Location: deceased_records.php?error=No sections found');
        exit();
    }
} else {
    // Use selected sections
    if (!isset($_GET['sections']) || empty($_GET['sections'])) {
        header('Location: deceased_records.php?error=Please select at least one section or check "All Sections"');
        exit();
    }
    
    $section_ids = $_GET['sections'];
    if (!is_array($section_ids)) {
        $section_ids = [$section_ids];
    }
    
    // Validate and sanitize section IDs
    $section_ids = array_filter(array_map('intval', $section_ids));
    if (empty($section_ids)) {
        header('Location: deceased_records.php?error=Invalid section selection');
        exit();
    }
}

// Get optional row filter
$row_filter = isset($_GET['row_filter']) && !empty($_GET['row_filter']) ? intval($_GET['row_filter']) : null;

// Build the query
$placeholders = str_repeat('?,', count($section_ids) - 1) . '?';

$query = "SELECT 
            dr.record_id,
            dr.full_name,
            dr.date_of_birth,
            dr.date_of_death,
            dr.burial_date,
            dr.date_acquired,
            dr.due_date,
            dr.address,
            dr.next_of_kin,
            dr.contact_number,
            p.section_id,
            s.section_name,
            p.row_number,
            p.plot_number
          FROM deceased_records dr
          JOIN plots p ON dr.plot_id = p.plot_id
          JOIN sections s ON p.section_id = s.section_id
          WHERE p.section_id IN ($placeholders)";

$params = $section_ids;
$param_types = str_repeat('i', count($section_ids));

// Add row filter if specified
if ($row_filter !== null) {
    $query .= " AND p.row_number = ?";
    $params[] = $row_filter;
    $param_types .= 'i';
}

$query .= " ORDER BY s.section_name, p.row_number, p.plot_number";

// Prepare and execute query
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Set headers for CSV download
    $filename = 'backup_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 (helps Excel recognize UTF-8 encoding)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write CSV header
    $header = [
        'NO.',
        'NAME OF LESSEE',
        'NAME OF DECEASED',
        'DATE OF DEATH',
        'DATE OF BIRTH',
        'DATE ACQUIRED',
        'DUE DATE',
        'ADDRESS',
        'NEXT OF KIN',
        'CONTACT NUMBER',
        'SECTION',
        'ROW',
        'PLOT NUMBER'
    ];
    fputcsv($output, $header);
    
    // Write data rows
    $row_number = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $csv_row = [
            $row_number++, // NO.
            '', // NAME OF LESSEE (not stored in database, left empty)
            $row['full_name'] ?? '', // NAME OF DECEASED
            formatDateForCSV($row['date_of_death']), // DATE OF DEATH
            formatDateForCSV($row['date_of_birth']), // DATE OF BIRTH
            formatDateForCSV($row['date_acquired']), // DATE ACQUIRED
            formatDateForCSV($row['due_date']), // DUE DATE
            $row['address'] ?? '', // ADDRESS
            $row['next_of_kin'] ?? '', // NEXT OF KIN
            $row['contact_number'] ?? '', // CONTACT NUMBER
            $row['section_name'] ?? '', // SECTION
            rowNumberToLetter($row['row_number']), // ROW (as letter)
            $row['plot_number'] ?? '' // PLOT NUMBER
        ];
        fputcsv($output, $csv_row);
    }
    
    fclose($output);
    mysqli_stmt_close($stmt);
} else {
    header('Location: deceased_records.php?error=Database error occurred');
    exit();
}
?>

