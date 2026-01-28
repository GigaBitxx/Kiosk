<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

$success_message = $success_message ?? null;
$error_message = $error_message ?? null;

// Handle success messages from URL parameters
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'exhumation_approved') {
        $success_message = 'Exhumation / transfer request approved and applied.';
    } elseif ($_GET['success'] === 'exhumation_rejected') {
        $success_message = 'Exhumation / transfer request rejected.';
    }
}

// Check if exhumation/transfer feature is available
$exhumation_enabled = false;
$exhum_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'exhumation_requests'");
if ($exhum_table_check && mysqli_num_rows($exhum_table_check) > 0) {
    $exhumation_enabled = true;
}

// Handle exhumation approval / rejection
if ($exhumation_enabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exhumation_action'])) {
    $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
    $action = $_POST['exhumation_action'] === 'approve' ? 'approve' : 'reject';

    if ($request_id <= 0) {
        $error_message = 'Invalid exhumation request.';
    } else {
        $req_stmt = mysqli_prepare($conn, "SELECT * FROM exhumation_requests WHERE request_id = ?");
        mysqli_stmt_bind_param($req_stmt, "i", $request_id);
        mysqli_stmt_execute($req_stmt);
        $req_result = mysqli_stmt_get_result($req_stmt);
        $request = mysqli_fetch_assoc($req_result);

        if (!$request) {
            $error_message = 'Exhumation request not found.';
        } elseif ($request['status'] !== 'pending') {
            $error_message = 'This exhumation request has already been processed.';
        } else {
            $admin_id = isset($_SESSION['admin_user_id']) ? intval($_SESSION['admin_user_id']) : null;

            if ($action === 'reject') {
                // On rejection, permanently remove the exhumation request so it no longer
                // blocks plot deletions via foreign key constraints.
                $delete_stmt = mysqli_prepare(
                    $conn,
                    "DELETE FROM exhumation_requests WHERE request_id = ?"
                );
                mysqli_stmt_bind_param($delete_stmt, "i", $request_id);

                if (mysqli_stmt_execute($delete_stmt)) {
                    if (function_exists('log_action')) {
                        log_action(
                            'Warning',
                            "Exhumation request #{$request_id} for {$request['deceased_name']} was rejected and deleted by admin ID {$admin_id}.",
                            $admin_id
                        );
                    }
                    header('Location: deceased_records.php?success=exhumation_rejected');
                    exit();
                } else {
                    $error_message = 'Failed to reject exhumation request: ' . mysqli_error($conn);
                }
            } else {
                // Approve: move deceased to target plot and update statuses
                $source_plot_id = (int)$request['source_plot_id'];
                $target_plot_id = (int)$request['target_plot_id'];
                $use_deceased_records = (int)$request['use_deceased_records'] === 1;
                $deceased_record_id = (int)$request['deceased_record_id'];
                $deceased_id = (int)$request['deceased_id'];

                // Validate target plot again (ensure it still exists; allow any status so transfers can add to existing plots)
                $target_stmt = mysqli_prepare($conn, "SELECT plot_id FROM plots WHERE plot_id = ?");
                mysqli_stmt_bind_param($target_stmt, "i", $target_plot_id);
                mysqli_stmt_execute($target_stmt);
                $target_result = mysqli_stmt_get_result($target_stmt);
                $target_row = mysqli_fetch_assoc($target_result);

                if (!$target_row) {
                    $error_message = 'Target plot no longer exists.';
                } else {
                    // Move the deceased record
                    $ok = true;

                    if ($use_deceased_records && $deceased_record_id > 0) {
                        $move_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE deceased_records SET plot_id = ? WHERE record_id = ? AND plot_id = ?"
                        );
                        mysqli_stmt_bind_param($move_stmt, "iii", $target_plot_id, $deceased_record_id, $source_plot_id);
                        $ok = mysqli_stmt_execute($move_stmt);
                    } elseif (!$use_deceased_records && $deceased_id > 0) {
                        $move_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE deceased SET plot_id = ? WHERE deceased_id = ? AND plot_id = ?"
                        );
                        mysqli_stmt_bind_param($move_stmt, "iii", $target_plot_id, $deceased_id, $source_plot_id);
                        $ok = mysqli_stmt_execute($move_stmt);
                    } else {
                        $ok = false;
                        $error_message = 'Invalid deceased record information on this request.';
                    }

                    if ($ok) {
                        // Update plot statuses
                        // Source plot: if no more deceased, mark as available
                        if ($use_deceased_records) {
                            $count_stmt = mysqli_prepare(
                                $conn,
                                "SELECT COUNT(*) AS cnt FROM deceased_records WHERE plot_id = ?"
                            );
                        } else {
                            $count_stmt = mysqli_prepare(
                                $conn,
                                "SELECT COUNT(*) AS cnt FROM deceased WHERE plot_id = ?"
                            );
                        }
                        mysqli_stmt_bind_param($count_stmt, "i", $source_plot_id);
                        mysqli_stmt_execute($count_stmt);
                        $count_result = mysqli_stmt_get_result($count_stmt);
                        $count_row = mysqli_fetch_assoc($count_result);
                        $remaining = (int)($count_row['cnt'] ?? 0);

                        if ($remaining === 0 && $source_plot_id !== $target_plot_id) {
                            $src_update = mysqli_prepare(
                                $conn,
                                "UPDATE plots SET status = 'available' WHERE plot_id = ?"
                            );
                            mysqli_stmt_bind_param($src_update, "i", $source_plot_id);
                            mysqli_stmt_execute($src_update);
                        }

                        // Target plot is now occupied
                        $tgt_update = mysqli_prepare(
                            $conn,
                            "UPDATE plots SET status = 'occupied' WHERE plot_id = ?"
                        );
                        mysqli_stmt_bind_param($tgt_update, "i", $target_plot_id);
                        mysqli_stmt_execute($tgt_update);

                        // Mark request as approved
                        $req_update = mysqli_prepare(
                            $conn,
                            "UPDATE exhumation_requests 
                             SET status = 'approved', decided_at = NOW(), approved_by = ? 
                             WHERE request_id = ?"
                        );
                        mysqli_stmt_bind_param($req_update, "ii", $admin_id, $request_id);

                        if (mysqli_stmt_execute($req_update)) {
                            if (function_exists('log_action')) {
                                log_action(
                                    'Alert',
                                    "Exhumation request #{$request_id} for {$request['deceased_name']} was approved by admin ID {$admin_id} (plot {$source_plot_id} → {$target_plot_id}).",
                                    $admin_id
                                );
                            }
                            header('Location: deceased_records.php?success=exhumation_approved');
                            exit();
                        } else {
                            $error_message = 'Failed to finalize exhumation request: ' . mysqli_error($conn);
                        }
                    } elseif (!$error_message) {
                        $error_message = 'Failed to move deceased record for exhumation.';
                    }
                }
            }
        }
    }
}

