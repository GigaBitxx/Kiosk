<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $reserved_by = mysqli_real_escape_string($conn, $_POST['reserved_by']);
                $plot_id = mysqli_real_escape_string($conn, $_POST['plot_id']);
                $reservation_date = mysqli_real_escape_string($conn, $_POST['reservation_date']);
                $status = 'active';
                
                $query = "INSERT INTO reservations (reserved_by, plot_id, reservation_date, status) 
                         VALUES ('$reserved_by', '$plot_id', '$reservation_date', '$status')";
                mysqli_query($conn, $query);
                
                // Update plot status to reserved
                mysqli_query($conn, "UPDATE plots SET status = 'reserved' WHERE plot_id = '$plot_id'");
                log_action('Info', "Added reservation for $reserved_by (Plot ID: $plot_id)", $_SESSION['user_id']);
                break;
                
            case 'edit':
                $reservation_id = mysqli_real_escape_string($conn, $_POST['reservation_id']);
                $reserved_by = mysqli_real_escape_string($conn, $_POST['reserved_by']);
                $reservation_date = mysqli_real_escape_string($conn, $_POST['reservation_date']);
                $status = mysqli_real_escape_string($conn, $_POST['status']);
                
                $query = "UPDATE reservations SET 
                         reserved_by = '$reserved_by',
                         reservation_date = '$reservation_date',
                         status = '$status'
                         WHERE reservation_id = '$reservation_id'";
                mysqli_query($conn, $query);
                log_action('Info', "Edited reservation ID: $reservation_id for $reserved_by", $_SESSION['user_id']);
                break;
                
            case 'delete':
                $reservation_id = mysqli_real_escape_string($conn, $_POST['reservation_id']);
                $plot_id = mysqli_real_escape_string($conn, $_POST['plot_id']);
                
                mysqli_query($conn, "DELETE FROM reservations WHERE reservation_id = '$reservation_id'");
                // Update plot status to available
                mysqli_query($conn, "UPDATE plots SET status = 'available' WHERE plot_id = '$plot_id'");
                log_action('Info', "Deleted reservation ID: $reservation_id (Plot ID: $plot_id)", $_SESSION['user_id']);
                break;
        }
    }
}

// Get search term
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// Get all reservations with plot information
$query = "SELECT r.*, p.plot_number, s.section_code, s.section_name, 
          CONCAT(c.first_name, ' ', c.last_name) as client_name,
          c.email as client_email, c.phone as client_phone
          FROM reservations r 
          JOIN plots p ON r.plot_id = p.plot_id 
          JOIN sections s ON p.section_id = s.section_id
          JOIN clients c ON r.client_id = c.client_id
          ORDER BY r.reservation_date DESC";
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
            <span>Reservations</span>
            <a href="add_reservation.php" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Add Reservation
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
            <div class="table-title">All Reservations</div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Plot Location</th>
                            <th>Client</th>
                            <th>Contact Info</th>
                            <th>Reservation Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($reservation = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($reservation['section_code'] . '-' . $reservation['plot_number']); ?></td>
                            <td><?php echo htmlspecialchars($reservation['client_name']); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($reservation['client_email']); ?></div>
                                <div><?php echo htmlspecialchars($reservation['client_phone']); ?></div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($reservation['reservation_date'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="reservation_id" value="<?php echo $reservation['reservation_id']; ?>">
                                    <select name="status" class="status-select" onchange="this.form.submit()">
                                        <option value="pending" <?php echo $reservation['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo $reservation['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="cancelled" <?php echo $reservation['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <button class="action-btn view" onclick="viewReservation(<?php echo $reservation['reservation_id']; ?>)">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <button class="action-btn edit" onclick="window.location.href='edit_reservation.php?id=<?php echo $reservation['reservation_id']; ?>'">
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
function viewReservation(reservationId) {
    window.location.href = `reservation_details.php?id=${reservationId}`;
}
</script>
</body>
</html> 