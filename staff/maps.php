<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// Run contract maintenance on each page load so expired contracts
// immediately archive deceased records and free plots
require_once __DIR__ . '/contract_maintenance.php';
run_contract_maintenance($conn, false);

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Get all plots with their information for the map, including multi-level data
$query = "SELECT 
            p.*, 
            s.section_code, 
            s.section_name, 
            s.has_multi_level, 
            s.max_levels AS section_max_levels,
            dr.full_name, 
            dr.burial_date
          FROM plots p 
          LEFT JOIN sections s ON p.section_id = s.section_id
          LEFT JOIN deceased_records dr ON p.plot_id = dr.plot_id
          WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
            AND p.latitude != 0 AND p.longitude != 0
          ORDER BY COALESCE(s.section_code, p.section), 
                   p.row_number, 
                   LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 1),
                   CAST(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 2) AS UNSIGNED),
                   p.level_number";
$result = mysqli_query($conn, $query);

// Get all sections for filter dropdown (excluding TES and mispositioned sections: AP, BLK 1-4)
$sections_query = "SELECT DISTINCT section_id, section_code, section_name FROM sections 
    WHERE section_name NOT LIKE '%TES%' 
    AND section_code NOT LIKE '%TES%'
    AND UPPER(TRIM(section_name)) != 'AP'
    AND UPPER(TRIM(section_code)) != 'AP'
    AND section_name NOT REGEXP '^BLK[[:space:]]*[1-4]$'
    AND section_code NOT REGEXP '^BLK[[:space:]]*[1-4]$'
    ORDER BY section_code";
$sections_result = mysqli_query($conn, $sections_query);
$all_sections = [];
while ($section = mysqli_fetch_assoc($sections_result)) {
    // Double-check in PHP as well
    $upperName = strtoupper(trim($section['section_name'] ?? ''));
    $upperCode = strtoupper(trim($section['section_code'] ?? ''));
    if ($upperName !== 'AP' && $upperCode !== 'AP' &&
        !preg_match('/^BLK\s*[1-4]$/i', $upperName) && !preg_match('/^BLK\s*[1-4]$/i', $upperCode)) {
        $all_sections[] = $section;
    }
}

