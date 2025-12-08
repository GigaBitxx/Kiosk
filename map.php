<?php
require_once 'config/database.php';

// Get all plots with their information for the kiosk map.
// Keep section/plot grouping consistent with the staff maps page so
// that filtering, section names, and wayfinding behave the same.
$query = "SELECT 
            p.*,
            s.section_code,
            s.section_name,
            s.has_multi_level,
            s.max_levels AS section_max_levels,
            dr.full_name,
            dr.date_of_birth,
            dr.date_of_death,
            dr.burial_date
          FROM plots p 
          LEFT JOIN sections s ON p.section_id = s.section_id
          LEFT JOIN deceased_records dr ON p.plot_id = dr.plot_id
          WHERE p.latitude IS NOT NULL 
            AND p.longitude IS NOT NULL
            AND p.latitude != 0 AND p.longitude != 0
          ORDER BY COALESCE(s.section_code, p.section), 
                   p.row_number, 
                   LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 1),
                   CAST(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 2) AS UNSIGNED),
                   p.level_number";
$result = mysqli_query($conn, $query);

// Group plots by section, mirroring the staff maps logic:
// - Skip TES sections
// - Skip mispositioned sections (AP, BLK 1–4)
// - Ensure each plot_id only produces a single marker
$sections = array();

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Resolve section name/code from either sections table or legacy plots.section
        $sectionName = !empty($row['section_name']) ? $row['section_name'] : '';
        $sectionCode = !empty($row['section_code']) ? $row['section_code'] : (!empty($row['section']) ? $row['section'] : '');

        // Skip TES sections
        if (stripos($sectionName, 'TES') !== false || stripos($sectionCode, 'TES') !== false) {
            continue;
        }

        // Skip mispositioned sections: AP, BLK 1–4, BLK1–BLK4
        $upperName = strtoupper(trim($sectionName));
        $upperCode = strtoupper(trim($sectionCode));
        if ($upperName === 'AP' || $upperCode === 'AP' ||
            preg_match('/^BLK\s*[1-4]$/i', $upperName) || preg_match('/^BLK\s*[1-4]$/i', $upperCode)) {
            continue;
        }

        // Use a safe section key even if section_name is missing
        $sectionKey = !empty($row['section_name']) ? $row['section_name'] : (!empty($row['section']) ? $row['section'] : 'Unknown Section');

        if (!isset($sections[$sectionKey])) {
            $sections[$sectionKey] = array(
                'section_id'         => $row['section_id'] ?? null,
                'section_code'       => $row['section_code'] ?? ($row['section'] ?? null),
                'has_multi_level'    => $row['has_multi_level'] ?? 0,
                'section_max_levels' => $row['section_max_levels'] ?? ($row['max_levels'] ?? 1),
                'plots'              => array(),
                // Internal helper structure to prevent duplicate markers for plots
                'plots_by_id'        => array()
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
                    'full_name'     => $row['full_name'],
                    'date_of_birth' => $row['date_of_birth'] ?? null,
                    'date_of_death' => $row['date_of_death'] ?? null,
                    'burial_date'   => $row['burial_date'] ?? null
                );
            }

            $sections[$sectionKey]['plots_by_id'][$plotId] = $plotData;
        } else {
            // Additional deceased for the same plot
            if (!empty($row['full_name'])) {
                $sections[$sectionKey]['plots_by_id'][$plotId]['deceased'][] = array(
                    'full_name'     => $row['full_name'],
                    'date_of_birth' => $row['date_of_birth'] ?? null,
                    'date_of_death' => $row['date_of_death'] ?? null,
                    'burial_date'   => $row['burial_date'] ?? null
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
    error_log('Error fetching plots for kiosk map: ' . mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            background: #f5f5f5;
        }
        #map {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }
        
        /* Ensure map is responsive and doesn't cause horizontal scroll */
        @media (max-width: 768px) {
            #map {
                width: 100%;
                height: 100%;
            }
        }
        
        /* Prevent horizontal scroll */
        html, body {
            overflow-x: hidden;
            max-width: 100%;
        }

        .back-to-home-btn {
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
        .back-to-home-btn:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .back-to-home-btn .arrow {
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
            justify-content: center;
        }
        
        /* Ensure controls don't overlap with back button (left) and legend (right) */
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
            flex: 1 1 auto;
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
            min-width: 110px;
            flex-shrink: 0;
        }

        .control-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
            flex-shrink: 0;
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
            }
            .search-container {
                width: 100%;
                min-width: unset;
                max-width: 100%;
            }
            .control-buttons {
                width: 100%;
                justify-content: space-between;
            }
            .legend {
                position: static;
                margin: 20px auto;
                max-width: 100%;
                width: calc(100% - 32px);
                max-height: none;
            }
        }

        .legend {
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

        .legend:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15), 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .legend-header {
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

        .legend-header:hover {
            background-color: #1f3659;
            border-color: #1f3659;
        }

        .legend-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .legend-header i {
            font-size: 1.1rem;
        }

        .legend-toggle {
            font-size: 0.9rem;
            opacity: 0.9;
            transition: transform 0.3s ease;
        }

        .legend.collapsed .legend-toggle {
            transform: rotate(-90deg);
        }

        .legend.collapsed .legend-content {
            display: none;
        }

        .legend-content {
            padding: 16px 20px;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
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
        
        /* Responsive zoom controls for mobile - keep at center left */
        @media (max-width: 768px) {
            .leaflet-control-zoom {
                left: 10px !important;
                top: 50% !important; /* Keep at center left */
                bottom: auto !important;
                transform: translateY(-50%) !important; /* Keep centered vertically */
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
                top: 50% !important; /* Keep at center left */
                bottom: auto !important;
                transform: translateY(-50%) !important; /* Keep centered vertically */
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
            width: 80px;
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
            z-index: 1; /* always visible above mini map */
        }

        .map-type-btn.active {
            border-color: #2b4c7e;
            box-shadow: 0 0 0 2px rgba(43, 76, 126, 0.9), 0 6px 16px rgba(15, 23, 42, 0.7);
        }

        @media (max-width: 768px) {
            .map-type-toggle {
                bottom: 90px;
                left: 10px;
            }
            .map-type-btn {
                width: 90px;
                height: 90px;
            }
        }

        .legend-section {
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(12, 13, 14, 0.8);
        }

        .legend-section:last-of-type {
            border-bottom: none;
            padding-bottom: 0;
        }

        .facilities-section {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .legend-group {
            margin-bottom: 20px;
        }

        .legend-group:last-child {
            margin-bottom: 0;
        }

        .legend-group-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .legend-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border-radius: 10px;
            transition: all 0.2s ease;
            cursor: pointer;
            background: transparent;
        }

        .legend-item:hover {
            background: rgba(43, 76, 126, 0.06);
            transform: translateX(4px);
        }

        .legend-color {
            width: 24px;
            height: 24px;
            margin-right: 12px;
            border: 2px solid #fff;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }

        .legend-item:hover .legend-color {
            transform: scale(1.15);
        }

        .legend-color.square {
            border-radius: 6px;
        }

        .legend-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #1e293b;
            flex: 1;
        }

        .legend-item:hover .legend-label {
            color: #2b4c7e;
            font-weight: 600;
        }

        /* Leaflet popup styling (small popup anchored to marker) */
        .custom-popup {
            width: 240px;
            max-width: 240px;
        }

        .custom-popup .leaflet-popup-content-wrapper {
            width: 100%;
        }

        .custom-popup .leaflet-popup-content {
            margin: 0;
        }

        .custom-popup .popup-content {
            padding: 10px;
        }

        .custom-popup .deceased-info {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        /* Compact arrow-style popup used when starting directions */
        .directions-popup .leaflet-popup-content-wrapper {
            border-radius: 999px;
            padding: 6px 8px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.35);
        }

        .directions-popup .leaflet-popup-content {
            margin: 0;
        }

        .directions-popup-inner {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .directions-popup-inner i {
            font-size: 22px;
            color: #2563eb;
        }

        /* Hide "Show Directions" button in the small Leaflet popup on the map.
           The same button remains visible inside the side suggestion panel. */
        .custom-popup .wayfinding-btn {
            display: none !important;
        }

        /* Side suggestion panel for search results */
        .search-suggestion-panel {
            position: fixed;
            top: 80px;
            left: 20px;
            width: 320px;
            max-height: calc(100vh - 120px);
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.25);
            padding: 16px 18px;
            z-index: 1200;
            display: none;
            overflow-y: auto;
            font-family: var(--font-primary, 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif);
        }

        .search-suggestion-panel.active {
            display: block;
        }

        .search-suggestion-panel.minimized {
            max-height: 56px;
            overflow: hidden;
            cursor: pointer;
        }

        .search-suggestion-panel.minimized #searchSuggestionList {
            display: none;
        }

        .search-suggestion-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .search-suggestion-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f172a;
        }

        .search-suggestion-close {
            background: transparent;
            border: none;
            font-size: 1.2rem;
            line-height: 1;
            cursor: pointer;
            color: #64748b;
        }

        .search-suggestion-close:hover {
            color: #0f172a;
        }

        @media (max-width: 768px) {
            .search-suggestion-panel {
                top: auto;
                bottom: 16px;
                left: 16px;
                right: 16px;
                width: auto;
                max-height: 45vh;
            }
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
        }
        .section-name {
            background: rgba(255, 255, 255, 0.5);
            padding: 2px 6px;
            border-radius: 4px;
            border: 2px solid rgba(51, 51, 51, 0.3);
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            pointer-events: none;
            max-width: 110px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .section-name.small {
            font-size: 10px;
            max-width: 80px;
            padding: 1px 4px;
        }

        /* Add new styles for popup / detail content */
        .plot-popup {
            padding: 8px 10px;
            font-family: var(--font-primary, 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif);
            font-size: 13px;
        }

        .plot-popup .deceased-block {
            margin-bottom: 4px;
        }

        .plot-popup .deceased-name {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 2px;
        }

        .plot-popup .life-dates {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .plot-popup .plot-meta {
            margin-top: 4px;
            border-top: 1px solid #eee;
            padding-top: 4px;
            font-size: 0.78rem;
        }

        .plot-popup .plot-meta p {
            margin-bottom: 2px;
        }

        .plot-popup .plot-code {
            margin-top: 4px;
            font-size: 0.78rem;
            color: #6b7280;
        }

        .plot-popup p {
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #555;
        }
        
        .plot-popup .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
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
            font-size: 0.78rem;
            padding: 4px 10px;
            margin-top: 6px;
            transition: all 0.2s;
        }

        .plot-popup .btn-primary:hover {
            background-color: #1f3659;
            border-color: #1f3659;
        }

        .facility-marker div {
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            font-size: 0.85rem;
        }

        .landmark-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .landmark-info {
            margin-bottom: 12px;
        }

        .landmark-info p {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
        }

        .landmark-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .landmark-filter-btn {
            flex: 1 1 auto;
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.85rem;
            background: rgba(248, 250, 252, 0.8);
            color:rgb(1, 3, 5);
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .landmark-filter-btn:hover {
            background: rgba(226, 232, 240, 0.8);
            border-color: rgba(1, 7, 15, 0.6);
        }

        .landmark-filter-btn.active {
            background: #2b4c7e;
            border-color: #2b4c7e;
            color: #fff;
            box-shadow: 0 4px 12px rgba(43, 76, 126, 0.3);
        }

        .landmark-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .landmark-item {
            border: none;
            background: rgba(248, 250, 252, 0.8);
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.2s ease;
            cursor: pointer;
            width: 100%;
            text-align: left;
        }

        .landmark-item:hover {
            background: rgba(241, 245, 249, 0.9);
            border-color: rgba(148, 163, 184, 0.4);
            transform: translateX(2px);
        }

        .landmark-item.active {
            background: rgba(43, 76, 126, 0.1);
            border-color: rgba(43, 76, 126, 0.3);
        }

        .landmark-item .info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .landmark-item .info span:first-child {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
        }

        .landmark-item .info span:last-child {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: capitalize;
        }

        .landmark-item .badge {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .badge-office {
            background: rgba(29, 78, 216, 0.2);
            color: #93c5fd;
        }

        .badge-parking {
            background: rgba(107, 114, 128, 0.2);
            color: #d1d5db;
        }

        .badge-landmark {
            background: rgba(139, 92, 246, 0.2);
            color: #e9d5ff;
        }

        @media (max-width: 768px) {
            .legend {
                position: static;
                margin: 20px auto;
                max-width: 100%;
                width: calc(100% - 32px);
                max-height: none;
            }
            .legend-content {
                padding: 12px 16px;
                max-height: none;
            }
            .legend-section {
                padding-bottom: 16px;
                margin-bottom: 16px;
            }
            .legend-group {
                margin-bottom: 12px;
            }
            .facilities-section {
                gap: 10px;
            }
            
            /* Improve touch zoom on mobile */
            .leaflet-container {
                touch-action: pan-x pan-y pinch-zoom;
                -webkit-touch-callout: none;
                -webkit-user-select: none;
            }
            
            /* Prevent accidental zoom on double-tap for buttons */
            .btn, .back-to-home-btn, .map-type-btn {
                touch-action: manipulation;
            }
            
            /* Make Leaflet popups responsive */
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
        }

        @media (max-width: 480px) {
            .legend {
                min-width: unset;
                width: calc(100% - 24px);
            }
            .legend-header {
                padding: 12px 16px;
                font-size: 0.9rem;
            }
            .legend-content {
                padding: 12px;
            }
            .legend-item {
                padding: 8px 10px;
            }
            .legend-color {
                width: 20px;
                height: 20px;
                margin-right: 10px;
            }
            .legend-label {
                font-size: 0.85rem;
            }
        }

        /* Fullscreen mode: hide UI overlays for a cleaner kiosk map */
        body.map-fullscreen-active .controls,
        body.map-fullscreen-active .legend,
        body.map-fullscreen-active .back-to-home-btn {
            display: none;
        }

        /* Fullscreen exit overlay (top-center) */
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
            max-width: 400px;
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

        /* Centered search result details card (for kiosk search) */
        .search-result-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.55);
            z-index: 3000;
        }

        .search-result-modal.active {
            display: flex;
        }

        .search-result-card {
            position: relative;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.35);
            max-width: 360px;
            width: calc(100% - 40px);
            padding: 18px 20px 20px;
        }

        .search-result-close {
            position: absolute;
            top: 8px;
            right: 10px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
        }

        /* Wayfinding styles */
        .wayfinding-start,
        .wayfinding-end {
            background: transparent !important;
            border: none !important;
        }

        .wayfinding-start div,
        .wayfinding-end div {
            pointer-events: none;
        }

        /* Pulsating destination marker */
        .destination-marker {
            animation: pulse 2s infinite;
            z-index: 1000;
        }

        /* Static kiosk "You are here" marker (no pulse) */
        .kiosk-marker {
            z-index: 1000;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.3);
                opacity: 0.7;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

    </style>
</head>
<body>
    <div id="map"></div>

    <a href="main.php" class="back-to-home-btn">
        <i class="bi bi-arrow-left"></i> Home
    </a>

    <div class="controls">
        <div class="search-container">
            <input type="text" id="searchDeceased" class="form-control" placeholder="Search Plot (e.g., ARIES-E-1) or Deceased Name" onkeypress="if(event.key === 'Enter') { event.preventDefault(); searchDeceased(); }">
            <button type="button" class="btn btn-primary" onclick="searchDeceased()">Search</button>
        </div>
        <div class="control-buttons">
            <!-- Refresh / reset view -->
            <button class="btn btn-primary" type="button" onclick="resetZoom()">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            <!-- Fullscreen toggle -->
            <button class="btn btn-secondary" type="button" onclick="toggleFullscreen()">
                <i class="bi bi-fullscreen" id="fullscreenIcon"></i>
            </button>
        </div>
    </div>

    <!-- Fullscreen exit overlay (top-center) -->
    <div class="fullscreen-exit-overlay">
        <button type="button" class="fullscreen-exit-btn" onclick="toggleFullscreen()">
            <i class="bi bi-arrow-down" id="fullscreenExitIcon"></i>
        </button>
    </div>

    <div class="legend collapsed" id="legend">
        <div class="legend-header" onclick="toggleLegend()">
            <div class="legend-header-left">
                <i class="bi bi-map"></i>
                <span>Map Guide</span>
            </div>
            <i class="bi bi-chevron-down legend-toggle"></i>
        </div>
        <div class="legend-content">
            <!-- Plot Type Section -->
            <div class="facilities-section" style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid rgba(12, 13, 14, 0.8);">
                <div class="landmark-info">
                    <div class="landmark-section-title">Plot Type</div>
                    <p>Filter plots by type.</p>
                </div>
                <div class="landmark-filters">
                    <button class="landmark-filter-btn active" data-plot-type="all">All Types</button>
                    <button class="landmark-filter-btn" data-plot-type="lawn">Lawn Lot (Ground Level)</button>
                    <button class="landmark-filter-btn" data-plot-type="niche">Apartment-Type Niches</button>
                </div>
            </div>

            <!-- Plot Sections Section -->
            <div class="facilities-section">
                <div class="landmark-info">
                    <div class="landmark-section-title">Plot Sections</div>
                    <p>Tap a section to zoom the map to it.</p>
                </div>
                <div class="landmark-list" id="sectionList"></div>
            </div>
        </div>
    </div>

    <!-- Centered details card used for search results -->
    <div id="searchResultModal" class="search-result-modal">
        <div class="search-result-card">
            <button type="button" id="searchResultCloseBtn" class="search-result-close">&times;</button>
            <div id="searchResultContent"></div>
        </div>
    </div>

    <!-- Side suggestion panel used for search results (plot / deceased) -->
    <div id="searchSuggestionPanel" class="search-suggestion-panel">
        <div class="search-suggestion-header">
            <div id="searchSuggestionTitle" class="search-suggestion-title">Search Result</div>
            <button type="button" id="searchSuggestionClose" class="search-suggestion-close">&times;</button>
        </div>
        <div id="searchSuggestionList"></div>
    </div>

    <div class="map-type-toggle">
        <!-- Single toggle tile; live mini-map preview + label -->
        <button class="map-type-btn" data-map-type="satellite">
            <div id="mapTypeMiniMap"></div>
            <span class="map-type-label">Satellite</span>
        </button>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/offline-map.js"></script>
    <script>
        // Keep zoom within a safe but detailed range where tiles are consistently available
        const MIN_TILE_ZOOM = 15; // Lowered to allow more zoom out for better overview
        const MAX_TILE_ZOOM = 22;
        const INITIAL_ZOOM = 19; // Increased zoom level for closer initial view
        
        // Helper function to get responsive zoom level
        function getResponsiveZoom(desktopZoom, mobileZoom = null) {
            const isMobile = window.innerWidth <= 768;
            return isMobile ? (mobileZoom || desktopZoom - 1) : desktopZoom;
        }

        // Initialize the map with offline support (center/zoom taken from Google Maps share link)
        // Adjust initial zoom for mobile devices
        const isMobileDevice = window.innerWidth <= 768;
        const adjustedInitialZoom = getResponsiveZoom(INITIAL_ZOOM, 18);
        const map = offlineMap.initializeMap('map', [14.2652649, 120.8651463], adjustedInitialZoom);
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

        // Define base layers
        const defaultLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            minZoom: MIN_TILE_ZOOM,   // Allow zooming out to minimum
            maxZoom: MAX_TILE_ZOOM,   // allow smooth zoom up to 22
            maxNativeZoom: 19,        // OSM native max, tiles get smoothly scaled above this
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
                minZoom: MIN_TILE_ZOOM,
                maxZoom: MAX_TILE_ZOOM
            });
            miniSatelliteLayer = offlineMap.getSatelliteLayer();
            miniLabelsLayer = offlineMap.getLabelsOverlay();

            // Initial state: main map is DEFAULT, so mini-map shows SATELLITE (opposite preview)
            miniSatelliteLayer.addTo(miniMap);
            miniLabelsLayer.addTo(miniMap);
        }

        // Plot data from PHP (made let so it can be updated)
        let sections = <?php echo json_encode($sections); ?>;
        let markers = [];
        // Quick lookup from plot_id -> Leaflet marker (for opening popup/wayfinding)
        let plotMarkersById = {};
        // Optional lookup from plot_id -> human-friendly label (e.g. APOLLO-A10)
        let plotLabelsById = {};
        // Track all individual plot markers so we can hide/show them during wayfinding
        let allPlotMarkers = [];
        let hiddenPlotMarkers = [];

        // Elements for centered search-result card
        const searchResultModal = document.getElementById('searchResultModal');
        const searchResultContent = document.getElementById('searchResultContent');
        const searchResultCloseBtn = document.getElementById('searchResultCloseBtn');

        // Elements for side suggestion panel
        const searchSuggestionPanel = document.getElementById('searchSuggestionPanel');
        const searchSuggestionTitle = document.getElementById('searchSuggestionTitle');
        const searchSuggestionList = document.getElementById('searchSuggestionList');
        const searchSuggestionClose = document.getElementById('searchSuggestionClose');

        // Simple device detection – used to enhance UX on phones/tablets
        const isTouchDevice = (
            'ontouchstart' in window ||
            (navigator.maxTouchPoints && navigator.maxTouchPoints > 0) ||
            (navigator.msMaxTouchPoints && navigator.msMaxTouchPoints > 0)
        );
        const isSmallScreen = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        const isMobileLike = isTouchDevice || isSmallScreen;


        // Helper: when showing the side suggestion panel on phones/tablets,
        // treat it like a bottom sheet and scroll it into view.
        function focusSuggestionPanelForMobile() {
            if (!isMobileLike || !searchSuggestionPanel) return;
            searchSuggestionPanel.classList.remove('minimized');
            setTimeout(() => {
                try {
                    searchSuggestionPanel.scrollIntoView({ behavior: 'smooth', block: 'end' });
                } catch (e) {
                    // scrollIntoView not supported – safe to ignore
                }
            }, 50);
        }
        let cemeteryBoundary = null; // Cemetery boundary polygon
        
        // Wayfinding variables
        let wayfindingRoute = null;
        let wayfindingStartMarker = null;
        let wayfindingEndMarker = null;
        let wayfindingRoutingControl = null; // Not used anymore (no external routing)
        let destinationPulseMarker = null; // (no longer used visually)
        let naturalLanguageInstructions = []; // Store step-by-step directions
        const kioskLocation = [14.264531, 120.866048]; // Kiosk location (starting point for directions)
        const adminOfficeLocation = kioskLocation; // Keep for compatibility with existing facilities data

        // Persistent "You are here" marker at the kiosk.
        // Uses a static style (no pulse) so only the destination marker pulses.
        const kioskIcon = L.divIcon({
            className: 'kiosk-marker',
            html: `<div style="background: #dc3545; width: 24px; height: 24px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.3);"></div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });

        const kioskMarker = L.marker(kioskLocation, { icon: kioskIcon }).addTo(map);
        kioskMarker.bindTooltip('You are here', {
            permanent: true,
            direction: 'top',
            offset: [0, -18],
            className: 'kiosk-you-are-here-label'
        });

        // Plot marker colors
        const plotColors = {
            available: '#4caf50',
            reserved: '#ff9800',
            occupied: '#f44336'
        };

        // Store section label markers for zoom updates
        let sectionLabelMarkers = [];
        // Store all plot markers so we can resize them on zoom
        // (array is also reset inside addPlotMarkers)
        
        // Helper: compute appropriate plot marker radius for a given zoom level
        function getPlotMarkerRadiusForZoom(zoom) {
            // Smaller radius when zoomed out, slightly larger when zoomed in
            // Clamp to a reasonable range so markers never disappear or dominate the map
            return Math.max(2, Math.min(8, zoom - 14));
        }
        
        // Function to update section labels based on zoom
        function updateSectionLabels() {
            const currentZoom = map.getZoom();
            const isNearZoom = currentZoom >= 19; // Show section names only when zoomed in closely
            
            sectionLabelMarkers.forEach(markerData => {
                const { marker, sectionName } = markerData;
                
                let newIcon;
                if (isNearZoom) {
                    // Show full label with border when zoomed in / near
                    newIcon = L.divIcon({
                        className: 'section-label',
                        html: `<div class="section-name">${sectionName}</div>`,
                        iconSize: [100, 40],
                        iconAnchor: [50, 20]
                    });
                } else {
                    // Hide section icon entirely when zoomed out
                    newIcon = L.divIcon({
                        className: 'section-label',
                        html: '',
                        iconSize: [0, 0],
                        iconAnchor: [0, 0]
                    });
                }
                marker.setIcon(newIcon);
            });
        }

        // Function to update plot marker sizes based on zoom
        function updatePlotMarkerSizes() {
            const zoom = map.getZoom();
            const radius = getPlotMarkerRadiusForZoom(zoom);
            allPlotMarkers.forEach(marker => {
                if (typeof marker.setRadius === 'function') {
                    marker.setRadius(radius);
                }
            });
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

        // Add section labels and plot markers to the map
        function addPlotMarkers(filteredSections = sections, shouldFit = false) {
            // Clear existing markers
            markers.forEach(marker => map.removeLayer(marker));
            markers = [];
            sectionLabelMarkers = []; // Clear section label markers array
            // Clear plot marker index
            Object.keys(plotMarkersById).forEach(id => delete plotMarkersById[id]);
            // Reset plot marker tracking used for wayfinding focus
            allPlotMarkers = [];
            hiddenPlotMarkers = [];

            // Add new markers
            const bounds = L.latLngBounds();

            Object.entries(filteredSections).forEach(([sectionName, sectionData]) => {
                const plots = sectionData.plots;
                if (plots.length === 0) return;

                // Calculate center point of the section
                const sectionLats = plots.map(p => parseFloat(p.latitude));
                const sectionLngs = plots.map(p => parseFloat(p.longitude));
                const centerLat = sectionLats.reduce((a, b) => a + b) / sectionLats.length;
                const centerLng = sectionLngs.reduce((a, b) => a + b) / sectionLngs.length;

                // Add section label: hide when zoomed out, show section name when zoom level is near
                const currentZoom = map.getZoom();
                const isNearZoom = currentZoom >= 19;
                
                let sectionLabel;
                if (isNearZoom) {
                    // Show full label with border when zoomed in / near
                    sectionLabel = L.divIcon({
                        className: 'section-label',
                        html: `<div class="section-name">${sectionName}</div>`,
                        iconSize: [100, 40],
                        iconAnchor: [50, 20]
                    });
                } else {
                    // Hide section icon entirely when zoomed out
                    sectionLabel = L.divIcon({
                        className: 'section-label',
                        html: '',
                        iconSize: [0, 0],
                        iconAnchor: [0, 0]
                    });
                }

                const labelMarker = L.marker([centerLat, centerLng], {
                    icon: sectionLabel,
                    interactive: false,
                    keyboard: false
                }).addTo(map);
                markers.push(labelMarker);

                // Store marker data for zoom updates
                sectionLabelMarkers.push({
                    marker: labelMarker,
                    sectionName: sectionName,
                    centerLat: centerLat,
                    centerLng: centerLng
                });

                // Add plot markers for this section
                plots.forEach(plot => {
                    const lat = parseFloat(plot.latitude);
                    const lng = parseFloat(plot.longitude);
                    if (isNaN(lat) || isNaN(lng)) {
                        return; // Skip plots with invalid coordinates
                    }

                    const status = plot.status && plotColors[plot.status] ? plot.status : 'available';

                    // Build a human-friendly plot label, e.g. ARI-A10
                    const displayLabel = (() => {
                        const rowNum = parseInt(plot.row_number || 0, 10);
                        const rowLetter = rowNum > 0 ? String.fromCharCode(64 + rowNum) : '';
                        const base = `${rowLetter}${plot.plot_number ?? ''}`;
                        return plot.section_code ? `${plot.section_code}-${base}` : base || (plot.section_name || 'Plot');
                    })();

                    // Build deceased info - handle multiple deceased per plot
                    let deceasedHtml = '';
                    if (Array.isArray(plot.deceased) && plot.deceased.length > 0) {
                        // Multiple deceased records
                        const multiple = plot.deceased.length > 1;
                        const deceasedList = plot.deceased.map((d, index) => {
                            const name = d.full_name || 'Unknown';
                            const dob = d.date_of_birth || null;
                            const dod = d.date_of_death || null;
                            const lifeDates = formatLifeDatesLong(dob, dod);
                            
                            return `
                                <div style="margin-bottom: ${index < plot.deceased.length - 1 ? '8px' : '0'}; padding-bottom: ${index < plot.deceased.length - 1 ? '8px' : '0'}; border-bottom: ${index < plot.deceased.length - 1 ? '1px solid #eee' : 'none'};">
                                    <div class="deceased-name" style="font-weight: 600; color: #333; margin-bottom: 2px;">${multiple ? `${index + 1}. ${name}` : name}</div>
                                    ${lifeDates ? `<div class="life-dates" style="font-size: 0.85rem; color: #6b7280; margin-bottom: 2px;">${lifeDates}</div>` : ''}
                                    ${d.burial_date ? `<div style="font-size: 0.8rem; color: #64748b;">Burial: ${d.burial_date}</div>` : ''}
                                </div>
                            `;
                        }).join('');
                        
                        deceasedHtml = `
                            <div class="deceased-block">
                                <div style="font-weight: 600; color: #64748b; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                                    ${multiple ? 'Deceased (Multiple)' : 'Deceased'}
                                </div>
                                ${deceasedList}
                            </div>
                        `;
                    } else {
                        // Single deceased or no deceased (backward compatibility)
                        const deceasedName = (() => {
                            if (plot.full_name) {
                                return plot.full_name;
                            }
                            const first = plot.first_name || '';
                            const last = plot.last_name || '';
                            const full = (first + ' ' + last).trim();
                            return full !== '' ? full : null;
                        })();

                        const dob = plot.date_of_birth || null;
                        const dod = plot.date_of_death || null;
                        const burialDate = plot.date_of_burial || null;

                        const lifeDates = formatLifeDatesLong(dob, dod);

                        deceasedHtml = `
                            <div class="deceased-block">
                                ${deceasedName ? `
                                    <div class="deceased-name">${deceasedName}</div>
                                    ${lifeDates ? `<div class="life-dates">${lifeDates}</div>` : ''}
                                ` : `
                                    <div class="deceased-name">No deceased record</div>
                                `}
                            </div>
                        `;
                    }

                    const popupHtml = `
                        <div class="plot-popup">
                            ${deceasedHtml}
                            <div class="plot-meta">
                                <p>
                                    <strong>Section:</strong>
                                    ${plot.section_name ? plot.section_name : (plot.section_code || '—')}
                                </p>
                                <p>
                                    <strong>Row:</strong> ${plot.row_number ?? '—'}&nbsp;&nbsp;
                                    <strong>Plot #:</strong> ${plot.plot_number ?? '—'}
                                </p>
                                <button 
                                    type="button" 
                                    class="btn btn-primary btn-sm wayfinding-btn" 
                                    onclick="startWayfinding(${lat}, ${lng}, '${displayLabel}', ${plot.plot_id || 'null'})">
                                    Show Directions
                                </button>
                            </div>
                        </div>
                    `;

                    const marker = L.circleMarker([lat, lng], {
                        // Visible, colored markers whose size adapts to zoom level
                        radius: getPlotMarkerRadiusForZoom(map.getZoom()),
                        fillColor: plotColors[status],
                        color: '#ffffff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.9,
                        // Make plot markers non-interactive so they cannot be clicked directly
                        interactive: false,
                        keyboard: false,
                        bubblingMouseEvents: false
                    });

                    // Keep popup binding for search functionality, but marker is invisible and non-interactive
                    marker.bindPopup(popupHtml, { className: 'custom-popup' });

                    // Index by plot_id so we can open this popup when searching
                    if (plot.plot_id) {
                        plotMarkersById[plot.plot_id] = marker;
                        plotLabelsById[plot.plot_id] = displayLabel;
                        // Store plot id directly on the marker for quick checks when hiding/showing
                        marker._plotId = plot.plot_id;
                    } else {
                        marker._plotId = null;
                    }

                    marker.addTo(map);
                    markers.push(marker);
                    allPlotMarkers.push(marker);
                    bounds.extend([lat, lng]);
                });
            });

            if (shouldFit && Object.keys(filteredSections).length > 0) {
                const isMobile = window.innerWidth <= 768;
                const padding = isMobile ? [80, 30] : [50, 50];
                map.fitBounds(bounds, { padding: padding });
            }
        }

        // Filter plots based on section, status, and plot type
        function filterPlots() {
            const sectionFilter = document.getElementById('sectionFilter')?.value || 'all';
            const statusFilter = document.getElementById('statusFilter')?.value || 'all';

            // First apply plot type filter
            let filteredSections = filterPlotsByType(sections, currentPlotTypeFilter);

            // Then apply section and status filters
            const finalFilteredSections = {};
            Object.entries(filteredSections).forEach(([sectionName, sectionData]) => {
                const filteredPlots = sectionData.plots.filter(plot => {
                    const matchesSection = sectionFilter === 'all' || !sectionFilter || plot.section_code === sectionFilter;
                    const matchesStatus = statusFilter === 'all' || !statusFilter || plot.status === statusFilter;
                    return matchesSection && matchesStatus;
                });

                if (filteredPlots.length > 0) {
                    finalFilteredSections[sectionName] = {
                        section_code: sectionData.section_code,
                        plots: filteredPlots
                    };
                }
            });

            addPlotMarkers(finalFilteredSections, true);
        }

        // Add styles for section labels (duplicated here for safety when JS runs before CSS)
        const mapStyles = document.createElement('style');
        mapStyles.textContent = `
            .section-label {
                background: none;
                border: none;
            }
            .section-name {
                background: rgba(255, 255, 255, 0.5);
                padding: 2px 6px;
                border-radius: 4px;
                border: 2px solid rgba(51, 51, 51, 0.3);
                font-weight: bold;
                font-size: 12px;
                text-align: center;
                white-space: nowrap;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                pointer-events: none;
                max-width: 110px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .section-name.small {
                font-size: 10px;
                max-width: 80px;
                padding: 1px 4px;
            }
        `;
        document.head.appendChild(mapStyles);

        // Shrink section labels when zoomed out
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

        map.on('zoomend', updateSectionLabelScale);
        updateSectionLabelScale();

        // Load saved cemetery boundary from localStorage
        function loadCemeteryBoundary() {
            const savedBoundary = localStorage.getItem('cemetery_boundary');
            if (savedBoundary) {
                try {
                    const coordinates = JSON.parse(savedBoundary);
                    drawCemeteryBoundary(coordinates);
                } catch (e) {
                    console.error('Error loading saved boundary:', e);
                }
            }
        }
        
        // Draw cemetery boundary from coordinates (display only, no editing)
        function drawCemeteryBoundary(coordinates) {
            // Remove existing boundary if any
            if (cemeteryBoundary) {
                map.removeLayer(cemeteryBoundary);
            }
            
            if (!coordinates || coordinates.length < 3) return;
            
            // Create and style the boundary polygon (non-interactive for kiosk)
            cemeteryBoundary = L.polygon(coordinates, {
                color: '#ffc107', // Yellow border
                weight: 2,
                opacity: 1,
                fillColor: '#90EE90', // Light green fill
                fillOpacity: 0.3,
                interactive: false // No interaction for kiosk interface
            }).addTo(map);
        }
        
        // Initialize boundary display (no drawing controls for kiosk interface)
        function initializeBoundaryDrawing() {
            // For kiosk interface, only load and display the boundary
            // Drawing/editing should be done in staff/admin interface
            // No draw controls needed here - just display the saved boundary
        }

        // Initial plot markers (keep default zoom)
        addPlotMarkers(sections, false);

        // If page is opened with ?plot=<id> from kiosk search, focus that plot
        // and optionally start directions automatically when &auto=1 is present.
        (function handleInitialPlotFromUrl() {
            try {
                const params = new URLSearchParams(window.location.search);
                const plotParam = params.get('plot');
                if (!plotParam) return;

                const plotId = parseInt(plotParam, 10);
                if (!plotId || !plotMarkersById[plotId]) return;

                const marker = plotMarkersById[plotId];
                const latLng = marker.getLatLng && marker.getLatLng();
                if (!latLng) return;

                const autoDirections =
                    params.get('auto') === '1' ||
                    params.get('directions') === '1';

                const destinationLabel =
                    plotLabelsById[plotId] ||
                    `Plot ${plotId}`;

                // Center map on destination plot
                const zoomLevel = getResponsiveZoom(20, 19);
                map.flyTo([latLng.lat, latLng.lng], zoomLevel, { duration: 0.8 });

                // After animation, show popup and (optionally) start wayfinding
                setTimeout(() => {
                    openPlotPopupLeaflet(plotId);
                    if (autoDirections) {
                        startWayfinding(latLng.lat, latLng.lng, destinationLabel, plotId);
                    }
                }, 900);
            } catch (e) {
                console.error('Error handling initial plot from URL:', e);
            }
        })();
        
        // Load saved cemetery boundary if exists
        setTimeout(() => {
            loadCemeteryBoundary();
            // Initialize drawing controls
            initializeBoundaryDrawing();
        }, 500);

        // Facilities (Offices, Parking, Landmarks)
        const facilityStyles = {
            office:  { bg: '#1d4ed8', emoji: '🏢' },
            parking: { bg: '#6b7280', emoji: '🅿️' },
            landmark:{ bg: '#8b5cf6', emoji: '📍' }
        };

        function createFacilityIcon(type, label, zoomLevel = null) {
            const style = facilityStyles[type] || facilityStyles.landmark;
            const currentZoom = zoomLevel !== null ? zoomLevel : map.getZoom();
            const isZoomedOut = currentZoom < 19;
            
            if (isZoomedOut) {
                // Show only emoji when zoomed out
                return L.divIcon({
                    className: 'facility-marker',
                    html: `
                        <div style="font-size:24px;text-align:center;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.3));">
                            ${style.emoji}
                        </div>
                    `,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });
            } else {
                // Show full label when zoomed in
            return L.divIcon({
                className: 'facility-marker',
                html: `
                    <div style="display:inline-flex;align-items:center;gap:6px;background:${style.bg};color:#fff;padding:6px 10px;border-radius:16px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);font-weight:600;">
                        <span>${style.emoji}</span>
                        <span>${label}</span>
                    </div>
                `,
                iconSize: [1, 1],
                iconAnchor: [20, 20]
            });
            }
        }

        const officeLayer = L.layerGroup().addTo(map);
        const parkingLayer = L.layerGroup().addTo(map);
        const landmarkLayer = L.layerGroup().addTo(map);

     
        // Facilities & landmark markers have been disabled (array left empty)
        // to remove landmark pins and entries from the map UI.
        const facilities = [];

        const facilityMarkers = {};
        let facilityMarkerObjects = []; // Store marker objects for zoom updates

        // Function to update facility markers based on zoom
        function updateFacilityMarkers() {
            const currentZoom = map.getZoom();
            facilities.forEach(f => {
                if (f.marker) {
                    const newIcon = createFacilityIcon(f.type, f.name, currentZoom);
                    f.marker.setIcon(newIcon);
                }
            });
        }

        facilities.forEach(f => {
            const icon = createFacilityIcon(f.type, f.name);
            const marker = L.marker([f.lat, f.lng], { icon }).bindPopup(`
                <strong>${f.name}</strong><br/>
                <span style="text-transform:capitalize;">${f.type}</span><br/>
                <small>${f.desc}</small>
            `);
            facilityMarkers[f.id] = marker;
            facilityMarkerObjects.push(marker);
            if (f.type === 'office') marker.addTo(officeLayer);
            else if (f.type === 'parking') marker.addTo(parkingLayer);
            else marker.addTo(landmarkLayer);
            f.marker = marker;
        });

        // Update facility markers and labels/sizes on zoom change
        map.on('zoomend', function() {
            updateFacilityMarkers();
            // Also update section labels and plot marker sizes
            updateSectionLabels();
            updatePlotMarkerSizes();
        });

        const sectionListEl = document.getElementById('sectionList');
        const plotTypeFilterBtns = document.querySelectorAll('.landmark-filter-btn[data-plot-type]');
        let currentPlotTypeFilter = 'all';

        // Render plot sections list
        function renderSectionList() {
            sectionListEl.innerHTML = '';
            // Apply plot type filter to sections list
            const filteredSections = filterPlotsByType(sections, currentPlotTypeFilter);
            const sectionNames = Object.keys(filteredSections).sort();
            
            sectionNames.forEach(sectionName => {
                const sectionData = filteredSections[sectionName];
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'landmark-item';
                item.dataset.section = sectionName;
                
                // Calculate center point of the section
                const plots = sectionData.plots;
                if (plots.length === 0) return;
                
                const sectionLats = plots.map(p => parseFloat(p.latitude));
                const sectionLngs = plots.map(p => parseFloat(p.longitude));
                const centerLat = sectionLats.reduce((a, b) => a + b) / sectionLats.length;
                const centerLng = sectionLngs.reduce((a, b) => a + b) / sectionLngs.length;
                
                item.innerHTML = `
                    <div class="info">
                        <span>${sectionName}</span>
                        <span>${plots.length} plot${plots.length !== 1 ? 's' : ''}</span>
                    </div>
                    <span class="badge" style="background: rgba(43, 76, 126, 0.2); color: #2b4c7e;">Section</span>
                `;
                
                item.addEventListener('click', () => {
                    // Zoom to section
                    const bounds = L.latLngBounds(
                        plots.map(p => [parseFloat(p.latitude), parseFloat(p.longitude)])
                    );
                    const isMobile = window.innerWidth <= 768;
                    const padding = isMobile ? [80, 30] : [50, 50];
                    map.flyToBounds(bounds, { padding: padding, duration: 0.8 });
                    
                    // Highlight clicked item
                    document.querySelectorAll('#sectionList .landmark-item').forEach(i => 
                        i.classList.remove('active')
                    );
                    item.classList.add('active');
                });
                
                sectionListEl.appendChild(item);
            });
            
            if (sectionNames.length === 0) {
                sectionListEl.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:0.9rem;padding:12px;">No sections available.</div>';
            }
        }

        // Initialize section list
        renderSectionList();

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

        // Plot type filter event handlers
        plotTypeFilterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                plotTypeFilterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentPlotTypeFilter = btn.dataset.plotType;
                // Apply plot type filter and refresh markers
                const filteredSections = filterPlotsByType(sections, currentPlotTypeFilter);
                addPlotMarkers(filteredSections, false);
                // Also update section list to show only filtered sections
                renderSectionList();
            });
        });

        // Add facility layers without UI control, show all by default
        officeLayer.addTo(map);
        parkingLayer.addTo(map);
        landmarkLayer.addTo(map);

        // Right-click helper to get coordinates (useful for defining cemetery boundary)
        map.on('contextmenu', function (e) {
            const lat = e.latlng.lat.toFixed(6);
            const lng = e.latlng.lng.toFixed(6);
            console.log(`Boundary coordinate: [${lat}, ${lng}],`);
            // Uncomment the line below if you want alerts instead of console logs
            // alert(`Right-click position:\nLat: ${lat}\nLng: ${lng}\n\nCheck console for formatted coordinate`);
        });

        function zoomIn() {
            map.zoomIn();
        }

        function zoomOut() {
            map.zoomOut();
        }

        // API base helper so deployments can point to another host or path
        const API_BASE = (window.KIOSK_API_BASE || '').replace(/\/?$/, '/');
        const apiUrl = (path) => {
            if (API_BASE === '/') return `/${path.replace(/^\/+/, '')}`;
            return `${API_BASE}${path.replace(/^\/+/, '')}`;
        };

        // Refresh plot data from server (silently, no notifications)
        function refreshPlotData(fitBounds = false) {
            fetch(apiUrl('api/get_kiosk_map_plots.php?t=' + Date.now()))
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.sections) {
                        sections = data.sections;
                        // Clear existing markers
                        markers.forEach(marker => map.removeLayer(marker));
                        markers = [];
                        allPlotMarkers.forEach(marker => map.removeLayer(marker));
                        allPlotMarkers = [];
                        // Clear lookup objects by reassigning to empty objects
                        plotMarkersById = {};
                        plotLabelsById = {};
                        
                        // Refresh markers with updated data
                        addPlotMarkers(sections, fitBounds);
                        
                        // Update section list in legend to reflect new data
                        renderSectionList();
                    } else {
                        throw new Error('Invalid response from server');
                    }
                })
                .catch(error => {
                    console.error('Error refreshing plot data:', error);
                });
        }

        function resetZoom() {
            // Clear search input and close any open popups
            const searchInput = document.getElementById('searchDeceased');
            if (searchInput) {
                searchInput.value = '';
            }
            map.closePopup();

            // Clear wayfinding when resetting zoom
            clearWayfinding();

            // Refresh plot data first, then reset view
            refreshPlotData(true);
            
            // Reset view to default center/zoom after refresh completes
            setTimeout(() => {
                const zoomLevel = getResponsiveZoom(19, 18);
                map.setView([14.2645191, 120.8654277], zoomLevel);
            }, 500);
        }

        // Show notification bubble (top-center)
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

        // Toggle legend collapse/expand
        function toggleLegend() {
            const legend = document.getElementById('legend');
            legend.classList.toggle('collapsed');
        }

        // Fullscreen toggle (hide overlays for a clean map)
        function toggleFullscreen() {
            const body = document.body;
            const icon = document.getElementById('fullscreenIcon');
            const exitIcon = document.getElementById('fullscreenExitIcon');
            
            // Change exit icon before toggling (for visual feedback)
            if (exitIcon && body.classList.contains('map-fullscreen-active')) {
                exitIcon.classList.remove('bi-arrow-down');
                exitIcon.classList.add('bi-arrow-up');
                // Reset icon after a brief delay
                setTimeout(() => {
                    if (exitIcon) {
                        exitIcon.classList.remove('bi-arrow-up');
                        exitIcon.classList.add('bi-arrow-down');
                    }
                }, 300);
            }
            
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

        // Clear previous wayfinding
        function clearWayfinding() {
            if (wayfindingRoute) {
                map.removeLayer(wayfindingRoute);
                wayfindingRoute = null;
            }
            if (wayfindingStartMarker) {
                map.removeLayer(wayfindingStartMarker);
                wayfindingStartMarker = null;
            }
            if (wayfindingEndMarker) {
                map.removeLayer(wayfindingEndMarker);
                wayfindingEndMarker = null;
            }
            if (destinationPulseMarker) {
                map.removeLayer(destinationPulseMarker);
                destinationPulseMarker = null;
            }
            if (wayfindingRoutingControl) {
                map.removeControl(wayfindingRoutingControl);
                wayfindingRoutingControl = null;
            }
            naturalLanguageInstructions = [];
            
            // Restore any plot markers that were hidden while showing directions
            if (hiddenPlotMarkers && hiddenPlotMarkers.length > 0) {
                hiddenPlotMarkers.forEach(marker => {
                    // Only re-add markers that are currently not on the map
                    if (!map.hasLayer(marker)) {
                        marker.addTo(map);
                    }
                });
                hiddenPlotMarkers = [];
            }
        }

        // Hide all plot markers except the specified destination plot while keeping its marker visible.
        // Also tolerate minor ID-type mismatches (string vs number) and fail gracefully if no match is found.
        function hideAllPlotsExcept(plotId) {
            if (!plotId || !allPlotMarkers || allPlotMarkers.length === 0) return;

            const targetId = String(plotId);

            // Clear any previous hidden state for safety
            hiddenPlotMarkers = [];

            let targetMarker = null;

            allPlotMarkers.forEach(marker => {
                const markerId = marker._plotId != null ? String(marker._plotId) : null;
                const isTarget = markerId !== null && markerId === targetId;

                if (isTarget) {
                    targetMarker = marker;
                    return;
                }

                if (map.hasLayer(marker)) {
                    map.removeLayer(marker);
                    hiddenPlotMarkers.push(marker);
                }
            });

            // If we somehow didn't find a matching target marker, restore everything so the user
            // never loses all plots due to an ID mismatch.
            if (!targetMarker) {
                hiddenPlotMarkers.forEach(marker => {
                    if (!map.hasLayer(marker)) {
                        marker.addTo(map);
                    }
                });
                hiddenPlotMarkers = [];
                return;
            }

            // Optionally emphasize the target marker slightly so it's easier to spot.
            if (typeof targetMarker.setRadius === 'function') {
                try {
                    const currentZoomRadius = getPlotMarkerRadiusForZoom(map.getZoom());
                    targetMarker.setRadius(currentZoomRadius + 2);
                    if (typeof targetMarker.setStyle === 'function') {
                        targetMarker.setStyle({
                            weight: 3,
                            color: '#000000'
                        });
                    }
                } catch (e) {
                    console.warn('Could not highlight target plot marker:', e);
                }
            }
        }
        
        // Generate natural language directions from waypoints
        function generateNaturalLanguageDirections(waypoints, destinationName) {
            const instructions = [];
            if (waypoints.length < 2) return instructions;

            instructions.push({
                step: 1,
                text: `Start from the kiosk location`,
                distance: 0
            });

            let stepNumber = 2;
            let cumulativeDistance = 0;

            for (let i = 1; i < waypoints.length; i++) {
                const prev = waypoints[i - 1];
                const curr = waypoints[i];
                const segmentDistance = distance(prev, curr);
                cumulativeDistance += segmentDistance;

                // Convert distance to meters (approximate)
                const distanceMeters = segmentDistance * 111000; // Rough conversion: 1 degree ≈ 111km
                
                // Determine direction based on coordinates
                const latDiff = curr[0] - prev[0];
                const lngDiff = curr[1] - prev[1];
                
                // Calculate bearing for more accurate direction
                const bearing = Math.atan2(lngDiff, latDiff) * 180 / Math.PI;
                
                let direction = '';
                if (bearing >= -22.5 && bearing < 22.5) direction = 'north';
                else if (bearing >= 22.5 && bearing < 67.5) direction = 'northeast';
                else if (bearing >= 67.5 && bearing < 112.5) direction = 'east';
                else if (bearing >= 112.5 && bearing < 157.5) direction = 'southeast';
                else if (bearing >= 157.5 || bearing < -157.5) direction = 'south';
                else if (bearing >= -157.5 && bearing < -112.5) direction = 'southwest';
                else if (bearing >= -112.5 && bearing < -67.5) direction = 'west';
                else if (bearing >= -67.5 && bearing < -22.5) direction = 'northwest';
                
                let instruction = '';
                const roundedDistance = Math.round(distanceMeters);
                
                if (i === waypoints.length - 1) {
                    // Last segment - arrival
                    if (roundedDistance < 5) {
                        instruction = `You have arrived at ${destinationName}`;
                    } else {
                        instruction = `Continue ${direction} for approximately ${roundedDistance} meters to reach ${destinationName}`;
                    }
                } else {
                    // Intermediate steps
                    if (roundedDistance < 10) {
                        instruction = `Continue ${direction}`;
                    } else {
                        instruction = `Walk ${direction} for approximately ${roundedDistance} meters`;
                    }
                }

                instructions.push({
                    step: stepNumber++,
                    text: instruction,
                    distance: roundedDistance
                });
            }

            // Add total distance summary
            const totalMeters = Math.round(cumulativeDistance * 111000);
            if (instructions.length > 1) {
                instructions.push({
                    step: stepNumber,
                    text: `Total distance: approximately ${totalMeters} meters`,
                    distance: totalMeters
                });
            }

            return instructions;
        }


        // Try external OpenStreetMap-based routing (OSRM). Falls back to manual routing on error.
        function createWayfindingRouteExternal(startLat, startLng, endLat, endLng, destinationName) {
            return new Promise((resolve, reject) => {
                // Basic validation
                if (isNaN(startLat) || isNaN(startLng) || isNaN(endLat) || isNaN(endLng)) {
                    console.error('Invalid coordinates for external routing:', { startLat, startLng, endLat, endLng });
                    return reject(new Error('Invalid coordinates'));
                }

                clearWayfinding();

                const startIcon = L.divIcon({
                    className: 'wayfinding-start',
                    html: `<div style="background: transparent; color: transparent; padding: 0; border: none; box-shadow: none;"><i class="bi bi-geo-alt-fill" style="font-size: 24px; color: transparent; opacity: 0;"></i></div>`,
                    iconSize: [1, 1],
                    iconAnchor: [0, 0]
                });

                const pulseIcon = L.divIcon({
                    className: 'destination-marker',
                    html: `<div style="background: #dc3545; width: 24px; height: 24px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.3);"></div>`,
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                });

                // OSRM public demo server (for light usage). Uses OpenStreetMap data.
                const url = `https://router.project-osrm.org/route/v1/foot/${startLng},${startLat};${endLng},${endLat}?overview=full&geometries=geojson`;

                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`OSRM HTTP error ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (!data || !data.routes || !data.routes.length) {
                            throw new Error('No routes returned from OSRM');
                        }

                        const geometry = data.routes[0].geometry;
                        if (!geometry || !geometry.coordinates || !geometry.coordinates.length) {
                            throw new Error('Invalid geometry from OSRM');
                        }

                        // Convert [lng, lat] → [lat, lng] for Leaflet
                        let routeCoordinates = geometry.coordinates.map(coord => [coord[1], coord[0]]);

                        // If the external route is basically a straight line, fall back to manual routing
                        try {
                            if (checkIfStraightLine(routeCoordinates)) {
                                console.warn('External routing returned a nearly straight line, falling back to manual trail routing.');
                                return reject(new Error('Straight-line route from OSRM'));
                            }
                        } catch (e) {
                            console.error('Error checking straightness of external route:', e);
                        }

                        // Generate natural language directions
                        naturalLanguageInstructions = generateNaturalLanguageDirections(routeCoordinates, destinationName || 'Destination');

                        // Add start marker at kiosk (no destination circle)
                        wayfindingStartMarker = L.marker([startLat, startLng], { icon: startIcon }).addTo(map);

                        // Draw the external route
                        wayfindingRoute = L.polyline(routeCoordinates, {
                            color: '#dc3545',
                            weight: 4,
                            opacity: 0.7,
                            fillOpacity: 0,
                            lineCap: 'round',
                            lineJoin: 'round'
                        }).addTo(map);

                        try {
                            const bounds = L.latLngBounds(routeCoordinates);
                            const isMobile = window.innerWidth <= 768;
                            // Reduced padding and higher maxZoom to keep route closer
                            const padding = isMobile ? [80, 30] : [60, 60];
                            const maxZoom = getResponsiveZoom(19, 18); // Zoom in closer
                            map.fitBounds(bounds, { padding: padding, maxZoom: maxZoom });
                        } catch (e) {
                            console.error('Error fitting bounds (external route):', e);
                            const zoomLevel = getResponsiveZoom(19, 18);
                            map.setView([endLat, endLng], zoomLevel);
                        }

                        resolve(true);
                    })
                    .catch(error => {
                        console.error('External routing (OSRM) failed:', error);
                        // Clean up any partial route drawn before falling back
                        if (wayfindingRoute) {
                            map.removeLayer(wayfindingRoute);
                            wayfindingRoute = null;
                        }
                        reject(error);
                    });
            });
        }

        // Use manual trail network for routing (no external API, fully offline-capable)
        function createWayfindingRouteManual(startLat, startLng, endLat, endLng, destinationName) {
            clearWayfinding();
            
            // Create transparent markers (no text, no color)
            const startIcon = L.divIcon({
                className: 'wayfinding-start',
                html: `<div style="background: transparent; color: transparent; padding: 0; border: none; box-shadow: none;"><i class="bi bi-geo-alt-fill" style="font-size: 24px; color: transparent; opacity: 0;"></i></div>`,
                iconSize: [1, 1],
                iconAnchor: [0, 0]
            });
            
            // Create pulsating destination marker
            const pulseIcon = L.divIcon({
                className: 'destination-marker',
                html: `<div style="background: #dc3545; width: 24px; height: 24px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.3);"></div>`,
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });
            
            // Calculate path waypoints using manual trail network
            let routeCoordinates;
            try {
                routeCoordinates = calculatePathWaypoints(startLat, startLng, endLat, endLng);
            } catch (e) {
                console.error('Error computing trail-based route, falling back to direct line:', e);
                routeCoordinates = null;
            }
            
            // Validate route coordinates
            if (!routeCoordinates || routeCoordinates.length < 2) {
                console.warn('Invalid route coordinates, falling back to direct line');
                routeCoordinates = [
                    [startLat, startLng],
                    [endLat, endLng]
                ];
            }

            // Generate natural language directions
            naturalLanguageInstructions = generateNaturalLanguageDirections(routeCoordinates, destinationName || 'Destination');
            
            // Add start marker at kiosk (no destination circle)
            wayfindingStartMarker = L.marker([startLat, startLng], { icon: startIcon }).addTo(map);

            // Create route line following the path waypoints
            wayfindingRoute = L.polyline(routeCoordinates, {
                color: '#dc3545',
                weight: 4,
                opacity: 0.6,
                fillOpacity: 0,
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(map);
            
            // Fit map to show the entire route with padding - keep closer to route
            try {
                const bounds = L.latLngBounds(routeCoordinates);
                const isMobile = window.innerWidth <= 768;
                // Reduced padding and higher maxZoom to keep route closer
                const padding = isMobile ? [80, 30] : [60, 60];
                const maxZoom = getResponsiveZoom(19, 18); // Zoom in closer
                map.fitBounds(bounds, { padding: padding, maxZoom: maxZoom });
            } catch (e) {
                console.error('Error fitting bounds:', e);
                const zoomLevel = getResponsiveZoom(19, 18);
                map.setView([endLat, endLng], zoomLevel);
            }
        }
        
        // Check if route is a straight line (indicates manual routing may have failed)
        function checkIfStraightLine(coordinates) {
            if (coordinates.length < 3) return true;
            
            // Calculate total distance
            let totalDistance = 0;
            for (let i = 0; i < coordinates.length - 1; i++) {
                totalDistance += distance(coordinates[i], coordinates[i + 1]);
            }
            
            // Calculate straight-line distance
            const straightDistance = distance(coordinates[0], coordinates[coordinates.length - 1]);
            
            // If route is less than 10% longer than straight line, it's probably too straight
            return (totalDistance / straightDistance) < 1.1;
        }

        // Define main trail network based on visible trails on the map
        // These trails represent the internal paths and nearby roads
        const trailNetwork = {
            // Main central trail (horizontal across the cemetery - primary path)
            mainCentral: [
                [14.26425, 120.86540], // West end
                [14.26430, 120.86550],
                [14.26435, 120.86560],
                [14.26440, 120.86570],
                [14.26445, 120.86580],
                [14.26450, 120.86590], // Center junction
                [14.26455, 120.86600],
                [14.26460, 120.86610],
                [14.26465, 120.86620],
                [14.26470, 120.86630], // East end
            ],
            // Upper trail (north area - for upper plot sections)
            upperTrail: [
                [14.26450, 120.86540],
                [14.26455, 120.86550],
                [14.26460, 120.86560],
                [14.26465, 120.86570],
                [14.26470, 120.86580],
                [14.26475, 120.86590],
            ],
            // Lower trail (south area - for lower plot sections)
            lowerTrail: [
                [14.26415, 120.86540],
                [14.26420, 120.86550],
                [14.26425, 120.86560],
                [14.26430, 120.86570],
                [14.26435, 120.86580],
                [14.26440, 120.86590],
            ],
            // Vertical connector trails (connect horizontal trails)
            verticalCenter: [
                [14.26440, 120.86550],
                [14.26445, 120.86560],
                [14.26450, 120.86570], // Main junction
                [14.26455, 120.86580],
                [14.26460, 120.86590],
            ],
            verticalWest: [
                [14.26420, 120.86540],
                [14.26425, 120.86550],
                [14.26430, 120.86560],
            ],
            verticalEast: [
                [14.26460, 120.86600],
                [14.26465, 120.86610],
                [14.26470, 120.86620],
            ],
            // Admin Office connector trail (connects Admin Office to main trail)
            adminConnector: [
                [14.26452, 120.86585], // Admin Office / kiosk
                [14.26450, 120.86580],
                [14.26448, 120.86575],
                [14.26446, 120.86570],
                [14.26445, 120.86565], // Connects to main central
            ],
            // North main road (external road along the northern edge)
            northMainRoad: [
                [14.264451, 120.865856],
                [14.264459, 120.865733],
                [14.264461, 120.865715],
                [14.264462, 120.865703],
                [14.264464, 120.865694],
                [14.264492, 120.865696],
                [14.264521, 120.865690],
                [14.264546, 120.865680],
                [14.264563, 120.865674],
                [14.264580, 120.865659],
                [14.264596, 120.865641],
                [14.264613, 120.865609],
                [14.264624, 120.865584],
                [14.264633, 120.865558],
                [14.264635, 120.865548],
                [14.264676, 120.865543],
                [14.264709, 120.865538],
                [14.264760, 120.865533],
                [14.264793, 120.865528],
                [14.264831, 120.865522],
                [14.264855, 120.865517],
                [14.264883, 120.865505],
                [14.264915, 120.865460],
                [14.264946, 120.865409],
                [14.264978, 120.865317],
                [14.264995, 120.865278],
                [14.265025, 120.865216],
                [14.265073, 120.865111],
                [14.265104, 120.865040],
                [14.265167, 120.864902],
                [14.265236, 120.864745],
                [14.265264, 120.864679],
                [14.265294, 120.864611],
                [14.265323, 120.864535],
                [14.265371, 120.864410],
                [14.265396, 120.864353],
                [14.265416, 120.864300],
                [14.265433, 120.864247],
                [14.265444, 120.864205],
                [14.265444, 120.864097],
                [14.265440, 120.864041],
                [14.265433, 120.863997],
                [14.265479, 120.863995],
                [14.265553, 120.864002],
                [14.265627, 120.864010],
                [14.265710, 120.864024],
                [14.265799, 120.864024],
                [14.265854, 120.864024],
                [14.265910, 120.864030],
                [14.265993, 120.864032],
                [14.266030, 120.864032],
                [14.266091, 120.864034],
                [14.266149, 120.864025],
                [14.266188, 120.864017],
                [14.266225, 120.864012],
                [14.266276, 120.863965],
                [14.266337, 120.863926],
                [14.266376, 120.863888],
                [14.266430, 120.863839],
                [14.266469, 120.863813],
                [14.266524, 120.863785],
                [14.266590, 120.863766],
                [14.266652, 120.863739],
                [14.266701, 120.863719],
                [14.266749, 120.863700],
                [14.266827, 120.863675],
            ],
            // Apollo internal road (vertical path through APOLLO section)
            apollo: [
                [14.266365, 120.863881],
                [14.266335, 120.863722],
                [14.266311, 120.863600],
                [14.266277, 120.863435],
                [14.266250, 120.863279],
                [14.266222, 120.863133],
                [14.266196, 120.862995],
                [14.266173, 120.862906],
            ],
        };

        // Find nearest point on a trail segment
        function findNearestPointOnTrail(point, trail) {
            let minDist = Infinity;
            let nearestPoint = trail[0];
            
            for (let i = 0; i < trail.length - 1; i++) {
                const p1 = trail[i];
                const p2 = trail[i + 1];
                
                // Calculate distance from point to line segment
                const dist = pointToLineDistance(point, p1, p2);
                if (dist < minDist) {
                    minDist = dist;
                    // Find closest point on the segment
                    const t = Math.max(0, Math.min(1, dotProduct(
                        [point[0] - p1[0], point[1] - p1[1]],
                        [p2[0] - p1[0], p2[1] - p1[1]]
                    ) / (Math.pow(p2[0] - p1[0], 2) + Math.pow(p2[1] - p1[1], 2))));
                    nearestPoint = [
                        p1[0] + t * (p2[0] - p1[0]),
                        p1[1] + t * (p2[1] - p1[1])
                    ];
                }
            }
            
            return nearestPoint;
        }

        // Calculate distance from point to line segment
        function pointToLineDistance(point, lineStart, lineEnd) {
            const A = point[0] - lineStart[0];
            const B = point[1] - lineStart[1];
            const C = lineEnd[0] - lineStart[0];
            const D = lineEnd[1] - lineStart[1];
            
            const dot = A * C + B * D;
            const lenSq = C * C + D * D;
            let param = -1;
            
            if (lenSq !== 0) param = dot / lenSq;
            
            let xx, yy;
            if (param < 0) {
                xx = lineStart[0];
                yy = lineStart[1];
            } else if (param > 1) {
                xx = lineEnd[0];
                yy = lineEnd[1];
            } else {
                xx = lineStart[0] + param * C;
                yy = lineStart[1] + param * D;
            }
            
            const dx = point[0] - xx;
            const dy = point[1] - yy;
            return Math.sqrt(dx * dx + dy * dy);
        }

        // Dot product helper
        function dotProduct(a, b) {
            return a[0] * b[0] + a[1] * b[1];
        }

        // Calculate distance between two points
        function distance(p1, p2) {
            const dx = p1[0] - p2[0];
            const dy = p1[1] - p2[1];
            return Math.sqrt(dx * dx + dy * dy);
        }

        // Find which trail section a point is closest to
        function findClosestTrail(point) {
            let minDist = Infinity;
            let closestTrail = null;
            let closestPoint = null;
            
            for (const [name, trail] of Object.entries(trailNetwork)) {
                const nearest = findNearestPointOnTrail(point, trail);
                const dist = distance(point, nearest);
                if (dist < minDist) {
                    minDist = dist;
                    closestTrail = name;
                    closestPoint = nearest;
                }
            }
            
            return { trail: closestTrail, point: closestPoint, distance: minDist };
        }

        // Calculate path waypoints that follow actual trails on the map
        function calculatePathWaypoints(startLat, startLng, endLat, endLng) {
            // Validate input coordinates
            if (isNaN(startLat) || isNaN(startLng) || isNaN(endLat) || isNaN(endLng)) {
                console.error('Invalid coordinates in calculatePathWaypoints:', {startLat, startLng, endLat, endLng});
                return [[startLat, startLng], [endLat, endLng]];
            }
            
            const start = [startLat, startLng];
            const end = [endLat, endLng];
            const waypoints = [];
            
            // Find closest trail points for start and end
            const startTrail = findClosestTrail(start);
            const endTrail = findClosestTrail(end);
            
            console.log('Trail routing:', {
                start: start,
                end: end,
                startTrail: startTrail.trail,
                endTrail: endTrail.trail,
                startDist: startTrail.distance,
                endDist: endTrail.distance
            });
            
            // Always start from the actual start point
            waypoints.push(start);
            
            // Route from start to nearest trail point
            if (startTrail.distance > 0.00005) {
                waypoints.push(startTrail.point);
            }
            
            // Determine routing strategy based on trail locations
            if (startTrail.trail === endTrail.trail) {
                // Both points are closest to the same trail - follow that trail
                const trail = trailNetwork[startTrail.trail];
                if (trail && trail.length > 0) {
                    const startIdx = findClosestTrailIndex(startTrail.point, trail);
                    const endIdx = findClosestTrailIndex(endTrail.point, trail);
                    
                    if (startIdx !== -1 && endIdx !== -1 && startIdx !== endIdx) {
                        const step = startIdx < endIdx ? 1 : -1;
                        const startPos = startIdx + step;
                        const endPos = endIdx + step;
                        
                        for (let i = startPos; i !== endPos; i += step) {
                            if (i >= 0 && i < trail.length) {
                                waypoints.push(trail[i]);
                            }
                        }
                    }
                }
            } else {
                // Different trails - route through main central trail as hub
                const mainTrail = trailNetwork.mainCentral;
                
                // Find connection points to main trail
                const startConnector = findNearestPointOnTrail(startTrail.point, mainTrail);
                const endConnector = findNearestPointOnTrail(endTrail.point, mainTrail);
                
                // Route from start trail to main trail
                waypoints.push(startConnector);
                
                // Follow main trail between connection points
                const startMainIdx = findClosestTrailIndex(startConnector, mainTrail);
                const endMainIdx = findClosestTrailIndex(endConnector, mainTrail);
                
                if (startMainIdx !== -1 && endMainIdx !== -1 && startMainIdx !== endMainIdx) {
                    const step = startMainIdx < endMainIdx ? 1 : -1;
                    const startPos = startMainIdx + step;
                    const endPos = endMainIdx + step;
                    
                    for (let i = startPos; i !== endPos; i += step) {
                        if (i >= 0 && i < mainTrail.length) {
                            waypoints.push(mainTrail[i]);
                        }
                    }
                }
                
                // Route from main trail to end trail
                waypoints.push(endConnector);
            }
            
            // Route from end trail point to actual end point
            if (endTrail.distance > 0.00005) {
                waypoints.push(endTrail.point);
            }
            waypoints.push(end);
            
            // Ensure we have at least 2 points
            if (waypoints.length < 2) {
                waypoints.push(end);
            }
            
            // Smooth the path by adding intermediate points for curves
            const smoothed = [];
            for (let i = 0; i < waypoints.length - 1; i++) {
                smoothed.push(waypoints[i]);
                
                // Add intermediate points for smoother curves (avoid straight lines)
                const p1 = waypoints[i];
                const p2 = waypoints[i + 1];
                const dist = distance(p1, p2);
                
                if (dist > 0.0001) {
                    // Add 1-2 intermediate points for curves
                    const mid1 = [
                        p1[0] + (p2[0] - p1[0]) * 0.33,
                        p1[1] + (p2[1] - p1[1]) * 0.33
                    ];
                    const mid2 = [
                        p1[0] + (p2[0] - p1[0]) * 0.67,
                        p1[1] + (p2[1] - p1[1]) * 0.67
                    ];
                    
                    if (dist > 0.0002) {
                        smoothed.push(mid1);
                        smoothed.push(mid2);
                    } else if (dist > 0.00015) {
                        smoothed.push(mid1);
                    }
                }
            }
            smoothed.push(waypoints[waypoints.length - 1]);
            
            console.log('Waypoints calculated (trail-based):', smoothed);
            return smoothed;
        }

        // Find closest index in a trail array
        function findClosestTrailIndex(point, trail) {
            let minDist = Infinity;
            let closestIdx = 0;
            
            for (let i = 0; i < trail.length; i++) {
                const dist = distance(point, trail[i]);
                if (dist < minDist) {
                    minDist = dist;
                    closestIdx = i;
                }
            }
            
            return closestIdx;
        }

        // Create wayfinding route from kiosk to destination.
        // Tries external OpenStreetMap-based routing first, then falls back to internal pathways.
        function createWayfindingRoute(destinationLat, destinationLng, destinationName) {
            // Validate coordinates
            if (isNaN(destinationLat) || isNaN(destinationLng)) {
                console.error('Invalid destination coordinates:', destinationLat, destinationLng);
                showNotification('Error: Invalid destination coordinates. Please contact administrator.', 'error');
                return;
            }
            
            console.log('Creating wayfinding route:', {
                from: kioskLocation,
                to: [destinationLat, destinationLng],
                destination: destinationName
            });

            // First try OSRM / OpenStreetMap-based routing when online
            createWayfindingRouteExternal(
                kioskLocation[0],
                kioskLocation[1],
                destinationLat,
                destinationLng,
                destinationName
            )
            .catch(() => {
                // If external routing fails (offline or OSRM unavailable), fall back to manual trail routing
                console.log('Falling back to manual trail-based routing.');
                try {
                    createWayfindingRouteManual(
                        kioskLocation[0],
                        kioskLocation[1],
                        destinationLat,
                        destinationLng,
                        destinationName
                    );
                } catch (error) {
                    console.error('Manual routing failed:', error);
                    showNotification('Unable to generate directions. Please contact administrator.', 'error');
                }
            });
        }

        function closeSearchResultModal() {
            if (searchResultModal && searchResultContent) {
                searchResultModal.classList.remove('active');
                searchResultContent.innerHTML = '';
            }
        }

        function closeSearchSuggestionPanel() {
            if (searchSuggestionPanel && searchSuggestionList) {
                searchSuggestionPanel.classList.remove('active');
                searchSuggestionPanel.classList.remove('minimized');
                searchSuggestionList.innerHTML = '';
            }
        }

        // Close modal when clicking backdrop or close button
        if (searchResultModal) {
            searchResultModal.addEventListener('click', function (e) {
                if (e.target === searchResultModal) {
                    closeSearchResultModal();
                }
            });
        }
        if (searchResultCloseBtn) {
            searchResultCloseBtn.addEventListener('click', closeSearchResultModal);
        }

        if (searchSuggestionClose) {
            searchSuggestionClose.addEventListener('click', closeSearchSuggestionPanel);
        }

        if (searchSuggestionPanel) {
            searchSuggestionPanel.addEventListener('click', function (e) {
                // Ignore clicks on the explicit close button
                if (e.target === searchSuggestionClose) {
                    return;
                }
                // Ignore clicks on the "Show Directions" button so its handler can run normally
                if (e.target.closest && e.target.closest('.wayfinding-btn')) {
                    return;
                }
                // If minimized, expand and close any open Leaflet popup
                if (searchSuggestionPanel.classList.contains('minimized')) {
                    searchSuggestionPanel.classList.remove('minimized');
                    if (map && map.closePopup) {
                        map.closePopup();
                    }
                }
            });
        }

        // On mobile-like devices, tap anywhere on the map to dismiss the keyboard
        // after typing in the search box. This keeps the map and results visible.
        if (isMobileLike && map && map.on) {
            map.on('click', function () {
                const input = document.getElementById('searchDeceased');
                if (input && document.activeElement === input) {
                    input.blur();
                }
            });
        }

        // Open the small Leaflet popup anchored to the marker (no centered card)
        function openPlotPopupLeaflet(plotId) {
            if (!plotId) return;
            const marker = plotMarkersById[plotId];
            if (marker && marker.openPopup) {
                marker.openPopup();
            }
        }

        // Open a custom popup for a specific deceased person from search results
        function openDeceasedPopup(item, lat, lng) {
            if (!item || isNaN(lat) || isNaN(lng)) return;
            
            const deceasedName = (item.full_name || ((item.first_name || '') + ' ' + (item.last_name || ''))).trim();
            const dob = item.date_of_birth || null;
            const dod = item.date_of_death || null;
            const burialDate = item.date_of_burial || item.burial_date || null;
            
            const lifeDates = formatLifeDatesLong(dob, dod);

            const sectionName = item.section_name || item.section_code || '—';
            const rowNumber = item.row_number ?? '—';
            const plotNumber = item.plot_number ?? '—';
            
            const plotLabel = item.section_code
                ? `${item.section_code}-${plotNumber}`
                : plotNumber;

            const popupHtml = `
                <div class="plot-popup">
                    ${deceasedName ? `
                        <div class="deceased-block">
                            <div class="deceased-name">${deceasedName}</div>
                            ${lifeDates ? `<div class="life-dates">${lifeDates}</div>` : ''}
                        </div>
                    ` : `
                        <div class="deceased-block">
                            <div class="deceased-name">No deceased record</div>
                        </div>
                    `}
                    <div class="plot-meta">
                        <p>
                            <strong>Section:</strong> ${sectionName}
                        </p>
                        <p>
                            <strong>Row:</strong> ${rowNumber}&nbsp;&nbsp;
                            <strong>Plot #:</strong> ${plotNumber}
                        </p>
                        ${burialDate ? `
                            <p><strong>Date of Burial:</strong> ${burialDate}</p>
                        ` : ''}
                        <button 
                            type="button" 
                            class="btn btn-primary btn-sm wayfinding-btn" 
                            onclick="startWayfinding(${lat}, ${lng}, '${plotLabel}', ${item.plot_id || 'null'})">
                            Show Directions
                        </button>
                    </div>
                </div>
            `;

            // Close any existing popup first
            map.closePopup();
            
            // Create and open a custom popup at the plot location
            const customPopup = L.popup({
                className: 'custom-popup',
                closeButton: true,
                autoPan: true
            })
                .setLatLng([lat, lng])
                .setContent(popupHtml);
            
            customPopup.openOn(map);
        }

        function openPlotPopupById(plotId) {
            if (!plotId) return;
            const marker = plotMarkersById[plotId];
            if (!marker) return;

            // Prefer centered modal for kiosk search results
            if (searchResultModal && searchResultContent) {
                let popupHtml = '';
                if (marker.getPopup && marker.getPopup()) {
                    const popup = marker.getPopup();
                    popupHtml = popup && popup.getContent ? popup.getContent() : '';
                } else if (marker._popup && marker._popup._content) {
                    popupHtml = marker._popup._content;
                }

                if (popupHtml) {
                    searchResultContent.innerHTML = popupHtml;
                    searchResultModal.classList.add('active');
                    return;
                }
            }

            // Fallback to default Leaflet popup
            marker.openPopup();
        }

        function showSuggestionPanelForMarker(marker, titleText) {
            if (!marker || !searchSuggestionPanel || !searchSuggestionList) return;

            let popupHtml = '';
            if (marker.getPopup && marker.getPopup()) {
                const popup = marker.getPopup();
                popupHtml = popup && popup.getContent ? popup.getContent() : '';
            } else if (marker._popup && marker._popup._content) {
                popupHtml = marker._popup._content;
            }

            if (!popupHtml) {
                return;
            }

            searchSuggestionList.innerHTML = popupHtml;
            if (searchSuggestionTitle && titleText) {
                searchSuggestionTitle.textContent = titleText;
            }
            searchSuggestionPanel.classList.remove('minimized');
            searchSuggestionPanel.classList.add('active');
            focusSuggestionPanelForMobile();
        }

        function showSuggestionPanelForPlotId(plotId, titleText) {
            if (!plotId) return;
            const marker = plotMarkersById[plotId];
            if (!marker) return;
            showSuggestionPanelForMarker(marker, titleText);
        }

        function startWayfinding(lat, lng, destinationName, plotId) {
            const destLat = parseFloat(lat);
            const destLng = parseFloat(lng);
            if (isNaN(destLat) || isNaN(destLng)) {
                console.error('Invalid destination for wayfinding:', lat, lng);
                showNotification('Unable to start wayfinding: invalid destination coordinates.', 'error');
                return;
            }

            // If a centered details modal is open, close it so we only show the small
            // arrow-style Leaflet popup anchored to the destination.
            if (typeof closeSearchResultModal === 'function') {
                closeSearchResultModal();
            }

            // Always replace any existing popup with a compact arrow-style popup
            // that only shows a location pin icon (no full details card).
            if (map && map.closePopup) {
                map.closePopup();
            }
            if (!isNaN(destLat) && !isNaN(destLng)) {
                const directionsPopup = L.popup({
                    className: 'directions-popup',
                    closeButton: false,
                    autoPan: true
                })
                    .setLatLng([destLat, destLng])
                    .setContent('<div class="directions-popup-inner"><i class="bi bi-geo-alt-fill"></i></div>');

                directionsPopup.openOn(map);
            }

            createWayfindingRoute(destLat, destLng, destinationName || 'Destination');

            // After starting directions, hide all other plots and keep only the target plot marker visible
            if (plotId) {
                hideAllPlotsExcept(plotId);
            }

            // On phones/tablets, give more vertical space to the map while
            // directions are active by minimizing the suggestion panel.
            if (isMobileLike && searchSuggestionPanel) {
                searchSuggestionPanel.classList.add('minimized');
            }
        }

        // Identify query type and search accordingly
        function identifyQueryType(searchTerm) {
            const term = searchTerm.trim().toUpperCase();
            
            // Check if it's a landmark
            const landmarkMatch = facilities.find(f => 
                f.name.toUpperCase().includes(term) || 
                f.type.toUpperCase() === term ||
                term.includes('CHAPEL') || term.includes('OFFICE') || term.includes('PARKING') || term.includes('GATE')
            );
            if (landmarkMatch) {
                return { type: 'landmark', data: landmarkMatch };
            }
            
            // Check if it's a section name
            const sectionMatch = Object.keys(sections).find(sectionName => 
                sectionName.toUpperCase() === term || 
                sectionName.toUpperCase().includes(term) ||
                term.includes(sectionName.toUpperCase())
            );
            if (sectionMatch) {
                return { type: 'section', data: { name: sectionMatch, ...sections[sectionMatch] } };
            }
            
            // Check if it looks like a plot number (contains numbers and possibly letters/dashes)
            // More strict: should have numbers and match plot number patterns
            const plotPattern = /^[A-Z0-9\-\s]+$/;
            if (plotPattern.test(term) && (/\d/.test(term) || term.includes('-') || term.includes('BLK'))) {
                // Additional check: if it's a short term (1-3 chars) and contains only letters, it's likely a name
                if (term.length <= 3 && /^[A-Z]+$/.test(term)) {
                    return { type: 'deceased', data: null };
                }
                return { type: 'plot', data: null };
            }
            
            // Default to deceased name search
            return { type: 'deceased', data: null };
        }

        // Helper function to format date from database format (YYYY-MM-DD) to mm/dd/yyyy
        function formatDateToMMDDYYYY(dateString) {
            if (!dateString) return null;
            
            try {
                // Handle various date formats
                const date = new Date(dateString);
                if (isNaN(date.getTime())) return null;
                
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const year = date.getFullYear();
                
                return `${month}/${day}/${year}`;
            } catch (e) {
                console.warn('Error formatting date:', dateString, e);
                return null;
            }
        }

        // Helper to format dates like "May 01, 2024"
        function formatDateToLong(dateString) {
            if (!dateString) return null;
            try {
                const date = new Date(dateString);
                if (isNaN(date.getTime())) return null;
                const monthNames = [
                    'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'
                ];
                const month = monthNames[date.getMonth()];
                const day = String(date.getDate()).padStart(2, '0');
                const year = date.getFullYear();
                return `${month} ${day}, ${year}`;
            } catch (e) {
                console.warn('Error formatting long date:', dateString, e);
                return null;
            }
        }

        // Helper to build life date range with N/A fallbacks
        function formatLifeDatesLong(dob, dod) {
            const birth = formatDateToLong(dob) || 'N/A';
            const death = formatDateToLong(dod) || 'N/A';
            return `${birth} - ${death}`;
        }

        // Make search bar functional with AJAX (supports deceased names, plot numbers, sections, and landmarks)
        function searchDeceased() {
            const searchTerm = document.getElementById('searchDeceased').value.trim();
            if (!searchTerm) {
                showNotification('Please enter a search term (plot number, deceased name, section, or landmark).', 'error');
                return;
            }

            // Remember last search per device so kiosk visitors can refine it easily
            try {
                localStorage.setItem('kiosk_last_map_search', searchTerm);
            } catch (e) {
                // Ignore storage failures (e.g., disabled cookies)
            }

            console.log('Searching for:', searchTerm);

            // Identify query type
            const queryType = identifyQueryType(searchTerm);
            console.log('Identified query type:', queryType);

            // Handle different query types
            if (queryType.type === 'landmark') {
                const landmark = queryType.data;
                console.log('Found landmark:', landmark);
                
                // Center map on landmark
                const zoomLevel = getResponsiveZoom(20, 19);
                map.flyTo([landmark.lat, landmark.lng], zoomLevel, { duration: 0.8 });
                
                // Open popup if marker exists
                if (landmark.marker) {
                    setTimeout(() => landmark.marker.openPopup(), 800);
                }
                return;
            }

            if (queryType.type === 'section') {
                const section = queryType.data;
                console.log('Found section:', section);
                
                // Calculate center of section
                const plots = section.plots || [];
                if (plots.length === 0) {
                    showNotification('Section found but no plots available.', 'error');
                    return;
                }
                
                const sectionLats = plots.map(p => parseFloat(p.latitude));
                const sectionLngs = plots.map(p => parseFloat(p.longitude));
                const centerLat = sectionLats.reduce((a, b) => a + b) / sectionLats.length;
                const centerLng = sectionLngs.reduce((a, b) => a + b) / sectionLngs.length;
                
                // Center map on section (wayfinding only when user clicks "Show Directions")
                const zoomLevel = getResponsiveZoom(19, 18);
                map.flyTo([centerLat, centerLng], zoomLevel, { duration: 0.8 });
                return;
            }

            // For plot and deceased searches, use existing API endpoints
            // Only try plot search if the query type is identified as 'plot'
            // Otherwise, go straight to deceased search to avoid false matches
            let searchPromise;
            
            if (queryType.type === 'plot') {
                searchPromise = fetch(`api/search_plot.php?plot=${encodeURIComponent(searchTerm)}`)
                .then(response => {
                    console.log('Plot search response status:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
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
                    
                    if (data && data.plot && data.plot.plot_id) {
                        const lat = parseFloat(data.plot.latitude);
                        const lng = parseFloat(data.plot.longitude);
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            showNotification('Error: Invalid coordinates for this plot. Please contact administrator.', 'error');
                            console.error('Invalid coordinates:', data.plot);
                            return;
                        }
                        
                        const plotLabel = data.plot.section_code ? 
                            `${data.plot.section_code}-${data.plot.plot_number}` : 
                            data.plot.plot_number;
                        
                        console.log('Search result (plot):', {
                            plot: plotLabel,
                            coordinates: [lat, lng],
                            section: data.plot.section_name
                        });
                        
                        // Center map on the found plot
                        const zoomLevel = getResponsiveZoom(20, 19);
                        map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                        
                        // Show details in the side suggestion panel instead of opening a popup
                        setTimeout(() => {
                            showSuggestionPanelForPlotId(data.plot.plot_id, plotLabel);
                            map.closePopup();
                            focusSuggestionPanelForMobile();
                        }, 800);

                        return Promise.reject('FOUND');
                    }
                    
                    // If plot search didn't find anything, try searching by deceased name
                    console.log('Plot search found nothing, trying deceased search...');
                    return fetch(`api/search_deceased.php?name=${encodeURIComponent(searchTerm)}`);
                })
                .catch((error) => {
                    // If it was a 'FOUND' error from plot search, don't continue
                    if (error === 'FOUND') {
                        return Promise.reject('FOUND');
                    }
                    // For plot searches that fail, don't fall back to deceased search
                    if (queryType.type === 'plot') {
                        showNotification('No matching plot found. Please try again.', 'error');
                        return Promise.reject('NOT_FOUND');
                    }
                    // For other errors, continue to deceased search
                    return fetch(`api/search_deceased.php?name=${encodeURIComponent(searchTerm)}`);
                });
            } else {
                // Direct deceased search for name queries
                searchPromise = fetch(`api/search_deceased.php?name=${encodeURIComponent(searchTerm)}`);
            }
            
            // Continue with deceased search handling
            searchPromise
                .then(response => {
                    if (!response) {
                        return null;
                    }
                    
                    console.log('Deceased search response status:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
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
                    if (data === null) {
                        return;
                    }
                    
                    console.log('Deceased search response data:', data);
                    
                    // Check for API errors
                    if (data.error) {
                        console.error('Search API error:', data.error);
                        showNotification('Search error: ' + data.error, 'error');
                        return;
                    }

                    // If matches are returned, show them in the side suggestion panel.
                    // We now use the same side-panel layout for both single and multiple
                    // matches so the experience is consistent.
                    let results = Array.isArray(data.results) ? data.results : [];
                    
                    console.log('Initial results count:', results.length);
                    
                    // Client-side filtering: ensure results actually match the search term
                    // This helps catch any overly broad database matches
                    const searchLower = searchTerm.toLowerCase().trim();
                    if (searchLower.length > 0) {
                        results = results.filter(item => {
                            // Validate that the item has required fields
                            if (!item.plot_id || !item.latitude || !item.longitude) {
                                console.warn('Search result missing required fields:', item);
                                return false;
                            }
                            
                            // Validate coordinates are valid numbers
                            const lat = parseFloat(item.latitude);
                            const lng = parseFloat(item.longitude);
                            if (isNaN(lat) || isNaN(lng) || lat === 0 || lng === 0) {
                                console.warn('Search result has invalid coordinates:', item);
                                return false;
                            }
                            
                            const fullName = (item.full_name || ((item.first_name || '') + ' ' + (item.last_name || ''))).trim().toLowerCase();
                            const firstName = (item.first_name || '').toLowerCase();
                            const lastName = (item.last_name || '').toLowerCase();
                            
                            // Check if search term appears in any part of the name
                            return fullName.includes(searchLower) || 
                                   firstName.includes(searchLower) || 
                                   lastName.includes(searchLower) ||
                                   fullName.split(' ').some(word => word.startsWith(searchLower));
                        });
                    }
                    
                    // Additional validation: remove any results with invalid plot information
                    // Note: section_name OR section_code is acceptable
                    const beforeValidation = results.length;
                    results = results.filter(item => {
                        return item.plot_id && 
                               (item.section_name || item.section_code) && 
                               item.plot_number &&
                               !isNaN(parseFloat(item.latitude)) &&
                               !isNaN(parseFloat(item.longitude));
                    });
                    
                    // Remove duplicate records (same record_id or same name + plot_id combination)
                    const seenRecords = new Set();
                    const seenCombinations = new Set();
                    results = results.filter(item => {
                        // Check by record_id if available
                        if (item.record_id) {
                            if (seenRecords.has(item.record_id)) {
                                console.warn('Duplicate record_id found:', item.record_id, item);
                                return false;
                            }
                            seenRecords.add(item.record_id);
                        }
                        
                        // Also check by deceased_id for legacy table
                        if (item.deceased_id) {
                            if (seenRecords.has('legacy_' + item.deceased_id)) {
                                console.warn('Duplicate deceased_id found:', item.deceased_id, item);
                                return false;
                            }
                            seenRecords.add('legacy_' + item.deceased_id);
                        }
                        
                        // Check by name + plot_id combination to catch duplicates from different tables
                        const name = (item.full_name || ((item.first_name || '') + ' ' + (item.last_name || ''))).trim().toLowerCase();
                        const comboKey = `${name}_${item.plot_id}`;
                        if (seenCombinations.has(comboKey)) {
                            console.warn('Duplicate name+plot combination found:', comboKey, item);
                            return false;
                        }
                        seenCombinations.add(comboKey);
                        
                        return true;
                    });
                    
                    console.log('Results after validation and deduplication:', results.length, 'out of', beforeValidation);
                    
                    if (results.length > 0 && searchSuggestionPanel && searchSuggestionList) {
                        // Build suggestion list HTML
                        const itemsHtml = results.map((item, index) => {
                            const deceasedName = (item.full_name || ((item.first_name || '') + ' ' + (item.last_name || ''))).trim();
                            const plotLabel = item.section_code
                                ? `${item.section_code}-${item.plot_number}`
                                : (item.plot_number || '');
                            const title = deceasedName || plotLabel || `Result ${index + 1}`;

                            const sectionName = item.section_name || item.section_code || '—';
                            const rowNumber = item.row_number ?? '—';
                            const plotNumber = item.plot_number ?? '—';

                            const dob = item.date_of_birth || null;
                            const dod = item.date_of_death || null;
                            const burialDate = item.date_of_burial || item.burial_date || null;
                            
                            // Build date display with long format and N/A fallbacks
                            const lifeDates = formatLifeDatesLong(dob, dod);
                            const dateDisplay = `<div style="font-size:0.8rem;color:#64748b;margin-top:2px;">${lifeDates}</div>`;

                            return `
                                <div class="search-suggestion-item" data-plot-id="${item.plot_id || ''}" data-title="${title.replace(/"/g, '&quot;')}" style="border-radius:12px;border:1px solid #e2e8f0;padding:10px 12px;margin-bottom:8px;cursor:pointer;background:#f9fafb;">
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                        <div>
                                            <div style="font-weight:600;color:#0f172a;">${title}</div>
                                            ${dateDisplay}
                                            <div style="font-size:0.85rem;color:#475569;margin-top:2px;">
                                                Section: <strong>${sectionName}</strong>
                                            </div>
                                            <div style="font-size:0.85rem;color:#475569;margin-top:2px;">
                                                Row: <strong>${rowNumber}</strong>
                                                &nbsp;&bull;&nbsp; Plot #: <strong>${plotNumber}</strong>
                                            </div>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-primary btn-sm search-suggestion-wayfinding">
                                                Show Directions
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('');

                        searchSuggestionList.innerHTML = itemsHtml;
                        if (searchSuggestionTitle) {
                            // Always show a counter in the header for consistency:
                            // "1 match for ..." vs "N matches for ..."
                            const label = results.length === 1 ? 'match' : 'matches';
                            searchSuggestionTitle.textContent = `${results.length} ${label} for "${searchTerm}"`;
                        }
                        searchSuggestionPanel.classList.remove('minimized');
                        searchSuggestionPanel.classList.add('active');
                        focusSuggestionPanelForMobile();

                        // Attach click handlers so selecting an item focuses its plot,
                        // and the "Show Directions" button starts wayfinding.
                        const items = searchSuggestionList.querySelectorAll('.search-suggestion-item');
                        items.forEach((el, index) => {
                            const item = results[index];
                            const plotId = item.plot_id;
                            const lat = parseFloat(item.latitude);
                            const lng = parseFloat(item.longitude);
                            const title = (item.full_name || ((item.first_name || '') + ' ' + (item.last_name || ''))).trim() ||
                                (item.section_code && item.plot_number ? `${item.section_code}-${item.plot_number}` : 'Destination');

                            el.addEventListener('click', () => {
                                if (!plotId || isNaN(lat) || isNaN(lng)) return;
                                
                                // Fly to the plot location - zoom in closer
                                const zoomLevel = getResponsiveZoom(19, 18); // Closer zoom
                                map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                                
                                // Open a custom popup showing the specific deceased person from search results
                                setTimeout(() => {
                                    openDeceasedPopup(item, lat, lng);
                                }, 800);
                            });

                            const btn = el.querySelector('.search-suggestion-wayfinding');
                            if (btn) {
                                btn.addEventListener('click', (ev) => {
                                    ev.stopPropagation(); // avoid triggering the item click
                                    if (isNaN(lat) || isNaN(lng)) {
                                        console.error('Invalid coordinates for wayfinding from suggestion item:', item);
                                        showNotification('Unable to start directions: invalid coordinates for this plot.', 'error');
                                        return;
                                    }
                                    startWayfinding(lat, lng, title, plotId || null);
                                });
                            }
                        });

                        // Optionally fit map to all matching plots
                        const bounds = L.latLngBounds();
                        results.forEach(item => {
                            const lat = parseFloat(item.latitude);
                            const lng = parseFloat(item.longitude);
                            if (!isNaN(lat) && !isNaN(lng)) {
                                bounds.extend([lat, lng]);
                            }
                        });
                        if (bounds.isValid()) {
                            const isMobile = window.innerWidth <= 768;
                            // Use smaller padding and higher maxZoom to keep results closer
                            const padding = isMobile ? [60, 20] : [40, 40];
                            const maxZoom = getResponsiveZoom(19, 18); // Zoom in closer to results
                            map.fitBounds(bounds, { padding: padding, maxZoom: maxZoom });
                        }

                        return;
                    }
                    
                        // Fallback: single match behaviour (backwards compatible) for legacy
                    // responses that only expose `plot` and do not provide `results`.
                    if (!Array.isArray(data.results) && data && data.plot && data.plot.plot_id) {
                        const lat = parseFloat(data.plot.latitude);
                        const lng = parseFloat(data.plot.longitude);
                        
                        if (isNaN(lat) || isNaN(lng)) {
                            showNotification('Error: Invalid coordinates for this plot. Please contact administrator.', 'error');
                            console.error('Invalid coordinates:', data.plot);
                            return;
                        }
                        
                        const deceasedName = (data.plot.full_name || 
                            (data.plot.first_name || '') + ' ' + (data.plot.last_name || '')).trim();
                        const plotLabel = data.plot.section_code ? 
                            `${data.plot.section_code}-${data.plot.plot_number}` : 
                            data.plot.plot_number;
                        
                        console.log('Search result (deceased):', {
                            name: deceasedName,
                            plot: plotLabel,
                            coordinates: [lat, lng]
                        });
                        
                        // Center map on the found plot - zoom in closer
                        const zoomLevel = getResponsiveZoom(19, 18); // Closer zoom
                        map.flyTo([lat, lng], zoomLevel, { duration: 0.8 });
                        
                        // Show details in the side suggestion panel instead of opening a popup
                        setTimeout(() => {
                            const title = deceasedName || plotLabel || 'Search Result';
                            showSuggestionPanelForPlotId(data.plot.plot_id, title);
                            map.closePopup();
                            focusSuggestionPanelForMobile();
                        }, 800);
                    } else {
                        showNotification('No matching plot, deceased, section, or landmark found. Please try again.', 'error');
                    }
                })
                .catch((error) => {
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
        const PERIODIC_REFRESH_INTERVAL = 30000; // Refresh every 30 seconds automatically
        let periodicRefreshTimer = null;
        
        // Periodic auto-refresh to keep map data up-to-date
        function startPeriodicRefresh() {
            // Clear any existing timer
            if (periodicRefreshTimer) {
                clearInterval(periodicRefreshTimer);
            }
            
            // Set up periodic refresh
            periodicRefreshTimer = setInterval(() => {
                // Skip refresh if directions are active (plots are hidden)
                if (hiddenPlotMarkers && hiddenPlotMarkers.length > 0) {
                    return;
                }
                
                // Skip refresh if user is in fullscreen mode (to avoid disruption)
                if (document.body.classList.contains('map-fullscreen-active')) {
                    return;
                }
                
                const now = Date.now();
                // Only refresh if it's been at least 5 seconds since last refresh
                if (now - lastRefreshTime > REFRESH_COOLDOWN) {
                    refreshPlotData(false); // Don't fit bounds on auto-refresh
                    lastRefreshTime = now;
                }
            }, PERIODIC_REFRESH_INTERVAL);
        }
        
        // Start periodic refresh when page loads
        startPeriodicRefresh();
        
        window.addEventListener('focus', function() {
            const now = Date.now();
            
            // If directions are currently active (plots are hidden), skip auto-refresh
            // so that hidden plots are not unexpectedly shown again after Alt+Tab.
            if (hiddenPlotMarkers && hiddenPlotMarkers.length > 0) {
                return;
            }

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

                // If directions are currently active (plots are hidden), skip auto-refresh
                // so that hidden plots are not unexpectedly shown again after Alt+Tab.
                if (hiddenPlotMarkers && hiddenPlotMarkers.length > 0) {
                    return;
                }

                if (now - lastRefreshTime > REFRESH_COOLDOWN) {
                    refreshPlotData(false);
                    lastRefreshTime = now;
                }
            }
        });
    </script>
</body>
</html>