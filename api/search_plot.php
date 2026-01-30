<?php
require_once '../config/database.php';
header('Content-Type: application/json');

// Error handling
error_reporting(0);
ini_set('display_errors', 0);

try {
    $search = isset($_GET['plot']) ? trim($_GET['plot']) : '';
    if (!$search) {
        echo json_encode(['plot' => null, 'error' => null]);
        exit;
    }

    // Search by plot reference.
    // Support multiple formats used across the app, for example:
    //  - "Angels Paradise-1-1"   (section name + row + plot)
    //  - "ARIES-A1-L1"          (section code + row/column + level)
    //  - plain plot numbers or fragments ("1", "A1", "E-1", etc.)
    $searchEscaped = mysqli_real_escape_string($conn, $search);
    $searchUpper = strtoupper($searchEscaped);

    // Exact-style match first
    $query = "SELECT p.*, s.section_name, s.section_code 
              FROM plots p 
              JOIN sections s ON p.section_id = s.section_id 
              WHERE 
                    UPPER(p.plot_number) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_code, '-', p.plot_number)) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_name, '-', p.plot_number)) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_name, ' - ', p.plot_number)) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_name, '-', p.row_number, '-', p.plot_number)) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_name, ' - ', p.row_number, ' - ', p.plot_number)) = '$searchUpper'
                 OR UPPER(CONCAT(s.section_code, '-', p.row_number, '-', p.plot_number)) = '$searchUpper'
              LIMIT 1";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        echo json_encode(['plot' => null, 'error' => 'Database query error: ' . mysqli_error($conn)]);
        exit;
    }

    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['plot' => $row, 'error' => null]);
        exit;
    }

    // Fallback: flexible partial matching (e.g. "ARIES-1", "Aries-E") – return all matching plots
    // so incomplete search like "Aries-1" shows all plots with that prefix in search details
    $query = "SELECT p.*, s.section_name, s.section_code 
              FROM plots p 
              JOIN sections s ON p.section_id = s.section_id 
              WHERE 
                    UPPER(p.plot_number) LIKE '%$searchUpper%'
                 OR UPPER(CONCAT(s.section_code, '-', p.plot_number)) LIKE '%$searchUpper%'
                 OR UPPER(CONCAT(s.section_name, '-', p.row_number, '-', p.plot_number)) LIKE '%$searchUpper%'
                 OR UPPER(CONCAT(s.section_name, ' - ', p.row_number, ' - ', p.plot_number)) LIKE '%$searchUpper%'
                 OR UPPER(CONCAT(s.section_code, '-', p.row_number, '-', p.plot_number)) LIKE '%$searchUpper%'
              ORDER BY s.section_code, p.row_number, p.plot_number
              LIMIT 50";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        echo json_encode(['plot' => null, 'plots' => null, 'error' => 'Database query error: ' . mysqli_error($conn)]);
        exit;
    }

    $plots = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $plots[] = $row;
    }

    if (count($plots) === 1) {
        echo json_encode(['plot' => $plots[0], 'plots' => null, 'error' => null]);
    } elseif (count($plots) > 1) {
        echo json_encode(['plot' => null, 'plots' => $plots, 'error' => null]);
    } else {
        echo json_encode(['plot' => null, 'plots' => null, 'error' => null]);
    }
} catch (Exception $e) {
    echo json_encode(['plot' => null, 'plots' => null, 'error' => 'Server error: ' . $e->getMessage()]);
}

