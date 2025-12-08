<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Get all plots with their information for the map
$query = "SELECT p.*, s.section_code, s.section_name,
          d.first_name, d.last_name, d.date_of_burial
          FROM plots p 
          JOIN sections s ON p.section_id = s.section_id
          LEFT JOIN deceased d ON p.plot_id = d.plot_id
          ORDER BY s.section_code, 
                   LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 1),
                   CAST(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 2) AS UNSIGNED),
                   p.level_number";
$result = mysqli_query($conn, $query);

$plots = array();
while ($row = mysqli_fetch_assoc($result)) {
    $plots[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php include 'includes/styles.php'; ?>
    <style>
        #map {
            width: 100%;
            height: calc(100vh - 200px);
            border-radius: 16px;
            margin-top: 24px;
        }
        .plot-popup {
            font-size: 14px;
            line-height: 1.6;
        }
        .plot-popup h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 8px 0;
        }
        .plot-popup p {
            margin: 0 0 4px 0;
        }
        .plot-popup .status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
        }
        .plot-popup .status.available { background: #e3f2fd; color: #1976d2; }
        .plot-popup .status.reserved { background: #fff3e0; color: #f57c00; }
        .plot-popup .status.occupied { background: #fbe9e7; color: #d84315; }
        .map-controls {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .map-controls select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 160px;
        }
        .map-legend {
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: absolute;
            bottom: 24px;
            right: 24px;
            z-index: 1000;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
            font-size: 13px;
        }
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-title">Cemetery Map</div>

        <div class="map-controls">
            <select id="sectionFilter" onchange="filterPlots()">
                <option value="">All Sections</option>
                <?php
                $sections_query = "SELECT DISTINCT section_code, section_name FROM sections ORDER BY section_code";
                $sections_result = mysqli_query($conn, $sections_query);
                while ($section = mysqli_fetch_assoc($sections_result)) {
                    echo "<option value='{$section['section_code']}'>{$section['section_name']} ({$section['section_code']})</option>";
                }
                ?>
            </select>
            <select id="statusFilter" onchange="filterPlots()">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="occupied">Occupied</option>
            </select>
        </div>

        <div id="map"></div>

        <div class="map-legend">
            <div class="legend-item">
                <div class="legend-color" style="background: #4caf50;"></div>
                <span>Available</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #ff9800;"></div>
                <span>Reserved</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #f44336;"></div>
                <span>Occupied</span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Initialize the map
const map = L.map('map').setView([0, 0], 2);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Plot data from PHP
const plots = <?php echo json_encode($plots); ?>;
let markers = [];

// Plot marker colors
const plotColors = {
    available: '#4caf50',
    reserved: '#ff9800',
    occupied: '#f44336'
};

// Add plot markers to the map
function addPlotMarkers(filteredPlots = plots) {
    // Clear existing markers
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];

    // Add new markers
    const bounds = L.latLngBounds();
    filteredPlots.forEach(plot => {
        const marker = L.circleMarker([plot.latitude, plot.longitude], {
            radius: 8,
            fillColor: plotColors[plot.status],
            color: '#fff',
            weight: 1,
            opacity: 1,
            fillOpacity: 0.8
        });

        const popupContent = `
            <div class="plot-popup">
                <h3>Plot ${plot.section_code}-${plot.plot_number}</h3>
                <p><strong>Section:</strong> ${plot.section_name}</p>
                <p><strong>Status:</strong> <span class="status ${plot.status}">${plot.status.charAt(0).toUpperCase() + plot.status.slice(1)}</span></p>
                ${plot.first_name ? `
                    <p><strong>Deceased:</strong> ${plot.first_name} ${plot.last_name}</p>
                    <p><strong>Burial Date:</strong> ${new Date(plot.date_of_burial).toLocaleDateString()}</p>
                ` : ''}
                <p>
                    <a href="plot_details.php?id=${plot.plot_id}" class="btn btn-sm btn-primary mt-2">View Details</a>
                </p>
            </div>
        `;

        marker.bindPopup(popupContent);
        marker.addTo(map);
        markers.push(marker);
        bounds.extend([plot.latitude, plot.longitude]);
    });

    if (filteredPlots.length > 0) {
        map.fitBounds(bounds, { padding: [50, 50] });
    }
}

// Filter plots based on section and status
function filterPlots() {
    const sectionFilter = document.getElementById('sectionFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;

    const filteredPlots = plots.filter(plot => {
        const matchesSection = !sectionFilter || plot.section_code === sectionFilter;
        const matchesStatus = !statusFilter || plot.status === statusFilter;
        return matchesSection && matchesStatus;
    });

    addPlotMarkers(filteredPlots);
}

// Initial plot markers
addPlotMarkers();
</script>
</body>
</html> 