// Get all deceased records with plot information
$query = "SELECT d.*, p.plot_number, s.section_code, s.section_name 
          FROM deceased d 
          JOIN plots p ON d.plot_id = p.plot_id 
          JOIN sections s ON p.section_id = s.section_id
          ORDER BY d.date_of_death DESC";
$result = mysqli_query($conn, $query);

// Get pending exhumation requests (if enabled)
$pending_exhumations = [];
if ($exhumation_enabled) {
    $ex_query = "SELECT er.*, 
                        sp.plot_number AS source_plot_number,
                        ss.section_code AS source_section_code,
                        tp.plot_number AS target_plot_number,
                        ts.section_code AS target_section_code,
                        COALESCE(u.full_name, u.username) AS requested_by_name
                 FROM exhumation_requests er
                 JOIN plots sp ON er.source_plot_id = sp.plot_id
                 JOIN sections ss ON sp.section_id = ss.section_id
                 JOIN plots tp ON er.target_plot_id = tp.plot_id
                 JOIN sections ts ON tp.section_id = ts.section_id
                 LEFT JOIN users u ON er.requested_by = u.user_id
                 WHERE er.status = 'pending'
                 ORDER BY er.created_at ASC";
    $ex_result = mysqli_query($conn, $ex_query);
    if ($ex_result) {
        while ($row = mysqli_fetch_assoc($ex_result)) {
            // Ensure we have a readable deceased name even if the stored value is empty or '0'
            $display_name = trim((string)($row['deceased_name'] ?? ''));
            if ($display_name === '' || $display_name === '0') {
                $use_new = isset($row['use_deceased_records']) && (int)$row['use_deceased_records'] === 1;
                if ($use_new && !empty($row['deceased_record_id'])) {
                    $dn_stmt = mysqli_prepare(
                        $conn,
                        "SELECT full_name FROM deceased_records WHERE record_id = ?"
                    );
                    mysqli_stmt_bind_param($dn_stmt, "i", $row['deceased_record_id']);
                    mysqli_stmt_execute($dn_stmt);
                    $dn_result = mysqli_stmt_get_result($dn_stmt);
                    if ($dn_row = mysqli_fetch_assoc($dn_result)) {
                        $display_name = trim((string)($dn_row['full_name'] ?? ''));
                    }
                } elseif (!$use_new && !empty($row['deceased_id'])) {
                    $dn_stmt = mysqli_prepare(
                        $conn,
                        "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM deceased WHERE deceased_id = ?"
                    );
                    mysqli_stmt_bind_param($dn_stmt, "i", $row['deceased_id']);
                    mysqli_stmt_execute($dn_stmt);
                    $dn_result = mysqli_stmt_get_result($dn_stmt);
                    if ($dn_row = mysqli_fetch_assoc($dn_result)) {
                        $display_name = trim((string)($dn_row['full_name'] ?? ''));
                    }
                }
            }

            if ($display_name === '' || $display_name === '0') {
                $display_name = 'Unknown';
            }

            $row['display_deceased_name'] = $display_name;
            
            // Get target plot deceased name - check if there's already someone in the target plot
            $target_plot_id = (int)$row['target_plot_id'];
            $target_deceased_name = '';
            
            // First check deceased_records table
            $target_check_stmt = mysqli_prepare(
                $conn,
                "SELECT full_name FROM deceased_records WHERE plot_id = ? LIMIT 1"
            );
            mysqli_stmt_bind_param($target_check_stmt, "i", $target_plot_id);
            mysqli_stmt_execute($target_check_stmt);
            $target_check_result = mysqli_stmt_get_result($target_check_stmt);
            if ($target_check_row = mysqli_fetch_assoc($target_check_result)) {
                $target_deceased_name = trim((string)($target_check_row['full_name'] ?? ''));
            } else {
                // If not found in deceased_records, check deceased table
                $target_check_stmt2 = mysqli_prepare(
                    $conn,
                    "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM deceased WHERE plot_id = ? LIMIT 1"
                );
                mysqli_stmt_bind_param($target_check_stmt2, "i", $target_plot_id);
                mysqli_stmt_execute($target_check_stmt2);
                $target_check_result2 = mysqli_stmt_get_result($target_check_stmt2);
                if ($target_check_row2 = mysqli_fetch_assoc($target_check_result2)) {
                    $target_deceased_name = trim((string)($target_check_row2['full_name'] ?? ''));
                }
            }
            
            $row['target_plot_deceased_name'] = $target_deceased_name;
            $pending_exhumations[] = $row;
        }
    }
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
    <script src="../assets/js/flash_clean_query.js"></script>
    <?php include 'includes/styles.php'; ?>
</head>
<body>
    <!-- Notification bubbles for exhumation actions -->
    <div id="exhumationSuccessNotification" class="notification-bubble success-notification" style="display: none;">
        <i class="bi bi-check-circle-fill"></i>
        <span></span>
    </div>
    <div id="exhumationErrorNotification" class="notification-bubble error-notification" style="display: none;">
        <i class="bi bi-x-circle-fill"></i>
        <span></span>
    </div>
    
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <h1 class="page-title">Grave Relocation</h1>

        <?php if (isset($success_message)): ?>
        <div id="exhumationSuccessMessage" data-message="<?php echo htmlspecialchars($success_message); ?>" style="display: none;"></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div id="exhumationErrorMessage" data-message="<?php echo htmlspecialchars($error_message); ?>" style="display: none;"></div>
        <?php endif; ?>

        <?php if ($exhumation_enabled): ?>
        <div class="table-card" style="margin-bottom: 24px;">
            <div class="table-title">Pending Burial / Plot Transfer Requests</div>
            <?php if (empty($pending_exhumations)): ?>
                <p style="font-size: 14px; color:#555; margin: 12px 0 0 0;">
                    There are no pending transfer requests at the moment.
                </p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Deceased</th>
                            <th>From Plot</th>
                            <th>To Plot</th>
                            <th>Requested By</th>
                            <th>Requested At</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_exhumations as $req): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($req['display_deceased_name'] ?? $req['deceased_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <?php
                                echo htmlspecialchars(
                                    ($req['source_section_code'] ?? '') . '-' . ($req['source_plot_number'] ?? '')
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                $target_plot_display = ($req['target_section_code'] ?? '') . '-' . ($req['target_plot_number'] ?? '');
                                $target_deceased = trim((string)($req['target_plot_deceased_name'] ?? ''));
                                if ($target_deceased !== '') {
                                    $target_plot_display .= '<br><small style="color: #666; font-style: italic;">(' . htmlspecialchars($target_deceased) . ')</small>';
                                }
                                echo $target_plot_display;
                                ?>
                            </td>
                            <td>
                                <?php
                                $requester_name = trim((string)($req['requested_by_name'] ?? ''));
                                if ($requester_name === '' && isset($req['requested_by']) && $req['requested_by']) {
                                    $requester_name = 'User #' . (int)$req['requested_by'];
                                }
                                echo htmlspecialchars($requester_name !== '' ? $requester_name : 'Unknown');
                                ?>
                            </td>
                            <td>
                                <?php
                                $created_at = $req['created_at'] ?? '';
                                echo $created_at
                                    ? htmlspecialchars(date('M d Y', strtotime($created_at)))
                                    : '—';
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($req['notes'] ?? ''); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <form method="POST" style="display:inline-block; margin:0;">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                        <input type="hidden" name="exhumation_action" value="approve">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline-block; margin:0;">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                        <input type="hidden" name="exhumation_action" value="reject">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewRecord(deceasedId) {
    window.location.href = `deceased_details.php?id=${deceasedId}`;
}

// Show notification bubble after exhumation approval/rejection
document.addEventListener('DOMContentLoaded', function() {
    const successNotification = document.getElementById('exhumationSuccessNotification');
    const errorNotification = document.getElementById('exhumationErrorNotification');
    const successMessageElement = document.getElementById('exhumationSuccessMessage');
    const errorMessageElement = document.getElementById('exhumationErrorMessage');
    
    const showNotification = (notification, message) => {
        if (!notification) return;
        const span = notification.querySelector('span');
        if (span) {
            span.textContent = message;
        }
        
        // Show notification
        notification.style.display = 'flex';
        notification.style.pointerEvents = 'auto';
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);
        
        // Hide notification after 4 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            notification.classList.add('hide');
            setTimeout(() => {
                notification.style.display = 'none';
                notification.style.pointerEvents = 'none';
                notification.classList.remove('hide');
            }, 250);
        }, 4000);
    };
    
    if (successMessageElement && successNotification) {
        const message = successMessageElement.getAttribute('data-message');
        if (message && (message.includes('Exhumation / transfer request') || message.includes('approved') || message.includes('rejected'))) {
            showNotification(successNotification, message);
        }
    }
    
    if (errorMessageElement && errorNotification) {
        const message = errorMessageElement.getAttribute('data-message');
        if (message && message.includes('Exhumation')) {
            showNotification(errorNotification, message);
        }
    }
});
</script>
</body>
</html> 