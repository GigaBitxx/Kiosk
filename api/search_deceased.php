<?php
require_once '../config/database.php';
header('Content-Type: application/json');

// Error handling
error_reporting(0);
ini_set('display_errors', 0);

try {
    $name = isset($_GET['name']) ? trim($_GET['name']) : '';
    if ($name === '') {
        echo json_encode(['plot' => null, 'results' => [], 'error' => null]);
        exit;
    }

    $nameEscaped = mysqli_real_escape_string($conn, $name);

    // We will collect matches from both legacy and new tables and also expose
    // the "primary" match in the legacy `plot` key so existing callers continue
    // to work. Prioritize deceased_records over legacy deceased table to avoid
    // showing outdated/incorrect records.
    $matches = [];
    $seenPlotIds = [];
    $seenRecordIds = []; // Track record IDs to avoid duplicates

    // 1) FIRST: Check newer `deceased_records` table (prioritize this over legacy)
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
    if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
        $nameExact2 = mysqli_real_escape_string($conn, $name);
        $namePattern2 = '%' . mysqli_real_escape_string($conn, $name) . '%';
        $query2 = "SELECT 
                        p.*,
                        s.section_name,
                        s.section_code,
                        d.record_id,
                        d.full_name,
                        d.date_of_death,
                        d.burial_date,
                        CASE 
                            WHEN d.full_name LIKE '$nameExact2' THEN 1
                            WHEN d.full_name LIKE '$namePattern2' THEN 2
                            ELSE 3
                        END AS match_priority
                   FROM deceased_records d
                   INNER JOIN plots p ON d.plot_id = p.plot_id 
                   LEFT JOIN sections s ON p.section_id = s.section_id 
                   WHERE d.full_name LIKE '$namePattern2'
                     AND p.latitude IS NOT NULL 
                     AND p.longitude IS NOT NULL
                     AND p.latitude != 0 
                     AND p.longitude != 0
                   ORDER BY match_priority ASC, d.full_name ASC
                   LIMIT 20";

        $result2 = mysqli_query($conn, $query2);

        if ($result2) {
            while ($row2 = mysqli_fetch_assoc($result2)) {
                $plotId2 = isset($row2['plot_id']) ? (int)$row2['plot_id'] : 0;
                $recordId2 = isset($row2['record_id']) ? (int)$row2['record_id'] : 0;
                
                // Skip if we already have this record_id
                if ($recordId2 > 0 && isset($seenRecordIds['new_' . $recordId2])) {
                    continue;
                }

                // Verify that this deceased record actually belongs to this plot
                if ($plotId2 > 0 && $recordId2 > 0) {
                    $verifyQuery = "SELECT 1 FROM deceased_records WHERE record_id = ? AND plot_id = ? LIMIT 1";
                    $verifyStmt = mysqli_prepare($conn, $verifyQuery);
                    if ($verifyStmt) {
                        mysqli_stmt_bind_param($verifyStmt, "ii", $recordId2, $plotId2);
                        mysqli_stmt_execute($verifyStmt);
                        $verifyResult = mysqli_stmt_get_result($verifyStmt);
                        if (mysqli_num_rows($verifyResult) === 0) {
                            mysqli_stmt_close($verifyStmt);
                            continue;
                        }
                        mysqli_stmt_close($verifyStmt);
                    }
                }

                // Normalise keys so existing JS continues to work
                $firstName = $row2['full_name'];
                $lastName = '';
                if (strpos($row2['full_name'], ' ') !== false) {
                    $parts = explode(' ', $row2['full_name'], 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1];
                }

                $row2['first_name'] = $firstName;
                $row2['last_name'] = $lastName;

                if (!isset($row2['date_of_burial']) && isset($row2['burial_date'])) {
                    $row2['date_of_burial'] = $row2['burial_date'];
                }

                if ($plotId2 > 0) {
                    $seenPlotIds[$plotId2] = true;
                }
                if ($recordId2 > 0) {
                    $seenRecordIds['new_' . $recordId2] = true;
                }

                $matches[] = $row2;
            }
        }
    }

    // 2) THEN: Check legacy `deceased` table (only for plots not already found)
    //    Include section info for display on the kiosk.
    //    Use more precise matching: prioritize exact matches and word boundaries
    $nameExact = mysqli_real_escape_string($conn, $name);
    $namePattern = '%' . mysqli_real_escape_string($conn, $name) . '%';
    $query = "SELECT 
                    p.*,
                    s.section_name,
                    s.section_code,
                    d.deceased_id,
                    d.first_name,
                    d.last_name,
                    d.date_of_death,
                    d.date_of_burial,
                    CASE 
                        WHEN CONCAT(d.first_name, ' ', d.last_name) LIKE '$nameExact' THEN 1
                        WHEN d.first_name LIKE '$nameExact' OR d.last_name LIKE '$nameExact' THEN 2
                        WHEN CONCAT(d.first_name, ' ', d.last_name) LIKE '$namePattern' THEN 3
                        WHEN d.first_name LIKE '$namePattern' OR d.last_name LIKE '$namePattern' THEN 4
                        ELSE 5
                    END AS match_priority
              FROM deceased d 
              INNER JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id 
              WHERE (CONCAT(d.first_name, ' ', d.last_name) LIKE '$namePattern' 
                 OR d.first_name LIKE '$namePattern' 
                 OR d.last_name LIKE '$namePattern')
                 AND p.latitude IS NOT NULL 
                 AND p.longitude IS NOT NULL
                 AND p.latitude != 0 
                 AND p.longitude != 0
              ORDER BY match_priority ASC, d.first_name ASC, d.last_name ASC
              LIMIT 20";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        echo json_encode(['plot' => null, 'results' => [], 'error' => 'Database query error: ' . mysqli_error($conn)]);
        exit;
    }
    
    while ($row = mysqli_fetch_assoc($result)) {
        $plotId = isset($row['plot_id']) ? (int)$row['plot_id'] : 0;
        $deceasedId = isset($row['deceased_id']) ? (int)$row['deceased_id'] : 0;
        
        // Verify that this deceased record actually belongs to this plot
        // This prevents showing orphaned or incorrectly linked records
        if ($plotId > 0 && $deceasedId > 0) {
            $verifyQuery = "SELECT 1 FROM deceased WHERE deceased_id = ? AND plot_id = ? LIMIT 1";
            $verifyStmt = mysqli_prepare($conn, $verifyQuery);
            if ($verifyStmt) {
                mysqli_stmt_bind_param($verifyStmt, "ii", $deceasedId, $plotId);
                mysqli_stmt_execute($verifyStmt);
                $verifyResult = mysqli_stmt_get_result($verifyStmt);
                if (mysqli_num_rows($verifyResult) === 0) {
                    // Record doesn't actually belong to this plot - skip it
                    mysqli_stmt_close($verifyStmt);
                    continue;
                }
                mysqli_stmt_close($verifyStmt);
            }
        }
        
        // Skip if we already found a record for this plot from deceased_records (newer table)
        // This ensures newer records take priority over legacy records
        if ($plotId > 0 && isset($seenPlotIds[$plotId])) {
            continue;
        }
        
        if ($plotId > 0) {
            $seenPlotIds[$plotId] = true;
        }
        if ($deceasedId > 0) {
            $seenRecordIds['legacy_' . $deceasedId] = true;
        }
        $matches[] = $row;
    }


    // Remove duplicates: if same record appears in both tables, keep only one
    $uniqueMatches = [];
    $seenRecordIds = [];
    
    foreach ($matches as $match) {
        $recordId = null;
        if (isset($match['record_id'])) {
            $recordId = 'new_' . $match['record_id'];
        } elseif (isset($match['deceased_id'])) {
            $recordId = 'legacy_' . $match['deceased_id'];
        }
        
        // Also check by name + plot_id to catch true duplicates
        $name = isset($match['full_name']) ? $match['full_name'] : 
                (isset($match['first_name']) && isset($match['last_name']) ? 
                 trim($match['first_name'] . ' ' . $match['last_name']) : '');
        $plotId = isset($match['plot_id']) ? (int)$match['plot_id'] : 0;
        $comboKey = strtolower(trim($name)) . '_' . $plotId;
        
        if ($recordId && isset($seenRecordIds[$recordId])) {
            // Skip duplicate record_id
            continue;
        }
        if (isset($seenRecordIds[$comboKey])) {
            // Skip duplicate name+plot combination
            continue;
        }
        
        if ($recordId) {
            $seenRecordIds[$recordId] = true;
        }
        $seenRecordIds[$comboKey] = true;
        
        $uniqueMatches[] = $match;
    }
    
    if (!empty($uniqueMatches)) {
        // Backwards-compatible: expose the first match as `plot`
        echo json_encode([
            'plot'    => $uniqueMatches[0],
            'results' => $uniqueMatches,
            'error'   => null
        ]);
        exit;
    }

    // Nothing found in either table
    echo json_encode(['plot' => null, 'results' => [], 'error' => null]);
} catch (Exception $e) {
    echo json_encode(['plot' => null, 'results' => [], 'error' => 'Server error: ' . $e->getMessage()]);
}