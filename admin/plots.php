<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $section = mysqli_real_escape_string($conn, $_POST['section']);
                $row_number = mysqli_real_escape_string($conn, $_POST['row_number']);
                $plot_number = mysqli_real_escape_string($conn, $_POST['plot_number']);
                $latitude = mysqli_real_escape_string($conn, $_POST['latitude']);
                $longitude = mysqli_real_escape_string($conn, $_POST['longitude']);
                
                $query = "INSERT INTO plots (section, row_number, plot_number, latitude, longitude) 
                         VALUES (?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "siidd", $section, $row_number, $plot_number, $latitude, $longitude);
                mysqli_stmt_execute($stmt);
                log_action('Info', "Added plot: $section-$row_number-$plot_number", $_SESSION['user_id']);
                break;
                
            case 'update':
                $plot_id = mysqli_real_escape_string($conn, $_POST['plot_id']);
                $status = mysqli_real_escape_string($conn, $_POST['status']);
                
                $query = "UPDATE plots SET status = ? WHERE plot_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "si", $status, $plot_id);
                mysqli_stmt_execute($stmt);
                log_action('Info', "Updated plot status: ID $plot_id to $status", $_SESSION['user_id']);
                break;
        }
    }
}

// Fetch all plots with deceased information
$query = "SELECT p.*, d.deceased_id, d.first_name, d.last_name, s.section_code, s.section_name 
          FROM plots p 
          LEFT JOIN deceased d ON p.plot_id = d.plot_id 
          JOIN sections s ON p.section_id = s.section_id
          ORDER BY s.section_code, "
        . "LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 1), "
        . "CAST(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(p.plot_number, '-', 2), '-', -1), 2) AS UNSIGNED), "
        . "p.level_number";
$result = mysqli_query($conn, $query);
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
    <?php include 'includes/styles.php'; ?>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-title d-flex justify-content-between align-items-center">
            <span>All Plots</span>
            <a href="add_plots.php" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Add Plots
            </a>
        </div>
        
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-title">Plot Management</div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Plot Number</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Deceased Information</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $last_section = null;
                        while ($plot = mysqli_fetch_assoc($result)): 
                            if ($plot['section_code'] !== $last_section): 
                                $last_section = $plot['section_code']; ?>
                        <tr class="section-header">
                            <td colspan="5">
                                Section: <?php echo htmlspecialchars($plot['section_name'] . ' (' . $plot['section_code'] . ')'); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($plot['plot_number']); ?></td>
                            <td><?php echo htmlspecialchars($plot['section_name']); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="plot_id" value="<?php echo $plot['plot_id']; ?>">
                                    <select name="status" class="status-select" onchange="this.form.submit()">
                                        <option value="available" <?php echo $plot['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                        <option value="reserved" <?php echo $plot['status'] === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                                        <option value="occupied" <?php echo $plot['status'] === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <?php if ($plot['deceased_id']): ?>
                                <div class="deceased-info">
                                    <?php echo htmlspecialchars($plot['first_name'] . ' ' . $plot['last_name']); ?>
                                </div>
                                <?php else: ?>
                                <div class="deceased-info">No deceased information</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="action-btn view" onclick="viewPlot(<?php echo $plot['plot_id']; ?>)">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <button class="action-btn edit" onclick="window.location.href='edit_plot.php?id=<?php echo $plot['plot_id']; ?>'">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewPlot(plotId) {
    window.location.href = `plot_details.php?id=${plotId}`;
}
</script>
</body>
</html> 