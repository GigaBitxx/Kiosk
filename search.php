<?php
require_once 'config/database.php';

$search_results = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search_input = isset($_GET['q']) ? trim($_GET['q']) : '';

// Determine which filter tab should be active in the UI
// - "all" or "recent" are direct tabs
// - any numeric section id -> "section" tab
$active_tab = ($filter === 'all' || $filter === 'recent') ? $filter : 'section';

// Run search when a query is provided OR a specific filter is selected
// This allows using filters (e.g., "Recent" or a Section) even without a text query.
$search_performed = ($search_input !== '') || ($filter !== 'all');

// Get sections for filter buttons (only sections that have at least one plot)
$sections_query = "SELECT s.section_id, s.section_name 
                   FROM sections s 
                   WHERE EXISTS (
                       SELECT 1 FROM plots p 
                       WHERE p.section_id = s.section_id
                   )
                   ORDER BY s.section_name";
$sections_result = mysqli_query($conn, $sections_query);
$sections = [];
while ($row = mysqli_fetch_assoc($sections_result)) {
    $sections[] = $row;
}

// Map of valid section IDs for quick validation
$valid_section_ids = [];
foreach ($sections as $section) {
    $valid_section_ids[(int)$section['section_id']] = true;
}

if ($search_performed) {
    $nameEscaped = mysqli_real_escape_string($conn, $search_input);
    // Uppercased variant for section/plot reference matching (e.g., "APOLLO-2-76")
    $plotSearchUpper = mysqli_real_escape_string($conn, strtoupper($search_input));

    $matches = [];
    $seenPlotIds = [];

    // Build extra filter conditions shared with map search
    $legacyExtra = [];
    $recordsExtra = [];

    if ($filter === 'recent') {
        // Recent: use either date_of_death or burial/burial_date
        $legacyExtra[]  = "COALESCE(d.date_of_death, d.date_of_burial) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $recordsExtra[] = "COALESCE(dr.date_of_death, dr.burial_date) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    } elseif ($filter !== 'all') {
        $filterId = (int)$filter;
        if (isset($valid_section_ids[$filterId])) {
            $legacyExtra[]  = "s.section_id = {$filterId}";
            $recordsExtra[] = "s.section_id = {$filterId}";
        }
    }

    // 1) Legacy `deceased` table (name + section/plot reference search)
    $legacyConditions = [];
    $legacyNameOrPlot = [];

    // Match by deceased name
    $legacyNameOrPlot[] = "(CONCAT(d.first_name, ' ', d.last_name) LIKE '%{$nameEscaped}%'
                         OR d.first_name LIKE '%{$nameEscaped}%'
                         OR d.last_name LIKE '%{$nameEscaped}%')";

    // Also allow searching by section/plot reference (e.g., "Angels Paradise-1-1" or "APOLLO-2-76")
    $legacyNameOrPlot[] = "(UPPER(p.plot_number) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_code, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_name, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_name, ' - ', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_name, '-', p.row_number, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_name, ' - ', p.row_number, ' - ', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                         OR UPPER(CONCAT(s.section_code, '-', p.row_number, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%')";

    // Combine into a single OR group, then apply extra filters (recent / section)
    $legacyConditions[] = '(' . implode(' OR ', $legacyNameOrPlot) . ')';
    $legacyConditions = array_merge($legacyConditions, $legacyExtra);

    $legacyWhere = 'WHERE ' . implode(' AND ', $legacyConditions);
    $legacyQuery = "SELECT 
                        p.*,
                        s.section_name,
                        s.section_code,
                        d.first_name,
                        d.last_name,
                        d.date_of_death,
                        d.date_of_burial
                    FROM deceased d 
                    JOIN plots p ON d.plot_id = p.plot_id 
                    JOIN sections s ON p.section_id = s.section_id 
                    {$legacyWhere}";

    $legacyResult = mysqli_query($conn, $legacyQuery);
    if ($legacyResult) {
        while ($row = mysqli_fetch_assoc($legacyResult)) {
            $plotId = isset($row['plot_id']) ? (int)$row['plot_id'] : 0;
            if ($plotId > 0) {
                $seenPlotIds[$plotId] = true;
            }

            // Normalise fields to a common shape
            $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (!isset($row['burial_date']) && isset($row['date_of_burial'])) {
                $row['burial_date'] = $row['date_of_burial'];
            }
            $matches[] = $row;
        }
    }

    // 2) New `deceased_records` table (if it exists), skipping duplicate plot_ids
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
    if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
        $recordsConditions = [];
        $recordsNameOrPlot = [];

        // Match by full_name in new `deceased_records`
        $recordsNameOrPlot[] = "dr.full_name LIKE '%{$nameEscaped}%'";

        // Also support section/plot reference formats on the joined plot/section
        $recordsNameOrPlot[] = "(UPPER(p.plot_number) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_code, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_name, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_name, ' - ', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_name, '-', p.row_number, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_name, ' - ', p.row_number, ' - ', p.plot_number)) LIKE '%{$plotSearchUpper}%'
                             OR UPPER(CONCAT(s.section_code, '-', p.row_number, '-', p.plot_number)) LIKE '%{$plotSearchUpper}%')";

        $recordsConditions[] = '(' . implode(' OR ', $recordsNameOrPlot) . ')';
        $recordsConditions = array_merge($recordsConditions, $recordsExtra);

        $recordsWhere = 'WHERE ' . implode(' AND ', $recordsConditions);
        $recordsQuery = "SELECT 
                             p.*,
                             s.section_name,
                             s.section_code,
                             dr.full_name,
                             dr.date_of_death,
                             dr.burial_date
                         FROM deceased_records dr
                         JOIN plots p ON dr.plot_id = p.plot_id 
                         JOIN sections s ON p.section_id = s.section_id 
                         {$recordsWhere}";

        $recordsResult = mysqli_query($conn, $recordsQuery);
        if ($recordsResult) {
            while ($row = mysqli_fetch_assoc($recordsResult)) {
                $plotId = isset($row['plot_id']) ? (int)$row['plot_id'] : 0;
                if ($plotId > 0 && isset($seenPlotIds[$plotId])) {
                    // Skip duplicates we already picked up from legacy table
                    continue;
                }
                if ($plotId > 0) {
                    $seenPlotIds[$plotId] = true;
                }
                $matches[] = $row;
            }
        }
    }

    // Final sort: newest first (by death/burial date), then by name
    if (!empty($matches)) {
        usort($matches, function ($a, $b) {
            $dateA = $a['date_of_death'] ?? $a['burial_date'] ?? '';
            $dateB = $b['date_of_death'] ?? $b['burial_date'] ?? '';

            if ($dateA === $dateB) {
                return strcasecmp($a['full_name'], $b['full_name'] ?? '');
            }

            // Newest (largest date string) first
            return strcmp($dateB, $dateA);
        });

        $search_results = $matches;
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
    <link rel="icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-soft: #f4f6f9;
            --ink: #1d2a38;
            --ink-muted: #516072;
            --accent: #2b4c7e;
            --panel: #ffffff;
            --border-soft: rgba(15, 23, 42, 0.1);
        }
        body {
            background: var(--bg-soft);
            min-height: 80vh;
            margin: 0;
            font-family: 'Raleway', 'Helvetica Neue', Arial, sans-serif;
            color: var(--ink);
            padding: 3vw 2vw;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
        }
        .btn-outline-primary {
            color: var(--accent);
            border-color: var(--accent);
        }
        .search-shell {
            width: min(1400px, 95vw);
            background: var(--panel);
            border: 1px solid var(--border-soft);
            box-shadow: 0 30px 65px rgba(15,23,42,0.08);
            border-radius: 24px;
            padding: 3rem 3.5rem;
        }
        .page-heading {
            text-align: center;
        }
        .page-heading h1 {
            font-size: clamp(2rem, 3vw, 2.8rem);
            font-weight: 700;
        }
        .page-heading p {
            color: var(--ink-muted);
            margin-bottom: 1.5rem;
        }
        .btn-back {
            border-radius: 999px;
            border: 1px solid var(--border-soft);
            color: var(--ink);
            padding: 0.5rem 1.25rem;
        }
        .search-container {
            background: rgba(43, 76, 126, 0.07);
            padding: 1.5rem;
            border-radius: 20px;
            border: 1px solid var(--border-soft);
            margin-bottom: 2.5rem;
            min-height: 200px;
        }
        .result-card {
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            transition: box-shadow 0.3s ease, transform 0.3s ease;
            margin-bottom: 1rem;
        }
        .result-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 45px rgba(15,23,42,0.12);
        }
        .filter-btn {
            border: 1px solid var(--border-soft);
            color: var(--ink);
            border-radius: 999px;
            padding: 0.65rem 1.2rem;
            font-weight: 600;
        }
        .filter-btn.active {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
            box-shadow: 0 6px 18px rgba(43,76,126,0.25);
        }
        .filter-tabs {
            gap: 0.5rem;
        }
        .section-select-wrapper {
            margin-top: 1rem;
        }
        /* Form input consistency */
        .form-control, .form-select {
            font-size: 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 10px 12px;
            min-height: 40px;
        }
        .form-control-lg {
            font-size: 14px;
            padding: 10px 12px;
            min-height: 40px;
        }
        .input-group-text {
            font-size: 14px;
            padding: 10px 12px;
            min-height: 40px;
        }
        /* Search results consistency */
        #results-section h3 {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 1.5rem;
            font-weight: 600;
        }
        #results-section h3 small.text-muted {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            font-weight: 400;
            color: var(--ink-muted);
        }
        .result-card .card-title {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 16px;
            font-weight: 600;
        }
        .result-card .card-text {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            font-weight: 400;
        }
        .result-card .card-text strong {
            font-weight: 600;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                padding: 16px 12px;
            }
            .search-shell {
                width: 100%;
                padding: 1.5rem 1.25rem;
                box-sizing: border-box;
            }
            .search-container {
                padding: 1rem;
            }
            .page-heading .d-flex {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .btn-back {
                padding: 0.45rem 1rem;
            }
            .filter-tabs {
                gap: 0.35rem;
            }
            .result-card .row {
                flex-direction: column;
                gap: 0.75rem;
            }
            .result-card .col-md-4 {
                text-align: left !important;
            }
        }

        @media (max-width: 480px) {
            .search-shell {
                padding: 1.25rem 1rem;
            }
            .search-container {
                padding: 0.9rem;
            }
            .page-heading h1 {
                font-size: clamp(1.5rem, 5vw, 1.8rem);
            }
            .filter-btn {
                padding: 0.55rem 1rem;
                font-size: 0.95rem;
            }
            .result-card .card-title {
                font-size: 15px;
            }
            .result-card .card-text {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="search-shell">
        <div class="page-heading mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="main.php" class="btn btn-back">
                    <i class="bi bi-arrow-left"></i> Home
                </a>
                <span class="text-uppercase fw-semibold text-muted">Search</span>
            </div>
            <h1>Gravesite Locator</h1>
            <p>Search by name, section, or plot reference. (e.g., Angels Paradise-1-1) <br> Use filters to narrow recent interments or specific sections.</p>
        </div>

        <!-- Search Form -->
        <div class="search-container">
            <form method="GET" class="row g-3" id="search-form">
                <input type="hidden" name="filter" id="selected-filter" value="<?php echo htmlspecialchars($filter); ?>">
                <div class="col-md-8">
                    <label for="search-query" class="visually-hidden">
                        Search by name, section, or plot reference
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input
                            type="text"
                            id="search-query"
                            class="form-control form-control-lg"
                            name="q"
                            placeholder="Search by name, section, or plot (e.g., APOLLO-2-76 or Juan)"
                            aria-label="Search by name, section, or plot reference"
                            value="<?php echo htmlspecialchars($search_input); ?>"
                        >
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-lg w-100 text-uppercase">
                        Search
                    </button>
                </div>
            </form>

            <!-- Filter Tabs + Section Selector -->
            <div class="mt-4">
                <h5 class="mb-3">Filters:</h5>
                <div class="d-flex flex-wrap filter-tabs" id="filter-tabs">
                    <button type="button"
                            data-filter-tab="all"
                            class="btn filter-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>">
                        <i class="bx bx-grid-alt"></i> All
                    </button>
                    <button type="button"
                            data-filter-tab="recent"
                            class="btn filter-btn <?php echo $active_tab === 'recent' ? 'active' : ''; ?>">
                        <i class="bx bx-time-five"></i> Recent (Last Year)
                    </button>
                    <button type="button"
                            data-filter-tab="section"
                            class="btn filter-btn <?php echo $active_tab === 'section' ? 'active' : ''; ?>">
                        <i class="bx bx-map-pin"></i> Section
                    </button>
                </div>

                <div class="section-select-wrapper" id="section-select-wrapper"
                     style="display: <?php echo $active_tab === 'section' ? 'block' : 'none'; ?>;">
                    <label for="section-select" class="form-label mb-1">Choose Section</label>
                    <select id="section-select" class="form-select">
                        <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['section_id']; ?>"
                                <?php echo ($filter == $section['section_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($section['section_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Search Results -->
        <?php if ($search_performed): ?>
            <div id="results-section">
                <h3 class="mb-4">Search Results 
                    <?php if ($filter !== 'all'): ?>
                        <small class="text-muted">(Filtered)</small>
                    <?php endif; ?>
                </h3>
                <?php if (empty($search_results)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No results found for your search.
                    </div>
                <?php else: ?>
                    <?php foreach ($search_results as $result): ?>
                        <div class="card result-card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="card-title">
                                            <?php echo htmlspecialchars($result['full_name']); ?>
                                        </h5>
                                        <p class="card-text">
                                            <?php
                                                $sectionLabel = $result['section_name'] 
                                                    ?? $result['section_code'] 
                                                    ?? ($result['section'] ?? '');
                                            ?>
                                            <strong>Plot Location:</strong> 
                                            <?php echo htmlspecialchars(trim($sectionLabel . '-' . $result['row_number'] . '-' . $result['plot_number'], '-')); ?><br>
                                            <strong>Date of Death:</strong> <?php echo htmlspecialchars($result['date_of_death'] ?? '—'); ?><br>
                                            <strong>Date of Burial:</strong> <?php echo htmlspecialchars($result['burial_date'] ?? '—'); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <a href="map.php?plot=<?php echo $result['plot_id']; ?>&auto=1" class="btn btn-outline-primary">
                                            <i class="bi bi-map"></i> View on Map
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const searchInput = document.querySelector('input[name="q"]');
        const searchForm = document.getElementById('search-form');
        const filterInput = document.getElementById('selected-filter');

        // Tabs
        const filterTabs = document.querySelectorAll('#filter-tabs .filter-btn');
        const sectionWrapper = document.getElementById('section-select-wrapper');
        const sectionSelect = document.getElementById('section-select');

        const submitWithFilter = (value) => {
            filterInput.value = value;
            // If there is a query, submit the form; otherwise, just change URL params
            if (searchInput.value.trim() !== '') {
                searchForm.submit();
            } else {
                const params = new URLSearchParams(window.location.search);
                if (value === 'all') {
                    params.delete('filter');
                } else {
                    params.set('filter', value);
                }
                window.location.search = params.toString();
            }
        };

        const updateActiveTab = (activeBtn) => {
            filterTabs.forEach(btn => btn.classList.toggle('active', btn === activeBtn));
        };

        filterTabs.forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.filterTab;
                updateActiveTab(btn);

                if (tab === 'section') {
                    sectionWrapper.style.display = 'block';
                    // When entering "Section" tab, use current select value (if any)
                    if (sectionSelect && sectionSelect.value) {
                        submitWithFilter(sectionSelect.value);
                    }
                } else {
                    sectionWrapper.style.display = 'none';
                    submitWithFilter(tab);
                }
            });
        });

        if (sectionSelect) {
            sectionSelect.addEventListener('change', () => {
                if (sectionSelect.value) {
                    submitWithFilter(sectionSelect.value);
                }
            });
        }

        // Apply saved kiosk brightness (for consistency with main page settings)
        function applyBrightness(value) {
            let overlay = document.getElementById('brightnessOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'brightnessOverlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, ${(100 - value) / 200});
                    pointer-events: none;
                    z-index: 999999;
                    transition: background 0.3s ease;
                `;
                document.body.appendChild(overlay);
            } else {
                overlay.style.background = `rgba(0, 0, 0, ${(100 - value) / 200})`;
            }
        }

        // Load brightness on page load
        (function loadBrightnessSetting() {
            const savedBrightness = localStorage.getItem('kiosk_brightness') || '100';
            applyBrightness(parseInt(savedBrightness, 10) || 100);
        })();

        // Idle timeout redirect to welcome screen (match kiosk behavior)
        let idleTimeout;
        const IDLE_LIMIT = 60000; // 60 seconds

        const resetIdleTimer = () => {
            clearTimeout(idleTimeout);
            idleTimeout = setTimeout(() => {
                window.location.href = 'index.php';
            }, IDLE_LIMIT);
        };

        ['mousemove', 'touchstart', 'keydown', 'click'].forEach(evt => {
            window.addEventListener(evt, resetIdleTimer);
        });

        resetIdleTimer();
    </script>
</body>
</html> 