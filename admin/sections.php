<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Get all sections with plot counts
$query = "SELECT s.*, 
          COUNT(p.plot_id) as total_plots,
          SUM(CASE WHEN p.status = 'available' THEN 1 ELSE 0 END) as available_plots,
          SUM(CASE WHEN p.status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
          SUM(CASE WHEN p.status = 'occupied' THEN 1 ELSE 0 END) as occupied_plots
          FROM sections s
          LEFT JOIN plots p ON s.section_id = p.section_id
          GROUP BY s.section_id
          ORDER BY s.section_code";
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
            <span>Cemetery Sections</span>
            <a href="add_section.php" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Add Section
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
            <div class="table-title">All Sections</div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Section Code</th>
                            <th>Section Name</th>
                            <th>Total Plots</th>
                            <th>Available</th>
                            <th>Reserved</th>
                            <th>Occupied</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($section = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($section['section_code']); ?></td>
                            <td><?php echo htmlspecialchars($section['section_name']); ?></td>
                            <td><?php echo $section['total_plots']; ?></td>
                            <td><?php echo $section['available_plots']; ?></td>
                            <td><?php echo $section['reserved_plots']; ?></td>
                            <td><?php echo $section['occupied_plots']; ?></td>
                            <td>
                                <button class="action-btn view" onclick="viewSection(<?php echo $section['section_id']; ?>)">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <button class="action-btn edit" onclick="window.location.href='edit_section.php?id=<?php echo $section['section_id']; ?>'">
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
function viewSection(sectionId) {
    window.location.href = `section_details.php?id=${sectionId}`;
}
</script>
</body>
</html> 