// Group plots by section (excluding TES sections)
$sections = array();
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Skip sections with TES in name or code, and mispositioned sections (AP, BLK 1-4)
        $sectionName = !empty($row['section_name']) ? $row['section_name'] : '';
        $sectionCode = !empty($row['section_code']) ? $row['section_code'] : (!empty($row['section']) ? $row['section'] : '');
        
        // Skip TES sections
        if (stripos($sectionName, 'TES') !== false || stripos($sectionCode, 'TES') !== false) {
            continue;
        }
        
        // Skip mispositioned sections: AP, BLK 1, BLK 2, BLK 3, BLK 4, BLK1, BLK2, BLK3, BLK4
        $upperName = strtoupper(trim($sectionName));
        $upperCode = strtoupper(trim($sectionCode));
        if ($upperName === 'AP' || $upperCode === 'AP' ||
            preg_match('/^BLK\s*[1-4]$/i', $upperName) || preg_match('/^BLK\s*[1-4]$/i', $upperCode)) {
            continue; // Skip mispositioned sections
        }
        
        // Resolve a safe section key even if section_name is missing
        $sectionKey = !empty($row['section_name']) ? $row['section_name'] : (!empty($row['section']) ? $row['section'] : 'Unknown Section');

        if (!isset($sections[$sectionKey])) {
            $sections[$sectionKey] = array(
                'section_id' => $row['section_id'] ?? null,
                'section_code' => $row['section_code'] ?? $row['section'] ?? null,
                'has_multi_level' => $row['has_multi_level'] ?? 0,
                'section_max_levels' => $row['section_max_levels'] ?? ($row['max_levels'] ?? 1),
                'plots' => array(),
                // Internal helper structure to prevent duplicate markers for plots
                'plots_by_id' => array()
            );
        }

        // Group deceased records by plot so that each plot only produces a single marker
        $plotId = $row['plot_id'] ?? null;
        if ($plotId === null) {
            // Fallback: if for some reason plot_id is missing, just push the row as-is
            $sections[$sectionKey]['plots'][] = $row;
            continue;
        }

        if (!isset($sections[$sectionKey]['plots_by_id'][$plotId])) {
            // First time we see this plot_id in this section
            $plotData = $row;
            $plotData['deceased'] = array();

            if (!empty($row['full_name'])) {
                $plotData['deceased'][] = array(
                    'full_name'   => $row['full_name'],
                    'burial_date' => $row['burial_date']
                );
            }

            $sections[$sectionKey]['plots_by_id'][$plotId] = $plotData;
        } else {
            // Additional deceased for the same plot
            if (!empty($row['full_name'])) {
                $sections[$sectionKey]['plots_by_id'][$plotId]['deceased'][] = array(
                    'full_name'   => $row['full_name'],
                    'burial_date' => $row['burial_date']
                );
            }
        }
    }

    // After grouping by plot_id, convert plots_by_id maps into numeric plots arrays
    foreach ($sections as $key => $sectionData) {
        if (isset($sectionData['plots_by_id']) && is_array($sectionData['plots_by_id'])) {
            $sections[$key]['plots'] = array_values($sectionData['plots_by_id']);
            unset($sections[$key]['plots_by_id']);
        }
    }
} else {
    error_log('Error fetching plots for maps: ' . mysqli_error($conn));
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/offline-map.js?v=<?php echo time(); ?>"></script>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
        }
        #map {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 1;
        }
        
        /* Ensure map is responsive and doesn't cause horizontal scroll */
        @media (max-width: 768px) {
            #map {
                width: 100%;
                height: 100%;
            }
        }
        .back-button {
            position: fixed;
            top: 20px;
            left: 60px;
            z-index: 1000;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #e0e0e0;
            background: #ffffff;
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
            cursor: pointer;
        }
        .back-button:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .back-button i {
            font-size: 20px;
        }
        .controls {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            max-width: 800px;
            min-width: 650px;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center; /* keep everything clustered, no big middle gap */
        }
        
        /* Ensure controls don't overlap with back button (left) and filters (right) */
        @media (min-width: 1201px) {
            .controls {
                max-width: min(800px, calc(100vw - 400px));
            }
        }
        
        @media (max-width: 1400px) {
            .controls {
                max-width: min(800px, calc(100vw - 380px));
            }
        }

        .search-container {
            flex: 1 1 auto;       /* take available width */
            min-width: 0;
            max-width: 520px;
            display: flex;
            gap: 8px;
        }

        .search-container input {
            flex: 1 1 auto;
            min-width: 0;
            font-size: 0.9rem;
            padding: 6px 10px;
        }
        
        .search-container .btn {
            padding: 6px 16px;
            font-size: 0.9rem;
            min-width: 110px; /* wide but leaves room for refresh/fullscreen */
            flex-shrink: 0;
        }

        .control-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
            flex-shrink: 0; /* prevent buttons from being squashed */
        }
        
        .control-buttons .btn {
            padding: 6px 10px;
            font-size: 0.9rem;
            min-width: 36px;
        }

        .btn-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: #fff;
        }

        .btn-primary:hover {
            background-color: #1f3659;
            border-color: #1f3659;
            color: #fff;
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
            color: #fff;
        }

        @media (max-width: 1200px) {
            .controls {
                left: 50%;
                transform: translateX(-50%);
                max-width: calc(100vw - 380px);
                min-width: auto;
                margin-left: 0;
                margin-right: 0;
            }
        }
        @media (max-width: 1000px) {
            .controls {
                left: 50%;
                transform: translateX(-50%);
                max-width: calc(100vw - 360px);
                min-width: auto;
            }
        }
        @media (max-width: 768px) {
            .controls {
                left: 50%;
                transform: translateX(-50%);
                max-width: calc(100vw - 20px);
                min-width: auto;
                flex-direction: column;
                align-items: stretch;
                margin: 0;
                top: 70px; /* Move down to avoid overlap with back button */
                padding: 8px 10px;
            }
            .search-container {
                width: 100%;
                min-width: unset;
                max-width: 100%;
            }
            .search-container input {
                min-width: unset;
                font-size: 14px;
            }
            .search-container .btn {
                min-width: auto;
                padding: 6px 12px;
            }
            .control-buttons {
                width: 100%;
                justify-content: space-between;
            }
            .control-buttons .btn {
                flex: 1;
                min-width: auto;
            }
            
            /* Back button adjustments */
            .back-button {
                top: 10px;
                left: 10px;
                padding: 8px 12px;
                font-size: 12px;
            }
            .back-button i {
                font-size: 18px;
            }
        }
        
        @media (max-width: 576px) {
            .controls {
                top: 60px;
                max-width: calc(100vw - 16px);
                padding: 6px 8px;
            }
            .search-container input {
                font-size: 13px;
                padding: 5px 8px;
            }
            .search-container .btn {
                padding: 5px 10px;
                font-size: 13px;
            }
            .back-button {
                top: 8px;
                left: 8px;
                padding: 6px 10px;
                font-size: 11px;
            }
        }
        .legend {
            position: fixed;
            bottom: 16px;
            left: 16px;
            background: rgba(255, 255, 255, 0.92);
            padding: 8px 10px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
            z-index: 1000;
            min-width: 150px;
            font-size: 11px;
        }
        
        @media (max-width: 768px) {
            .legend {
                bottom: auto;
                top: 130px; /* Below controls */
                left: 10px;
                right: auto;
                min-width: 140px;
                font-size: 10px;
                padding: 6px 8px;
            }
        }
        
        @media (max-width: 576px) {
            .legend {
                top: 110px;
                left: 8px;
                min-width: 120px;
                font-size: 9px;
                padding: 5px 6px;
            }
            .legend h6 {
                font-size: 10px;
            }
        }
        .legend h6 {
            margin-bottom: 6px;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 3px;
            font-size: 11px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin: 4px 0;
        }
        .legend-color {
            width: 14px;
            height: 14px;
            margin-right: 6px;
            border: 1px solid #ccc;
            border-radius: 50%;
        }
        .custom-popup {
            max-width: 300px;
        }
        .custom-popup .popup-content {
            padding: 10px;
        }
        .custom-popup .deceased-info {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .plot-marker {
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 0 4px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
        }
        .plot-marker:hover {
            transform: scale(1.2);
            z-index: 1000;
        }
        .plot-marker.available {
            background-color: #90EE90;
        }
        .plot-marker.reserved {
            background-color: #FFD700;
        }
        .plot-marker.occupied {
            background-color: #FF6B6B;
        }
        .section-label {
            background: none;
            border: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .section-name {
            background: rgba(255, 255, 255, 0.5);
            border-radius: 4px;
            border: 2px solid rgba(51, 51, 51, 0.3);
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            padding: 2px 6px;
            max-width: 100px;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            pointer-events: none;
        }
        .section-name.small {
            font-size: 10px;
            max-width: 80px;
            padding: 1px 4px;
        }
        .plot-popup {
            padding: 8px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            transition: all 0.3s ease;
        }
        .plot-popup h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
            transition: font-size 0.3s ease;
        }
        .plot-popup p {
            margin-bottom: 8px;
            font-size: 0.8rem;
            color: #555;
            transition: font-size 0.3s ease;
        }
        .plot-popup .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
            transition: font-size 0.3s ease, padding 0.3s ease;
        }
        .plot-popup .status.available {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        .plot-popup .status.reserved {
            background-color: #fff3e0;
            color: #ef6c00;
        }
        .plot-popup .status.occupied {
            background-color: #ffebee;
            color: #c62828;
        }
        .plot-popup .btn-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: #fff;
            font-size: 0.85rem;
            padding: 6px 12px;
            margin-top: 12px;
            transition: all 0.3s ease;
        }
        .plot-popup .btn-primary:hover {
            background-color: #1f3659;
            border-color: #1f3659;
            color: #fff;
        }
        /* Leaflet popup container - adjust max-width based on zoom */
        .leaflet-popup-content-wrapper {
            transition: all 0.3s ease;
        }
        .filters-container {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.98);
            padding: 0;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.08);
            z-index: 1000;
            min-width: 240px;
            max-width: 320px;
            max-height: calc(100vh - 40px);
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        @media (max-width: 768px) {
            .filters-container {
                top: auto;
                bottom: 20px;
                right: 10px;
                left: 10px;
                max-width: calc(100vw - 20px);
                min-width: auto;
                max-height: calc(100vh - 140px); /* Leave space for controls */
            }
            .filters-content {
                padding: 14px 16px;
                gap: 16px;
            }
            .filter-section {
                padding-bottom: 16px;
            }
            .level-btn,
            .status-filter-btn {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            .section-filter-select {
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 576px) {
            .filters-container {
                bottom: 10px;
                right: 8px;
                left: 8px;
                max-width: calc(100vw - 16px);
                max-height: calc(100vh - 120px);
                border-radius: 12px;
            }
            .filters-header {
                padding: 12px 16px;
                font-size: 0.9rem;
            }
            .filters-content {
                padding: 12px 16px;
                gap: 16px;
            }
            .filter-section {
                padding-bottom: 16px;
            }
            .filter-section-title {
                font-size: 0.7rem;
                margin-bottom: 10px;
            }
            .level-btn,
            .status-filter-btn {
                padding: 8px 10px;
                font-size: 0.75rem;
            }
            .section-filter-select {
                font-size: 0.75rem;
                padding: 8px 10px;
            }
        }

        .filters-container:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15), 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .filters-header {
            background: #2b4c7e;
            border: 1px solid #2b4c7e;
            color: #fff;
            padding: 16px 20px;
            margin: 0;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .filters-header:hover {
            background-color: #1f3659;
            border-color: #1f3659;
        }

        .filters-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-header i {
            font-size: 1.1rem;
        }

        .filters-toggle {
            font-size: 0.9rem;
            opacity: 0.9;
            transition: transform 0.3s ease;
        }

        .filters-container.collapsed .filters-toggle {
            transform: rotate(-90deg);
        }

        .filters-container.collapsed .filters-content {
            display: none;
        }

        .filters-content {
            padding: 16px 20px;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .filter-section {
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(12, 13, 14, 0.8);
        }

        .filter-section:last-of-type {
            border-bottom: none;
            padding-bottom: 0;
        }

        .filter-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .level-controls,
        .status-controls,
        .section-controls {
            background: transparent;
            padding: 0;
            border-radius: 0;
            box-shadow: none;
            position: static;
            min-width: auto;
            font-size: 12px;
        }
        .section-filter-select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .section-filter-select:focus {
            outline: none;
            border-color: #2b4c7e;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
        }
        }
        @media (max-width: 768px) {
            .back-button {
                top: 10px;
                left: 20px;
                padding: 8px 16px;
                font-size: 0.9rem;
            }
            .filters-container {
                position: static;
                margin: 20px auto;
                max-width: 100%;
                width: calc(100% - 32px);
                max-height: none;
            }
            .filters-content {
                padding: 12px 16px;
                max-height: none;
            }
            .filter-section {
                padding-bottom: 16px;
                margin-bottom: 16px;
            }
        }
        .level-buttons {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 8px;
        }
        .level-btn {
            padding: 6px 10px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.8rem;
        }
        .level-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        .level-btn.active {
            background: #2b4c7e;
            color: white;
            border-color: #2b4c7e;
        }
        .level-btn .level-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 6px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            vertical-align: middle;
        }
        .level-btn.active .level-indicator {
            border-color: rgba(255, 255, 255, 0.5);
        }
        .status-filter-buttons {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .status-filter-btn {
            padding: 6px 10px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.8rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .status-filter-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        .status-filter-btn.active {
            background: #2b4c7e;
            color: white;
            border-color: #2b4c7e;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }
        .status-filter-btn.active .status-indicator {
            border-color: rgba(255, 255, 255, 0.5);
        }
        .status-indicator.available { background-color: #4caf50; }
        .status-indicator.reserved { background-color: #ff9800; }
        .status-indicator.occupied { background-color: #f44336; }
        .level-info {
            font-size: 0.75rem;
            color: #666;
            text-align: center;
            padding-top: 6px;
            border-top: 1px solid #eee;
        }
        .level-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 6px;
            border: 1px solid #fff;
        }
        .level-1 { background-color: #00bcd4; }  /* Cyan for Row A */
        .level-2 { background-color: #2196f3; }  /* Blue for Row B */
        .level-3 { background-color: #9c27b0; }  /* Purple for Row C */
        .level-4 { background-color: #e91e63; }  /* Pink for Row D */
        .level-5 { background-color: #795548; }  /* Brown for Row E */
        
        .legend-section {
            margin-bottom: 15px;
        }
        .legend-section:last-child {
            margin-bottom: 0;
        }

        /* Fullscreen exit overlay button (top-center) */
        .fullscreen-exit-overlay {
            position: fixed;
            top: 14px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1100;
            display: none; /* shown only in fullscreen mode */
            pointer-events: none; /* inner button handles clicks */
        }
        .fullscreen-exit-btn {
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: none;
            background: rgba(148, 163, 184, 0.8); /* gray-ish, slightly transparent */
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.4);
            cursor: pointer;
            pointer-events: auto;
        }
        .fullscreen-exit-btn i {
            pointer-events: none;
        }

        /* Zoom controls (center left) */
        .leaflet-control-zoom {
            position: fixed !important;
            left: 20px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            margin: 0 !important;
            border: none !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.25) !important;
            border-radius: 12px !important;
            overflow: hidden !important;
            z-index: 1001 !important;
        }

        .leaflet-control-zoom a {
            width: 60px !important;
            height: 60px !important;
            line-height: 60px !important;
            text-align: center !important;
            background: #ffffff !important;
            color: #2b4c7e !important;
            border: 1px solid #e2e8f0 !important;
            font-size: 28px !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            -webkit-tap-highlight-color: rgba(43, 76, 126, 0.2) !important;
        }

        .leaflet-control-zoom a:hover,
        .leaflet-control-zoom a:active {
            background: #2b4c7e !important;
            color: #ffffff !important;
            border-color: #2b4c7e !important;
        }

        .leaflet-control-zoom-in {
            border-bottom: 1px solid #e2e8f0 !important;
        }
        
        /* Responsive zoom controls for mobile */
        @media (max-width: 768px) {
            .leaflet-control-zoom {
                left: 10px !important;
                top: auto !important;
                bottom: 100px !important; /* Above map type toggle */
                transform: none !important;
                border-radius: 10px !important;
            }
            .leaflet-control-zoom a {
                width: 44px !important;
                height: 44px !important;
                line-height: 44px !important;
                font-size: 22px !important;
            }
        }
        
        @media (max-width: 576px) {
            .leaflet-control-zoom {
                left: 8px !important;
                bottom: 90px !important;
                border-radius: 8px !important;
            }
            .leaflet-control-zoom a {
                width: 40px !important;
                height: 40px !important;
                line-height: 40px !important;
                font-size: 20px !important;
            }
        }

        /* Map type toggle (Default / Satellite) */
        .map-type-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1001;
        }

        .map-type-btn {
            position: relative;
            border: none;
            width: 80px;   /* ~3x larger tile */
            height: 90px;
            border-radius: 18px;
            cursor: pointer;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.55);
            border: 2px solid #ffffff;
            background: transparent;
        }

        #mapTypeMiniMap {
            position: absolute;
            inset: 0;
            border-radius: 16px;
            overflow: hidden;
            z-index: 0;
            pointer-events: none; /* allow clicks & pointer cursor on the button */
        }

        .map-type-label {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 4px 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #ffffff;
            text-align: center;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
            pointer-events: none;
            z-index: 1; /* always visible above mini map, never fades */
        }

        .map-type-btn.active {
            border-color: #2b4c7e;
            box-shadow: 0 0 0 2px rgba(43, 76, 126, 0.9), 0 6px 16px rgba(15, 23, 42, 0.7);
        }

        .plot-type-filter-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .plot-type-buttons {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .plot-type-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.75rem;
            text-align: left;
            font-weight: 500;
            color: #333;
        }

        .plot-type-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        .plot-type-btn.active {
            background: #2b4c7e;
            color: white;
            border-color: #2b4c7e;
        }

        @media (max-width: 768px) {
            .map-type-toggle {
                bottom: 90px;
                left: 10px;
            }
            .map-type-btn {
                width: 70px;
                height: 70px;
            }
            #mapTypeMiniMap {
                width: 60px;
                height: 60px;
            }
        }
        
        @media (max-width: 576px) {
            .map-type-toggle {
                bottom: 80px;
                left: 8px;
            }
            .map-type-btn {
                width: 60px;
                height: 60px;
            }
            #mapTypeMiniMap {
                width: 50px;
                height: 50px;
            }
            .map-type-label {
                font-size: 9px;
            }
        }

        /* Fullscreen mode: hide side UI for a cleaner map */
        body.map-fullscreen-active .controls,
        body.map-fullscreen-active .filters-container,
        body.map-fullscreen-active .back-button {
            display: none;
        }
        body.map-fullscreen-active .fullscreen-exit-overlay {
            display: block;
        }

        /* Notification bubble (top-center) */
        .notification-bubble {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%) translateY(-100px);
            padding: 16px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            z-index: 9999;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 500px;
            word-wrap: break-word;
        }
        
        .notification-bubble.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        .notification-bubble.hide {
            transform: translateX(-50%) translateY(-100px);
            opacity: 0;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-left: 4px solid #065f46;
        }
        
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-left: 4px solid #991b1b;
        }
        
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-bubble span {
            flex: 1;
        }
        
        /* Responsive notification bubble */
        @media (max-width: 768px) {
            .notification-bubble {
                top: 60px;
                left: 10px;
                right: 10px;
                transform: translateY(-100px);
                max-width: calc(100vw - 20px);
                padding: 12px 16px;
                font-size: 13px;
            }
            .notification-bubble.show {
                transform: translateY(0);
            }
        }
        
        @media (max-width: 576px) {
            .notification-bubble {
                top: 50px;
                left: 8px;
                right: 8px;
                max-width: calc(100vw - 16px);
                padding: 10px 14px;
                font-size: 12px;
            }
        }
        
        /* Make Leaflet controls touch-friendly on mobile */
        @media (max-width: 768px) {
            .leaflet-control-zoom a {
                width: 36px;
                height: 36px;
                line-height: 36px;
                font-size: 20px;
            }
            .leaflet-popup-content-wrapper {
                max-width: calc(100vw - 40px) !important;
            }
            .plot-popup {
                padding: 10px;
            }
            .plot-popup h3 {
                font-size: 0.9rem;
            }
            .plot-popup p {
                font-size: 0.75rem;
            }
            
            /* Improve touch zoom on mobile */
            .leaflet-container {
                touch-action: pan-x pan-y pinch-zoom;
                -webkit-touch-callout: none;
                -webkit-user-select: none;
            }
            
            /* Prevent accidental zoom on double-tap for buttons */
            .btn, .back-button, .map-type-btn, .filters-header {
                touch-action: manipulation;
            }
        }
        
        /* Prevent horizontal scroll */
        html, body {
            overflow-x: hidden;
            max-width: 100%;
        }
        
        /* Fullscreen exit overlay responsive */
        .fullscreen-exit-overlay {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: none;
        }
        
        @media (max-width: 768px) {
            .fullscreen-exit-overlay {
                top: 10px;
                right: 10px;
            }
        }

    </style>
</head>
<body>
    <div id="map"></div>
    <a href="staff_dashboard.php" class="back-button"><i class="bi bi-arrow-left"></i></a>
    
    <div class="controls">
        <div class="search-container">
            <input type="text" id="searchDeceased" class="form-control" placeholder="Search Plot (e.g., APOLLO-E1)" onkeypress="if(event.key === 'Enter') { event.preventDefault(); searchDeceased(); }">
            <button type="button" class="btn btn-primary" onclick="searchDeceased()">Search</button>
        </div>
        <div class="control-buttons">
            <!-- Refresh / reset view (tooltip removed per request) -->
            <button class="btn btn-primary" type="button" onclick="resetZoom()" title="Reset view and refresh plots">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            <!-- Fullscreen toggle (tooltip removed per request) -->
            <button class="btn btn-secondary" type="button" onclick="toggleFullscreen()">
                <i class="bi bi-fullscreen" id="fullscreenIcon"></i>
            </button>
        </div>
    </div>

    <!-- Fullscreen exit overlay (top-center) -->
    <div class="fullscreen-exit-overlay">
        <button type="button" class="fullscreen-exit-btn" onclick="toggleFullscreen()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    
    <div class="filters-container collapsed" id="filtersContainer">
        <div class="filters-header" onclick="toggleFilters()">
            <div class="filters-header-left">
                <i class="bi bi-map"></i>
                <span>Map Guide</span>
            </div>
            <i class="bi bi-chevron-down filters-toggle"></i>
        </div>
        <div class="filters-content">
            <div class="filter-section">
                <div class="filter-section-title">Plot Section</div>
                <div class="section-controls">
                    <select id="sectionFilter" class="section-filter-select">
                        <option value="all">All Sections</option>
                        <?php foreach ($all_sections as $section): ?>
                            <option value="<?php echo htmlspecialchars($section['section_name']); ?>">
                                <?php echo htmlspecialchars($section['section_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-section">
                <div class="filter-section-title">Filter by Row</div>
    <div class="level-controls">
        <div class="level-buttons">
            <button class="level-btn active" data-level="all">All Rows</button>
                        <button class="level-btn" data-level="1">Row A (Ground)</button>
            <button class="level-btn" data-level="2">Row B</button>
            <button class="level-btn" data-level="3">Row C</button>
            <button class="level-btn" data-level="4">Row D</button>
                        <button class="level-btn" data-level="5">Row E</button>
        </div>
        <div class="level-info">
            <span id="level-info-text">Showing all rows</span>
                    </div>
        </div>
    </div>
    
            <div class="filter-section">
                <div class="filter-section-title">Plot Status</div>
                <div class="status-controls">
                    <div class="status-filter-buttons" style="display: flex; flex-direction: column; gap: 4px;">
                        <button class="status-filter-btn active" data-status="all">All Status</button>
                        <button class="status-filter-btn" data-status="available">
                            <span class="status-indicator available"></span>Available
                        </button>
                        <button class="status-filter-btn" data-status="occupied">
                            <span class="status-indicator occupied"></span>Occupied
                        </button>
                        <button class="status-filter-btn" data-status="reserved">
                            <span class="status-indicator reserved"></span>Reserved
                        </button>
            </div>
            </div>
            </div>

            <!-- Plot Type filter moved inside Map Guide panel, just below Plot Status -->
            <div class="plot-type-filter">
                <div class="plot-type-filter-title">Plot Type</div>
                <div class="plot-type-buttons">
                    <button class="plot-type-btn active" data-plot-type="all">All Types</button>
                    <button class="plot-type-btn" data-plot-type="lawn">Lawn Lot (Ground Level)</button>
                    <button class="plot-type-btn" data-plot-type="niche">Apartment-Type Niches</button>
        </div>
            </div>

            </div>
            </div>

    <div class="map-type-toggle">
        <!-- Single toggle tile; live mini-map preview + label -->
        <button class="map-type-btn" data-map-type="satellite">
            <div id="mapTypeMiniMap"></div>
            <span class="map-type-label">Satellite</span>
        </button>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Keep zoom within a safe but detailed range where tiles are consistently available
        const MIN_TILE_ZOOM = 17;
        const MAX_TILE_ZOOM = 22;
        const INITIAL_ZOOM = 18; // Default zoom when page first loads
        
        // Helper function to get responsive zoom level
        function getResponsiveZoom(desktopZoom, mobileZoom = null) {
            const isMobile = window.innerWidth <= 768;
            return isMobile ? (mobileZoom || desktopZoom - 1) : desktopZoom;
        }

        // Initialize the map with offline support (same behavior as kiosk map)
        // Adjust initial zoom for mobile devices
        const isMobileDevice = window.innerWidth <= 768;
        const adjustedInitialZoom = getResponsiveZoom(INITIAL_ZOOM, 17);
        const map = offlineMap.initializeMap('map', [14.2645191, 120.8654277], adjustedInitialZoom);
        map.setMinZoom(MIN_TILE_ZOOM);
        map.setMaxZoom(MAX_TILE_ZOOM);
        
        // Improve touch handling for mobile zoom
        if (isMobileDevice) {
            // Enable double-tap zoom but with better control
            map.doubleClickZoom.enable();
            // Improve pinch zoom sensitivity
            map.touchZoom.enable();
            // Adjust zoom animation duration for smoother mobile experience
            map.options.zoomAnimationDuration = 0.3;
        }
        
        // Handle window resize to adjust zoom if needed
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                // Optionally adjust zoom on orientation change
                const currentZoom = map.getZoom();
                const isMobileNow = window.innerWidth <= 768;
                if (isMobileNow !== isMobileDevice && currentZoom > 20) {
                    // If switched to mobile and zoomed in too much, adjust slightly
                    map.setZoom(Math.min(currentZoom, 20));
                }
            }, 250);
        });

        // Add zoom controls positioned at center left
        const zoomControl = L.control.zoom({
            position: 'topleft' // Will be repositioned via CSS
        });
        zoomControl.addTo(map);

        // Remove base tile layers added by offlineMap so we can control map types
        map.eachLayer(layer => {
            if (layer instanceof L.TileLayer) {
                map.removeLayer(layer);
            }
        });

        // Define base layers (match kiosk map config)
        const defaultLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: MAX_TILE_ZOOM,
            maxNativeZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });
        const satelliteLayer = offlineMap.getSatelliteLayer();
        const labelsLayer = offlineMap.getLabelsOverlay();

        // Start with default map view
        let currentMapType = 'default';
        defaultLayer.addTo(map);

        // Mini-map inside the map-type tile
        const miniMapEl = document.getElementById('mapTypeMiniMap');
        let miniMap = null;
        let miniDefaultLayer = null;
        let miniSatelliteLayer = null;
        let miniLabelsLayer = null;

        if (miniMapEl) {
            miniMap = L.map(miniMapEl, {
                center: map.getCenter(),
                zoom: map.getZoom(),
                zoomControl: false,
                attributionControl: false,
                interactive: false,
                minZoom: MIN_TILE_ZOOM,
                maxZoom: MAX_TILE_ZOOM
            });

            miniDefaultLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: MAX_TILE_ZOOM
            });
            miniSatelliteLayer = offlineMap.getSatelliteLayer();
            miniLabelsLayer = offlineMap.getLabelsOverlay();

            // Initial state: main map is DEFAULT, so mini-map shows SATELLITE (opposite preview)
            miniSatelliteLayer.addTo(miniMap);
            miniLabelsLayer.addTo(miniMap);
        }
        
        // Allow maximum zoom for detailed plot viewing
        map.setMaxZoom(22);
        
        // Prevent automatic view changes
        let userHasInteracted = false;
        map.on('zoomstart', function() { userHasInteracted = true; });
        map.on('movestart', function() { userHasInteracted = true; });

        // Plot data from PHP (made let so it can be updated)
        let sections = <?php echo json_encode($sections); ?>;
        let allSections = <?php echo json_encode($all_sections); ?>;
        let markers = [];
        let isInitialized = false;
        let currentLevelFilter = 'all';
        let currentStatusFilter = 'all';
        let currentSectionFilter = 'all';
        let currentPlotTypeFilter = 'all';

        // Plot marker colors by status
        const plotColors = {
            available: '#4caf50',
            reserved: '#ff9800',
            occupied: '#f44336'
        };

        // Level colors for multi-level tombs (changed to avoid conflict with status colors)
        const levelColors = {
            1: '#00bcd4',  // Cyan for Row A
            2: '#2196f3',  // Blue for Row B
            3: '#9c27b0',  // Purple for Row C
            4: '#e91e63',  // Pink for Row D
            5: '#795548',  // Brown for Row E
            6: '#f44336',  // Red for level 6+
        };

        // Get color for plot based on status only (not row level)
        function getPlotColor(plot) {
            // Always use status color, regardless of row level
            return plotColors[plot.status] || plotColors.available;
        }

        // Filter plots by row level
        function filterPlotsByLevel(sections, levelFilter) {
            if (levelFilter === 'all') {
                return sections;
            }
            
            const filteredSections = {};
            Object.entries(sections).forEach(([sectionName, sectionData]) => {
                const filteredPlots = sectionData.plots.filter(plot => 
                    parseInt(plot.row_number) === parseInt(levelFilter)
                );
                if (filteredPlots.length > 0) {
                    filteredSections[sectionName] = {
                        ...sectionData,
                        plots: filteredPlots
                    };
                }
            });
            return filteredSections;
        }
        
        // Keep mini-map synced with main map
        function syncMiniMapView() {
            if (!miniMap) return;
            miniMap.setView(map.getCenter(), map.getZoom());
        }

        map.on('move zoom', syncMiniMapView);
        
        // Switch the underlying map tiles (main map + mini-map)
        function setMapType(type) {
            if (type === currentMapType) return;
            currentMapType = type;

            if (type === 'default') {
                // MAIN: default tiles
                if (!map.hasLayer(defaultLayer)) {
                    defaultLayer.addTo(map);
                }
                if (map.hasLayer(satelliteLayer)) {
                    map.removeLayer(satelliteLayer);
                }
                if (map.hasLayer(labelsLayer)) {
                    map.removeLayer(labelsLayer);
                }

                // MINI: show SATELLITE preview (opposite of main)
                if (miniMap) {
                    if (miniMap.hasLayer(miniDefaultLayer)) {
                        miniMap.removeLayer(miniDefaultLayer);
                    }
                    if (!miniMap.hasLayer(miniSatelliteLayer)) {
                        miniSatelliteLayer.addTo(miniMap);
                    }
                    if (!miniMap.hasLayer(miniLabelsLayer)) {
                        miniLabelsLayer.addTo(miniMap);
                    }
                }
            } else if (type === 'satellite') {
                // MAIN: satellite tiles
                if (map.hasLayer(defaultLayer)) {
                    map.removeLayer(defaultLayer);
                }
                if (!map.hasLayer(satelliteLayer)) {
                    satelliteLayer.addTo(map);
                }
                if (!map.hasLayer(labelsLayer)) {
                    labelsLayer.addTo(map);
                }

                // MINI: show DEFAULT preview (opposite of main)
                if (miniMap) {
                    if (!miniMap.hasLayer(miniDefaultLayer)) {
                        miniDefaultLayer.addTo(miniMap);
                    }
                    if (miniMap.hasLayer(miniSatelliteLayer)) {
                        miniMap.removeLayer(miniSatelliteLayer);
                    }
                    if (miniMap.hasLayer(miniLabelsLayer)) {
                        miniMap.removeLayer(miniLabelsLayer);
                    }
                }
            }
        }

        // Safety: ensure a base tile layer is always present on the main map
        function ensureBaseLayer() {
            const hasMainBase = map.hasLayer(defaultLayer) || map.hasLayer(satelliteLayer);
            if (!hasMainBase) {
                defaultLayer.addTo(map);
            }
        }

        map.on('zoomend moveend', ensureBaseLayer);
        
        // Update the single map-type button label based on current map type
        const mapTypeBtn = document.querySelector('.map-type-btn');
        const mapTypeLabel = document.querySelector('.map-type-label');
        
        function updateMapTypeButton() {
            if (!mapTypeBtn || !mapTypeLabel) return;
            if (currentMapType === 'default') {
                // Map is default → tile shows "Satellite"
                mapTypeBtn.dataset.mapType = 'satellite';
                mapTypeLabel.textContent = 'Satellite';
            } else {
                // Map is satellite → tile shows "Default"
                mapTypeBtn.dataset.mapType = 'default';
                mapTypeLabel.textContent = 'Default';
            }
        }
        
        if (mapTypeBtn) {
            mapTypeBtn.addEventListener('click', function () {
                const targetType = this.dataset.mapType || (currentMapType === 'default' ? 'satellite' : 'default');
                setMapType(targetType);
                updateMapTypeButton();
            });
            // Initial label
            updateMapTypeButton();
        }

        // Filter plots by status
        function filterPlotsByStatus(sections, statusFilter) {
            if (statusFilter === 'all') {
                return sections;
            }
            
            const filteredSections = {};
            Object.entries(sections).forEach(([sectionName, sectionData]) => {
                const filteredPlots = sectionData.plots.filter(plot => 
                    plot.status === statusFilter
                );
                if (filteredPlots.length > 0) {
                    filteredSections[sectionName] = {
                        ...sectionData,
                        plots: filteredPlots
                    };
                }
            });
            return filteredSections;
        }

        // Filter plots by plot type (lawn lot vs apartment-type niches)
        // Apartment-type niches sections: AION, APHRODITE, ATHENA, etc.
        const apartmentTypeSections = ['AION', 'APHRODITE', 'ATHENA', 'ARIES', 'ATLAS', 'ARTEMIS', 'APOLLO', 'AURA', 'ASTREA', 'ARIA'];
        
        function filterPlotsByType(sections, plotTypeFilter) {
            if (plotTypeFilter === 'all') {
                return sections;
            }
            
            const filteredSections = {};
            Object.entries(sections).forEach(([sectionName, sectionData]) => {
                const sectionNameUpper = sectionName.toUpperCase();
                const isApartmentType = apartmentTypeSections.some(aptSection => 
                    sectionNameUpper.includes(aptSection) || sectionNameUpper === aptSection
                );
                
                if (plotTypeFilter === 'niche') {
                    // Apartment-type niches: sections like AION, APHRODITE, ATHENA, etc.
                    if (isApartmentType) {
                        filteredSections[sectionName] = sectionData;
                    }
                } else if (plotTypeFilter === 'lawn') {
                    // Lawn lot: all other sections
                    if (!isApartmentType) {
                        filteredSections[sectionName] = sectionData;
                    }
                }
            });
            return filteredSections;
        }

        // Filter plots by section
        function filterPlotsBySection(sections, sectionFilter) {
            if (sectionFilter === 'all') {
                return sections;
            }
            
            const filteredSections = {};
            Object.entries(sections).forEach(([sectionName, sectionData]) => {
                if (sectionName === sectionFilter) {
                    filteredSections[sectionName] = sectionData;
                }
            });
            return filteredSections;
        }

        // Add section labels and plot markers to the map
        function addPlotMarkers(filteredSections = sections, fitBounds = false) {
            console.log('addPlotMarkers called with fitBounds:', fitBounds, 'currentLevelFilter:', currentLevelFilter);
            
            // Apply section filter first
            let sectionFilteredSections = filterPlotsBySection(filteredSections, currentSectionFilter);
            // Apply plot type filter
            let typeFilteredSections = filterPlotsByType(sectionFilteredSections, currentPlotTypeFilter);
            // Apply level filter
            let levelFilteredSections = filterPlotsByLevel(typeFilteredSections, currentLevelFilter);
            // Apply status filter
            levelFilteredSections = filterPlotsByStatus(levelFilteredSections, currentStatusFilter);
            console.log('Filtered sections:', levelFilteredSections);
            
            // Always clear and redraw markers when filter changes
            // Remove the condition that was preventing updates
            
            // Clear existing markers
            markers.forEach(marker => map.removeLayer(marker));
            markers = [];

            // Add new markers
            const bounds = L.latLngBounds();

            Object.entries(levelFilteredSections).forEach(([sectionName, sectionData]) => {
                const plots = sectionData.plots;
                if (plots.length === 0) return;

                // Calculate center point of the section
                const sectionLats = plots.map(p => parseFloat(p.latitude));
                const sectionLngs = plots.map(p => parseFloat(p.longitude));
                const centerLat = sectionLats.reduce((a, b) => a + b) / sectionLats.length;
                const centerLng = sectionLngs.reduce((a, b) => a + b) / sectionLngs.length;

                // Add section label (section name only)
                const sectionLabel = L.divIcon({
                    className: 'section-label',
                    html: `<div class="section-name" style="cursor:pointer;">${sectionName}</div>`,
                    iconSize: [100, 30],
                    iconAnchor: [50, 15]
                });

                const labelMarker = L.marker([centerLat, centerLng], {
                    icon: sectionLabel,
                    interactive: true
                }).addTo(map);
                labelMarker.on('click', function() {
                    window.location.href = `section_layout.php?section_id=${plots[0].section_id}`;
                });
                markers.push(labelMarker);

                // Add plot markers for this section (as circles)
                plots.forEach(plot => {
                    const plotColor = getPlotColor(plot);
                    const marker = L.circleMarker([plot.latitude, plot.longitude], {
                        radius: Math.max(4, Math.min(12, map.getZoom() - 10)), // Scale with zoom
                        fillColor: plotColor,
                        color: '#fff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.9
                    });

                    const rowLetter = String.fromCharCode(64 + parseInt(plot.row_number));

                    // Compute a consistent display label for the popup title
                    const displayLabel = (() => {
                        const rowLetter = String.fromCharCode(64 + parseInt(plot.row_number));
                        const base = `${rowLetter}${plot.plot_number}`;
                        return plot.section_code ? `${plot.section_code}-${base}` : base;
                    })();

                    // Format date as "Jul. 13-2025"
                    const formatBurialDate = (dateString) => {
                        if (!dateString) return '—';
                        const date = new Date(dateString);
                        if (isNaN(date.getTime())) return '—';
                        const months = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
                        const month = months[date.getMonth()];
                        const day = date.getDate();
                        const year = date.getFullYear();
                        return `${month} ${day}-${year}`;
                    };

                    // Build deceased details block, supporting multiple deceased per plot
                    let deceasedHtml = '';
                    if (Array.isArray(plot.deceased) && plot.deceased.length > 0) {
                        const multiple = plot.deceased.length > 1;
                        const itemsHtml = plot.deceased.map((d, index) => {
                            const nameLabel = multiple ? `${index + 1}. ${d.full_name}` : d.full_name;
                            const burialLabel = d.burial_date ? formatBurialDate(d.burial_date) : '—';
                            return `
                                <li>
                                    <span class="deceased-name">${nameLabel}</span><br>
                                    <span class="deceased-date"><strong>Burial:</strong> ${burialLabel}</span>
                                </li>
                            `;
                        }).join('');

                        deceasedHtml = `
                            <div class="deceased-info">
                                <p><strong>${multiple ? 'Deceased (multiple):' : 'Deceased:'}</strong></p>
                                <ul class="deceased-list">
                                    ${itemsHtml}
                                </ul>
                            </div>
                        `;
                    } else if (plot.full_name) {
                        // Backward-compatible fallback when only a single deceased is present
                        deceasedHtml = `
                            <div class="deceased-info">
                                <p><strong>Deceased Name:</strong><br>${plot.full_name}</p>
                                <p><strong>Date of Burial:</strong> ${formatBurialDate(plot.burial_date)}</p>
                            </div>
                        `;
                    }

                    const popupContent = `
                        <div class="plot-popup">
                            <h3>${displayLabel}</h3>
                            <p><strong>Status:</strong> <span class="status ${plot.status}">${plot.status.charAt(0).toUpperCase() + plot.status.slice(1)}</span></p>
                            ${deceasedHtml}
                            <a href="plot_details.php?id=${plot.plot_id}&from=maps" class="btn btn-primary">View Details</a>
                        </div>
                    `;

                    marker.bindPopup(popupContent);
                    marker.addTo(map);
                    markers.push(marker);
                    bounds.extend([plot.latitude, plot.longitude]);
                });
            });

            // Only fit bounds if explicitly requested (initial load)
            // Adjust padding based on screen size for better mobile experience
            const isMobile = window.innerWidth <= 768;
            const padding = isMobile ? [100, 30] : [50, 50]; // More top padding on mobile for controls
            
            if (fitBounds && Object.keys(levelFilteredSections).length > 0) {
                map.fitBounds(bounds, { padding: padding });
                isInitialized = true;
            } else if (Object.keys(levelFilteredSections).length > 0) {
                // If not fitting bounds but we have filtered data, still fit to show the filtered plots
                map.fitBounds(bounds, { padding: padding });
            }
        }

        // Add styles for section labels
        const mapStyles = document.createElement('style');
        mapStyles.textContent = `
            .section-label {
                background: none;
                border: none;
            }
            .section-name {
                background: rgba(255, 255, 255, 0.5);
                border-radius: 4px;
                border: 2px solid rgba(51, 51, 51, 0.3);
                font-weight: bold;
                font-size: 12px;
                text-align: center;
                white-space: nowrap;
                padding: 2px 6px;
                max-width: 100px;
                width: 100%;
                box-sizing: border-box;
                overflow: hidden;
                text-overflow: ellipsis;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                pointer-events: none;
            }
        `;
        document.head.appendChild(mapStyles);

        // Level control event handlers
        document.querySelectorAll('.level-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.level-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update level filter
                currentLevelFilter = this.dataset.level;
                
                // Update info text
                const infoText = currentLevelFilter === 'all' ? 'Showing all rows' : `Showing row ${String.fromCharCode(64 + parseInt(currentLevelFilter))}`;
                document.getElementById('level-info-text').textContent = infoText;
                
                // Refresh markers with new filter
                addPlotMarkers(sections, false);
            });
        });

        // Status filter event handlers
        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update status filter
                currentStatusFilter = this.dataset.status;
                
                // Refresh markers with new filter
                addPlotMarkers(sections, false);
            });
        });

        // Section filter event handler
        document.getElementById('sectionFilter').addEventListener('change', function() {
            // Update section filter
            currentSectionFilter = this.value;
            
            // Refresh markers with new filter
            addPlotMarkers(sections, false);
        });

        // Plot type filter event handlers
        document.querySelectorAll('.plot-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.plot-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update plot type filter
                currentPlotTypeFilter = this.dataset.plotType;
                
                // Refresh markers with new filter
                addPlotMarkers(sections, false);
            });
        });
        
        // Fullscreen toggle (hide controls + filters for clean map)
        function toggleFullscreen() {
            const body = document.body;
            const icon = document.getElementById('fullscreenIcon');
            body.classList.toggle('map-fullscreen-active');
            if (icon) {
                if (body.classList.contains('map-fullscreen-active')) {
                    icon.classList.remove('bi-fullscreen');
                    icon.classList.add('bi-fullscreen-exit');
                } else {
                    icon.classList.remove('bi-fullscreen-exit');
                    icon.classList.add('bi-fullscreen');
                }
            }
        }

        // Initial plot markers
        addPlotMarkers(sections, true);
        // Initial label scaling
        updateSectionLabelScale();
        
        // Debug: Check if there are any intervals or timers
        console.log('Map initialized. Checking for any auto-refresh...');
        
        // Monitor map view changes and update marker sizes
        function updateSectionLabelScale() {
            const currentZoom = map.getZoom();
            const labels = document.querySelectorAll('.section-name');
            labels.forEach(label => {
                if (currentZoom <= 18) {
                    label.classList.add('small');
                } else {
                    label.classList.remove('small');
                }
            });
        }

        // Update popup sizes based on zoom level
        function updatePopupSizes(zoom) {
            // Calculate scale factor based on zoom (zoom 15-22 range)
            // At zoom 15: smaller (0.6x), at zoom 22: larger (1.2x)
            const minZoom = 15;
            const maxZoom = 22;
            // Normalize zoom to 0-1 range, then scale to 0.6-1.2 range
            const normalizedZoom = (zoom - minZoom) / (maxZoom - minZoom);
            const scale = 0.6 + (normalizedZoom * 0.6); // Range: 0.6 to 1.2
            
            // Update all open popups
            document.querySelectorAll('.plot-popup').forEach(popup => {
                // Base padding: 8px, scaled
                popup.style.padding = `${8 * scale}px`;
                
                const h3 = popup.querySelector('h3');
                if (h3) {
                    // Base font size: 1rem, scaled
                    h3.style.fontSize = `${1 * scale}rem`;
                    h3.style.marginBottom = `${8 * scale}px`;
                    h3.style.paddingBottom = `${6 * scale}px`;
                }
                
                popup.querySelectorAll('p').forEach(p => {
                    // Base font size: 0.8rem, scaled
                    p.style.fontSize = `${0.8 * scale}rem`;
                    p.style.marginBottom = `${6 * scale}px`;
                });
                
                popup.querySelectorAll('.status').forEach(status => {
                    // Base font size: 0.7rem, scaled
                    status.style.fontSize = `${0.7 * scale}rem`;
                    status.style.padding = `${3 * scale}px ${6 * scale}px`;
                });
                
                const btn = popup.querySelector('.btn-primary');
                if (btn) {
                    // Base font size: 0.75rem, scaled
                    btn.style.fontSize = `${0.75 * scale}rem`;
                    btn.style.padding = `${5 * scale}px ${10 * scale}px`;
                    btn.style.marginTop = `${8 * scale}px`;
                }
            });
            
            // Update popup container max-width
            document.querySelectorAll('.leaflet-popup-content-wrapper').forEach(wrapper => {
                // Base max-width: 250px, scaled
                wrapper.style.maxWidth = `${250 * scale}px`;
            });
        }

        map.on('zoomend', function() {
            const currentZoom = map.getZoom();
            console.log('Zoom changed to:', currentZoom);
            
            // Update marker sizes based on zoom level
            markers.forEach(marker => {
                if (marker.setRadius) { // Only for circle markers, not section labels
                    const newRadius = Math.max(4, Math.min(12, currentZoom - 10));
                    marker.setRadius(newRadius);
                }
            });

            // Shrink/expand section labels based on zoom
            updateSectionLabelScale();
            
            // Update popup sizes based on zoom
            updatePopupSizes(currentZoom);
        });
        
        // Also update popup size when a popup is opened
        map.on('popupopen', function(e) {
            const currentZoom = map.getZoom();
            updatePopupSizes(currentZoom);
        });
        
        map.on('moveend', function() {
            console.log('Map moved to:', map.getCenter());
        });
        
        // Toggle filters container collapse/expand
        function toggleFilters() {
            const filtersContainer = document.getElementById('filtersContainer');
            filtersContainer.classList.toggle('collapsed');
        }
        
        // Zoom functions
        function zoomIn() {
            map.zoomIn();
        }

        function zoomOut() {
            map.zoomOut();
        }

        // Utility to remove any existing notification bubbles (e.g., after refresh)
        function clearNotification() {
            const existing = document.getElementById('mapNotification');
            if (existing) {
                existing.remove();
            }
        }

        // Refresh plot data from server
        function refreshPlotData(fitBounds = false) {
            clearNotification();
            fetch('../api/get_map_plots.php?t=' + Date.now())
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.sections) {
                        sections = data.sections;
                        if (data.all_sections) {
                            allSections = data.all_sections;
                            // Update section filter dropdown
                            const sectionFilter = document.getElementById('sectionFilter');
                            if (sectionFilter) {
                                const currentValue = sectionFilter.value;
                                sectionFilter.innerHTML = '<option value="all">All Sections</option>';
                                allSections.forEach(section => {
                                    const option = document.createElement('option');
                                    option.value = section.section_name;
                                    option.textContent = section.section_name;
                                    if (currentValue === section.section_name) {
                                        option.selected = true;
                                    }
                                    sectionFilter.appendChild(option);
                                });
                            }
                        }
                        // Refresh markers with updated data
                        addPlotMarkers(sections, fitBounds);
                    } else {
                        throw new Error('Invalid response from server');
                    }
                })
                .catch(error => {
                    console.error('Error refreshing plot data:', error);
                    showNotification('Error refreshing plot data. Please try again.', 'error');
                });
        }

        function resetZoom() {
            clearNotification();
            // Clear search input and close any open popups
            const searchInput = document.getElementById('searchDeceased');
            if (searchInput) {
                searchInput.value = '';
            }
            map.closePopup();

            // Reset all filters to default values
            // Reset filter variables
            currentLevelFilter = 'all';
            currentStatusFilter = 'all';
            currentSectionFilter = 'all';
            currentPlotTypeFilter = 'all';

            // Reset section filter dropdown to "All Sections"
            const sectionFilter = document.getElementById('sectionFilter');
            if (sectionFilter) {
                sectionFilter.value = 'all';
            }

            // Reset row filter buttons to "All Rows"
            document.querySelectorAll('.level-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.level === 'all') {
                    btn.classList.add('active');
                }
            });
            const levelInfoText = document.getElementById('level-info-text');
            if (levelInfoText) {
                levelInfoText.textContent = 'Showing all rows';
            }

            // Reset status filter buttons to "All Status"
            document.querySelectorAll('.status-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.status === 'all') {
                    btn.classList.add('active');
                }
            });

            // Reset plot type filter buttons to "All Types"
            document.querySelectorAll('.plot-type-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.plotType === 'all') {
                    btn.classList.add('active');
                }
            });

            // Refresh plot data with fitBounds=true to reset view
            refreshPlotData(true);
        }

        // Show notification bubble (left side)
        function showNotification(message, type = 'error') {
            const existingNotification = document.getElementById('mapNotification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            const notification = document.createElement('div');
            notification.id = 'mapNotification';
            notification.className = `notification-bubble ${type}-notification`;
            notification.innerHTML = `
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => {
                    notification.remove();
                }, 400);
            }, 4000);
        }

        // Parse search term to detect pattern: section, section-row, or full plot
        function parseSearchTerm(term) {
            const upperTerm = term.toUpperCase().trim();
            
            // Pattern 1: Full plot (e.g., "ATHENA-A1", "APOLLO-A19", "ATHENA-A-1")
            // Matches: SECTION-ROWNUMBER or SECTION-ROW-NUMBER
            const fullPlotMatch1 = upperTerm.match(/^([A-Z]+)-([A-Z])(\d+)$/); // ATHENA-A1
            const fullPlotMatch2 = upperTerm.match(/^([A-Z]+)-([A-Z])-(\d+)$/); // ATHENA-A-1
            const fullPlotMatch = fullPlotMatch1 || fullPlotMatch2;
            if (fullPlotMatch) {
                return {
                    type: 'full-plot',
                    section: fullPlotMatch[1],
                    row: fullPlotMatch[2],
                    plotNumber: fullPlotMatch[3],
                    original: term
                };
            }
            
            // Pattern 2: Section with row (e.g., "ATHENA-B", "APOLLO-A")
            // Matches: SECTION-ROW (but not SECTION-ROW-NUMBER)
            const sectionRowMatch = upperTerm.match(/^([A-Z]+)-([A-Z])$/);
            if (sectionRowMatch) {
                return {
                    type: 'section-row',
                    section: sectionRowMatch[1],
                    row: sectionRowMatch[2],
                    original: term
                };
            }
            
            // Pattern 3: Section only (e.g., "ATHENA", "APOLLO")
            // Just the section name (all uppercase letters, no dashes or numbers)
            if (/^[A-Z]+$/.test(upperTerm)) {
                return {
                    type: 'section',
                    section: upperTerm,
                    original: term
                };
            }
            
            // Default: treat as regular search (plot number or deceased name)
            return {
                type: 'regular',
                original: term
            };
        }

        // Search function
        function searchDeceased() {
            const searchTerm = document.getElementById('searchDeceased').value.trim();
            if (!searchTerm) {
                showNotification('Please enter a plot number or deceased name to search.', 'error');
                return;
            }

            console.log('Searching for:', searchTerm);
            
            // Parse the search term
            const parsed = parseSearchTerm(searchTerm);
            console.log('Parsed search term:', parsed);
            
            // Helper function to find section by name or code
            function findSection(sectionName) {
                // First try exact match by key
                if (sections[sectionName]) {
                    return { key: sectionName, data: sections[sectionName] };
                }
                
                // Try case-insensitive match by key
                for (const key in sections) {
                    if (key.toUpperCase() === sectionName) {
                        return { key: key, data: sections[key] };
                    }
                }
                
                // Try matching by section_code
                for (const key in sections) {
                    const sectionData = sections[key];
                    if (sectionData.section_code && sectionData.section_code.toUpperCase() === sectionName) {
                        return { key: key, data: sectionData };
                    }
                }
                
                return null;
            }
            
            // Handle section-only search
            if (parsed.type === 'section') {
                const sectionName = parsed.section;
                const found = findSection(sectionName);
                
                if (found && found.data.plots && found.data.plots.length > 0) {
                    // Calculate center of section
                    const sectionLats = found.data.plots.map(p => parseFloat(p.latitude));
                    const sectionLngs = found.data.plots.map(p => parseFloat(p.longitude));
                    const centerLat = sectionLats.reduce((a, b) => a + b) / sectionLats.length;
                    const centerLng = sectionLngs.reduce((a, b) => a + b) / sectionLngs.length;
                    
                    // Set section filter
                    currentSectionFilter = found.key;
                    const sectionFilter = document.getElementById('sectionFilter');
                    if (sectionFilter) {
                        sectionFilter.value = found.key;
                    }
                    
                    // Reset row filter to "all" to show all rows
                    currentLevelFilter = 'all';
                    document.querySelectorAll('.level-btn').forEach(btn => {
                        btn.classList.remove('active');
                        if (btn.dataset.level === 'all') {
                            btn.classList.add('active');
                        }
                    });
                    
                    // Update info text
                    const levelInfoText = document.getElementById('level-info-text');
                    if (levelInfoText) {
                        levelInfoText.textContent = 'Showing all rows';
                    }
                    
                    // Refresh markers with filter (showing all rows)
                    addPlotMarkers(sections, false);
                    
                    // Center map on section with responsive zoom
                    const isMobile = window.innerWidth <= 768;
                    const zoomLevel = isMobile ? 18 : 19;
                    map.flyTo([centerLat, centerLng], zoomLevel, { duration: 0.8 });
                    return;
                } else {
                    showNotification(`Section "${sectionName}" not found.`, 'error');
                    return;
                }
            }
            
            // Handle section-row search
            if (parsed.type === 'section-row') {
                const sectionName = parsed.section;
                const rowLetter = parsed.row;
                const rowNumber = rowLetter.charCodeAt(0) - 64; // A=1, B=2, etc.
                
                const found = findSection(sectionName);
                
                if (found && found.data.plots && found.data.plots.length > 0) {
                    // Filter plots by row
                    const rowPlots = found.data.plots.filter(p => parseInt(p.row_number) === rowNumber);
                    
                    if (rowPlots.length > 0) {
                        // Calculate center of row
                        const rowLats = rowPlots.map(p => parseFloat(p.latitude));
                        const rowLngs = rowPlots.map(p => parseFloat(p.longitude));
                        const centerLat = rowLats.reduce((a, b) => a + b) / rowLats.length;
                        const centerLng = rowLngs.reduce((a, b) => a + b) / rowLngs.length;
                        
                        // Set filters to show only this section and row
                        currentSectionFilter = found.key;
                        currentLevelFilter = rowNumber.toString();
                        
                        // Update filter UI
                        const sectionFilter = document.getElementById('sectionFilter');
                        if (sectionFilter) {
                            sectionFilter.value = found.key;
                        }
                        
                        // Update level filter buttons
                        document.querySelectorAll('.level-btn').forEach(btn => {
                            btn.classList.remove('active');
                            if (btn.dataset.level === rowNumber.toString()) {
                                btn.classList.add('active');
                            }
                        });
                        
                        // Update info text
                        const infoText = `Showing row ${rowLetter}`;
                        const levelInfoText = document.getElementById('level-info-text');
                        if (levelInfoText) {
                            levelInfoText.textContent = infoText;
                        }
                        
                        // Refresh markers with filters
                        addPlotMarkers(sections, false);
                        
                        // Center map on row with responsive zoom
                        const isMobile = window.innerWidth <= 768;
                        const zoomLevel = isMobile ? 19 : 20;
                        map.flyTo([centerLat, centerLng], zoomLevel, { duration: 0.8 });
                        return;
                    } else {
                        showNotification(`No plots found in ${sectionName} row ${rowLetter}.`, 'error');
                        return;
                    }
                } else {
                    showNotification(`Section "${sectionName}" not found.`, 'error');
                    return;
                }
            }
            
            // Handle full plot search (e.g., "APOLLO-A1", "ATHENA-A19")
            if (parsed.type === 'full-plot') {
                const sectionName = parsed.section;
                const rowLetter = parsed.row;
                const plotNumber = parsed.plotNumber;
                const rowNumber = rowLetter.charCodeAt(0) - 64; // A=1, B=2, etc.
                
                const found = findSection(sectionName);
                
                if (found && found.data.plots && found.data.plots.length > 0) {
                    // Search for the specific plot within the section
                    // The plot_number in database might be in different formats: "1", "A1", "A-1", etc.
                    const matchingPlot = found.data.plots.find(p => {
                        const pRowNumber = parseInt(p.row_number);
                        
                        // First check if row matches
                        if (pRowNumber !== rowNumber) return false;
                        
                        // Get plot number and normalize it
                        const pPlotNum = (p.plot_number || '').toString().trim();
                        const pPlotNumUpper = pPlotNum.toUpperCase();
                        
                        // Remove any section code prefix if present (e.g., "APO-A1" -> "A1")
                        let normalizedPlotNum = pPlotNumUpper;
                        if (found.data.section_code) {
                            const sectionCodeUpper = found.data.section_code.toUpperCase();
                            if (normalizedPlotNum.startsWith(sectionCodeUpper + '-')) {
                                normalizedPlotNum = normalizedPlotNum.substring(sectionCodeUpper.length + 1);
                            }
                        }
                        
                        // Extract the numeric part from the plot number
                        const numMatch = normalizedPlotNum.match(/\d+/);
                        const plotNumFromDB = numMatch ? numMatch[0] : '';
                        
                        // Match the numeric part exactly (e.g., "5" should match "5", not "15" or "50")
                        if (plotNumFromDB === plotNumber) {
                            return true;
                        }
                        
                        // Also try exact string matches for formats like "A5", "A-5"
                        if (normalizedPlotNum === rowLetter + plotNumber ||
                            normalizedPlotNum === rowLetter + '-' + plotNumber ||
                            normalizedPlotNum === plotNumber) {
                            return true;
                        }
                        
                        return false;
                    });
                    
                    if (matchingPlot) {
                        const lat = parseFloat(matchingPlot.latitude);
                        const lng = parseFloat(matchingPlot.longitude);
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            showNotification('Error: Invalid coordinates for this plot. Please contact administrator.', 'error');
                            return;
                        }
                        
                        // Set section filter to show the section
                        currentSectionFilter = found.key;
                        const sectionFilter = document.getElementById('sectionFilter');
                        if (sectionFilter) {
                            sectionFilter.value = found.key;
                        }
                        
                        // Refresh markers
                        addPlotMarkers(sections, false);
                        
                        // Wait a bit for markers to render, then find and open popup
                        setTimeout(() => {
                            // Find the marker for this plot
                            markers.forEach(marker => {
                                if (marker._popup) {
                                    const popupContent = marker._popup._content;
                                    if (popupContent && popupContent.includes(`plot_details.php?id=${matchingPlot.plot_id}`)) {
                                        marker.openPopup();
                                    }
                                }
                            });
                        }, 300);
                        
                        // Center map on the found plot with responsive zoom
                        const isMobile = window.innerWidth <= 768;
                        const zoomLevel = isMobile ? 19 : 20;
                        map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                        return;
                    } else {
                        // Try searching via API as fallback
                        const searchQuery = `${sectionName}-${rowLetter}${plotNumber}`;
                        console.log('Plot not found in local data, trying API search:', searchQuery);
                        // Fall through to regular search below
                    }
                } else {
                    showNotification(`Section "${sectionName}" not found.`, 'error');
                    return;
                }
            }
            
            // Handle regular search (plot number or deceased name)
            // First try searching by plot number
            fetch(`../api/search_plot.php?plot=${encodeURIComponent(searchTerm)}`)
                .then(response => {
                    console.log('Plot search response status:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    // Check if response is actually JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Expected JSON but got:', contentType, text);
                            throw new Error('Invalid response format from server');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Plot search response data:', data);
                    
                    // Check if plot was found
                    if (data && data.plot && data.plot.plot_id) {
                        // Validate coordinates
                        const lat = parseFloat(data.plot.latitude);
                        const lng = parseFloat(data.plot.longitude);
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            showNotification('Error: Invalid coordinates for this plot. Please contact administrator.', 'error');
                            console.error('Invalid coordinates:', data.plot);
                            return;
                        }
                        
                        // Check if coordinates are within reasonable range
                        if (lat < 14.2 || lat > 14.3 || lng < 120.8 || lng > 120.9) {
                            console.warn('Coordinates out of expected range:', lat, lng);
                        }
                        
                        console.log('Search result (plot):', {
                            plot: data.plot.plot_number,
                            coordinates: [lat, lng],
                            section: data.plot.section_name
                        });
                        
                        // Find and open popup on the matching plot marker
                        markers.forEach(marker => {
                            if (marker._popup) {
                                const popupContent = marker._popup._content;
                                if (popupContent && popupContent.includes(`plot_details.php?id=${data.plot.plot_id}`)) {
                                    marker.openPopup();
                                    const isMobile = window.innerWidth <= 768;
                                    const zoomLevel = isMobile ? 19 : 20;
                                    map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                                }
                            }
                        });

                        // Center map on the found plot
                        const isMobile = window.innerWidth <= 768;
                        const zoomLevel = isMobile ? 19 : 20;
                        map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });

                        return Promise.reject('FOUND'); // Use reject to stop the chain
                    }
                    
                    // If plot search didn't find anything, try searching by deceased name
                    console.log('Plot search found nothing, trying deceased search...');
                    return fetch(`../api/search_deceased.php?name=${encodeURIComponent(searchTerm)}`);
                })
                .then(response => {
                    // If response is undefined, it means we already found a plot and returned early
                    if (!response) {
                        return null;
                    }
                    
                    console.log('Deceased search response status:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    // Check if response is actually JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Expected JSON but got:', contentType, text);
                            throw new Error('Invalid response format from server');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    // If data is null, we already handled the plot search
                    if (data === null) {
                        return;
                    }
                    
                    console.log('Deceased search response data:', data);
                    
                    if (data && data.plot && data.plot.plot_id) {
                        // Validate coordinates
                        const lat = parseFloat(data.plot.latitude);
                        const lng = parseFloat(data.plot.longitude);
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            showNotification('Error: Invalid coordinates for this plot. Please contact administrator.', 'error');
                            console.error('Invalid coordinates:', data.plot);
                            return;
                        }
                        
                        console.log('Search result (deceased):', {
                            plot: data.plot.plot_number,
                            coordinates: [lat, lng],
                            name: (data.plot.first_name || '') + ' ' + (data.plot.last_name || '')
                        });
                        
                        // Find and open popup on the matching plot marker
                        markers.forEach(marker => {
                            if (marker._popup) {
                                const popupContent = marker._popup._content;
                                if (popupContent && popupContent.includes(`plot_details.php?id=${data.plot.plot_id}`)) {
                                    marker.openPopup();
                                    const isMobile = window.innerWidth <= 768;
                                    const zoomLevel = isMobile ? 19 : 20;
                                    map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                                }
                            }
                        });

                        // Center map on the found plot
                        const isMobile = window.innerWidth <= 768;
                        const zoomLevel = isMobile ? 19 : 20;
                        map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                    } else {
                        showNotification('No matching plot or deceased found. Please try again.', 'error');
                    }
                })
                .catch((error) => {
                    // If error is 'FOUND', it means we successfully found a plot, so ignore it
                    if (error === 'FOUND') {
                        return;
                    }
                    console.error('Search error details:', {
                        error: error,
                        message: error.message,
                        stack: error.stack
                    });
                    showNotification('Error searching. Please check the console for details or contact administrator.', 'error');
                });
        }
        
        // Auto-refresh plot data when page regains focus (useful if plots were added in another tab)
        let lastRefreshTime = Date.now();
        const REFRESH_COOLDOWN = 5000; // Don't refresh more than once per 5 seconds
        
        window.addEventListener('focus', function() {
            const now = Date.now();
            // Only refresh if it's been at least 5 seconds since last refresh
            if (now - lastRefreshTime > REFRESH_COOLDOWN) {
                refreshPlotData(false); // Don't fit bounds on auto-refresh
                lastRefreshTime = now;
            }
        });
        
        // Also refresh when page becomes visible (handles tab switching)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                const now = Date.now();
                if (now - lastRefreshTime > REFRESH_COOLDOWN) {
                    refreshPlotData(false);
                    lastRefreshTime = now;
                }
            }
        });
        
        // Note: Method overrides removed to restore normal map functionality
    </script>
</body>
</html> 