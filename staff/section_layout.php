<?php
require_once '../config/database.php';

// Function to extract just the plot number from the full plot identifier
function extractPlotNumber($plotNumber) {
    // Remove common prefixes like "APHRODITE-", "PHRODITE-", etc.
    $plotNumber = preg_replace('/^[A-Z]+-/', '', $plotNumber);
    return $plotNumber;
}

$section_id = $_GET['section_id'] ?? null;
if (!$section_id) {
    die("Section not specified.");
}

// Get section and plots
$section_query = "SELECT * FROM sections WHERE section_id = ?";
$stmt = $conn->prepare($section_query);
$stmt->bind_param("i", $section_id);
$stmt->execute();
$section = $stmt->get_result()->fetch_assoc();

// Get all plots for this section without pagination
// Order by row_number, then col_number (when used), then plot_number for consistent layout
$plots_query = "SELECT * FROM plots WHERE section_id = ? "
    . "ORDER BY `row_number` ASC, `col_number` ASC, `plot_number` ASC";
$stmt = $conn->prepare($plots_query);
$stmt->bind_param("i", $section_id);
$stmt->execute();
$plots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
    <!-- Fonts & Bootstrap (match other staff pages like maps.php) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            color: #333333;
        }
        .page-wrapper {
            padding: 20px 20px 40px;
        }
        .plot-grid {
            display: flex;
            flex-direction: column-reverse;
            gap: 30px;
            width: 100%;
            padding: 0 20px;
            overflow-x: auto;
        }
        .plot-row {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-start;
            gap: 12px;
            align-items: center;
            flex-wrap: nowrap;
            padding: 10px 0;
            min-width: max-content;
            border-bottom: 1px solid #e4e4e4;
        }
        .plot {
            width: 60px;
            height: 45px;
            border: 2px solid #aaa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            word-wrap: break-word;
            flex-shrink: 0;
            overflow: hidden;
            white-space: nowrap;
        }
        .plot:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10;
        }
        .occupied { 
            background: #FF6B6B; 
            color: #000000;
        }
        .reserved { 
            background: #FFD700; 
            color: #333;
        }
        .available { 
            background: #90EE90; 
            color: #333;
        }
        .plot-container {
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin: 20px;
        }
        .legend { margin-bottom: 2rem; display: flex; gap: 20px; justify-content: center; }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-color { width: 20px; height: 20px; display: inline-block; border: 1px solid #aaa; }
        .plot.clickable {
            cursor: pointer;
        }
        .plot.clickable:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .modern-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            color: #555;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
            transition: background-color 0.18s ease, box-shadow 0.18s ease, transform 0.1s ease;
            text-decoration: none;
        }
        .modern-btn:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .section-title {
            text-align: center;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #2b4c7e;
            margin-top: 10px;
            margin-bottom: 8px;
        }
        .section-subtitle {
            text-align: center;
            margin-bottom: 20px;
            color: #666666;
            font-size: 0.9rem;
        }

        .row-filter-container {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: #ffffff;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
            border: 1px solid #e5e7eb;
            font-size: 0.8rem;
            color: #4b5563;
        }
        .row-filter-container label {
            margin: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.7rem;
            color: #6b7280;
        }
        .row-filter-container select {
            border: none;
            padding: 2px 8px;
            font-size: 0.8rem;
            color: #111827;
            background: transparent;
            box-shadow: none;
        }
        .row-filter-container select:focus {
            outline: none;
            box-shadow: none;
        }
        
        /* Responsive design for smaller screens */
        @media (max-width: 768px) {
            .plot-grid {
                max-width: 100%;
                padding: 0 10px;
                gap: 20px;
            }
            .plot-row {
                gap: 8px;
                padding: 8px 0;
            }
            .plot {
                width: 50px;
                height: 40px;
                font-size: 11px;
            }
            .row-filter-container {
                margin-top: 8px;
            }
        }
        
        @media (max-width: 480px) {
            .plot {
                width: 45px;
                height: 35px;
                font-size: 10px;
            }
            .plot-row {
                gap: 6px;
            }
        }
        
        /* Custom scrollbar styling */
        .plot-grid::-webkit-scrollbar {
            height: 8px;
        }
        
        .plot-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .plot-grid::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .plot-grid::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="maps.php" class="modern-btn">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="row-filter-container">
                <label for="rowFilter">Rows</label>
                <select id="rowFilter">
                    <option value="all">All</option>
                </select>
            </div>
        </div>
        <h2 class="section-title">
            <?= htmlspecialchars($section['section_name']) ?>
        </h2>
        <div class="section-subtitle">
            <i class="bi bi-arrow-left-right"></i>
            <span>Scroll horizontally to see all plots in each row</span>
        </div>
    </div>
    <div class="legend">
        <div class="legend-item"><span class="legend-color occupied"></span>Occupied</div>
        <div class="legend-item"><span class="legend-color reserved"></span>Reserved</div>
        <div class="legend-item"><span class="legend-color available"></span>Available</div>
    </div>
    <div class="plot-container">
        <div class="plot-grid">
        <?php
        // Group plots into rows based on row_number from database to match map layout
        $rows = [];
        
        foreach ($plots as $plot) {
            $row_number = isset($plot['row_number']) ? (int)$plot['row_number'] : 1;
            
            // Initialize row if it doesn't exist
            if (!isset($rows[$row_number])) {
                $rows[$row_number] = [];
            }
            
            $rows[$row_number][] = $plot;
        }

        // Sort rows by row_number and render them
        ksort($rows);
        $maxPlotsInRow = !empty($rows) ? max(array_map('count', $rows)) : 0;
        $rowMinWidthPx = $maxPlotsInRow > 0 ? ($maxPlotsInRow * 72 - 12) : 0; // 60px plot + 12px gap each, minus last gap
        foreach ($rows as $rowNumber => $rowPlots):
            // Sort plots within each row: use col_number when set (for grid alignment), else plot_number
            usort($rowPlots, function($a, $b) {
                $colA = isset($a['col_number']) ? (int)$a['col_number'] : 0;
                $colB = isset($b['col_number']) ? (int)$b['col_number'] : 0;
                if ($colA > 0 || $colB > 0) {
                    $cmp = $colA <=> $colB;
                    if ($cmp !== 0) return $cmp;
                }
                return strnatcmp($a['plot_number'] ?? '', $b['plot_number'] ?? '');
            });
            // Determine row letter (Row 1 = A, Row 2 = B, etc.) - consistent across all sections
            $rowLetter = ($rowNumber > 0 && $rowNumber <= 26) ? chr(64 + (int)$rowNumber) : (string)$rowNumber;
            echo '<div class="plot-row" data-row-number="' . (int)$rowNumber . '" data-row-letter="' . $rowLetter . '" style="min-width:' . (int)$rowMinWidthPx . 'px">';
            foreach ($rowPlots as $plot):
                $rawPlotNumber = isset($plot['plot_number']) ? $plot['plot_number'] : '';
                $plotId = isset($plot['plot_id']) ? (int)$plot['plot_id'] : 0;
                $displayNumber = extractPlotNumber($rawPlotNumber);
                $displayCode = $rowLetter . '-' . $displayNumber;
        ?>
                <div class="plot <?= htmlspecialchars($plot['status']) ?>"
                     data-plot-id="<?= $plotId ?>"
                     data-plot-number="<?= htmlspecialchars($rawPlotNumber) ?>"
                     title="Plot <?= htmlspecialchars($displayCode) ?> - <?= ucfirst($plot['status']) ?>"
                     data-bs-toggle="tooltip"
                     data-bs-title="Plot <?= htmlspecialchars($displayCode) ?> - <?= ucfirst($plot['status']) ?>">
                    <?= htmlspecialchars($displayCode) ?>
                </div>
        <?php
            endforeach;
            echo '</div>';
        endforeach;
        ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Make plots clickable to view details (by plot ID)
        document.querySelectorAll('.plot').forEach(plot => {
            plot.classList.add('clickable');
            plot.addEventListener('click', function() {
                const plotId = this.dataset.plotId;
                if (plotId) {
                    // Navigate to plot details page using specific plot ID
                    // Include origin so the back button can return here
                    window.location.href = `plot_details.php?id=${plotId}&from=section&section_id=<?= (int)$section_id ?>`;
                }
            });
        });
        
        // Add visual feedback for clickable plots
        document.querySelectorAll('.plot').forEach(plot => {
            plot.addEventListener('mouseenter', function() {
                this.style.zIndex = '10';
            });
            
            plot.addEventListener('mouseleave', function() {
                this.style.zIndex = '1';
            });
        });
        
        // Initialize Bootstrap tooltips for plots
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Row filter: populate options based on existing rows
        const rowFilterSelect = document.getElementById('rowFilter');
        if (rowFilterSelect) {
            const rowElements = Array.from(document.querySelectorAll('.plot-row'));
            const rowLetters = Array.from(new Set(
                rowElements
                    .map(r => r.dataset.rowLetter)
                    .filter(Boolean)
            )).sort();

            rowLetters.forEach(letter => {
                const opt = document.createElement('option');
                opt.value = letter;
                opt.textContent = `Row ${letter}`;
                rowFilterSelect.appendChild(opt);
            });

            rowFilterSelect.addEventListener('change', function () {
                const value = this.value;
                rowElements.forEach(row => {
                    if (value === 'all' || row.dataset.rowLetter === value) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
    </script>
</body>
</html> 