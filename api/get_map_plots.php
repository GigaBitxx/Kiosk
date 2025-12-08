<?php
header('Content-Type: application/json');
require_once '../config/database.php';

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

// Return JSON response
echo json_encode([
    'success' => true,
    'sections' => $sections,
    'all_sections' => $all_sections
]);

mysqli_close($conn);
?>

