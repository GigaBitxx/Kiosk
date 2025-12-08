<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';
require_once 'includes/logging.php';

$message = '';

// Handle approval
if (isset($_GET['approve']) && is_numeric($_GET['approve'])) {
    $pending_id = intval($_GET['approve']);
    
    $stmt = $conn->prepare("SELECT * FROM pending_admin_registrations WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $pending_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $pending = $result->fetch_assoc();
        
        // Check if username already exists
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $pending['username']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = '<div class="alert alert-danger">Username already exists. Registration rejected.</div>';
            // Update status to rejected
            $update_stmt = $conn->prepare("UPDATE pending_admin_registrations SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("ii", $_SESSION['user_id'], $pending_id);
            $update_stmt->execute();
            log_action('Warning', 'Rejected pending admin registration: ' . $pending['username'] . ' (username exists)', $_SESSION['user_id']);
        } else {
            // Check admin limit (max 5 admins)
            require_once '../config/security.php';
            if (is_admin_limit_reached($conn, 5)) {
                $message = '<div class="alert alert-warning">Cannot approve: Maximum limit of 5 administrators has been reached. Please remove an existing admin before approving new ones.</div>';
                // Update status to rejected
                $update_stmt = $conn->prepare("UPDATE pending_admin_registrations SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $update_stmt->bind_param("ii", $_SESSION['user_id'], $pending_id);
                $update_stmt->execute();
                log_action('Warning', 'Rejected pending admin registration: ' . $pending['username'] . ' (admin limit reached)', $_SESSION['user_id']);
            } else {
                // Create admin user
                $insert_stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'admin')");
                $insert_stmt->bind_param("sss", $pending['username'], $pending['password_hash'], $pending['full_name']);
                
                if ($insert_stmt->execute()) {
                    // Update pending registration status
                    $update_stmt = $conn->prepare("UPDATE pending_admin_registrations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("ii", $_SESSION['user_id'], $pending_id);
                    $update_stmt->execute();
                    
                    $message = '<div class="alert alert-success">Admin account approved and created successfully!</div>';
                    log_action('Info', 'Approved and created admin account: ' . $pending['username'], $_SESSION['user_id']);
                } else {
                    $message = '<div class="alert alert-danger">Error creating admin account: ' . $conn->error . '</div>';
                    log_action('Error', 'Failed to create approved admin account: ' . $pending['username'], $_SESSION['user_id']);
                }
            }
        }
    } else {
        $message = '<div class="alert alert-danger">Pending registration not found or already processed.</div>';
    }
}

// Handle rejection
if (isset($_GET['reject']) && is_numeric($_GET['reject'])) {
    $pending_id = intval($_GET['reject']);
    
    $update_stmt = $conn->prepare("UPDATE pending_admin_registrations SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
    $update_stmt->bind_param("ii", $_SESSION['user_id'], $pending_id);
    
    if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
        $stmt = $conn->prepare("SELECT username FROM pending_admin_registrations WHERE id = ?");
        $stmt->bind_param("i", $pending_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pending = $result->fetch_assoc();
        
        $message = '<div class="alert alert-warning">Admin registration rejected.</div>';
        log_action('Warning', 'Rejected pending admin registration: ' . $pending['username'], $_SESSION['user_id']);
    } else {
        $message = '<div class="alert alert-danger">Failed to reject registration or already processed.</div>';
    }
}

// Get pending registrations
$pending_query = "SELECT par.*, u.username as approved_by_username 
                  FROM pending_admin_registrations par 
                  LEFT JOIN users u ON par.approved_by = u.user_id 
                  WHERE par.status = 'pending' 
                  ORDER BY par.created_at DESC";
$pending_result = mysqli_query($conn, $pending_query);
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-shield-check"></i> Approve Admin Registrations</h2>
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
        
        <?php echo $message; ?>
        
        <?php if (mysqli_num_rows($pending_result) > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> 
                <strong>Security Alert:</strong> Review each pending admin registration carefully before approval.
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>IP Address</th>
                            <th>Requested At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($pending_result)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($row['ip_address']); ?></code></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <a href="?approve=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-success" 
                                       onclick="return confirm('Are you sure you want to approve this admin registration?');">
                                        <i class="bi bi-check-circle"></i> Approve
                                    </a>
                                    <a href="?reject=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Are you sure you want to reject this admin registration?');">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No pending admin registrations at this time.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

