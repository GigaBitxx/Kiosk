<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// Archive logs older than 30 days
// 1. Ensure archive table exists (same structure as logs)
$create_archive_table_sql = "CREATE TABLE IF NOT EXISTS logs_archive LIKE logs";
mysqli_query($conn, $create_archive_table_sql);

// 2. Move logs older than 30 days into archive, then delete them from main table
$archive_sql = "
    INSERT INTO logs_archive
    SELECT * FROM logs
    WHERE created_at < (NOW() - INTERVAL 30 DAY)
";
$delete_old_sql = "
    DELETE FROM logs
    WHERE created_at < (NOW() - INTERVAL 30 DAY)
";

// Run archive and delete only if connection is valid
if ($conn) {
    // Archive first so we don't lose data
    mysqli_query($conn, $archive_sql);
    mysqli_query($conn, $delete_old_sql);
}

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total number of records
$total_query = "SELECT COUNT(*) as count FROM logs";
$total_result = mysqli_query($conn, $total_query);
$total_row = mysqli_fetch_assoc($total_result);
$total_records = $total_row['count'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch logs from database with pagination
$query = "
    SELECT l.*, u.full_name 
    FROM logs l 
    LEFT JOIN users u ON l.user_id = u.user_id 
    ORDER BY l.created_at DESC 
    LIMIT {$offset}, {$records_per_page}
";
$result = mysqli_query($conn, $query);
$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = $row;
}

// Fetch recent archived logs (for modal display)
$archived_logs = [];
$archive_query = "
    SELECT l.*, u.full_name
    FROM logs_archive l
    LEFT JOIN users u ON l.user_id = u.user_id
    ORDER BY l.created_at DESC
    LIMIT 50
";
$archive_result = mysqli_query($conn, $archive_query);
if ($archive_result) {
    while ($row = mysqli_fetch_assoc($archive_result)) {
        $archived_logs[] = $row;
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
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <?php include 'includes/styles.php'; ?>
    <style>
        .main {
            padding: 48px 40px 32px 40px !important;
            width: 100%;
            max-width: 100%;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 32px;
            letter-spacing: 1px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            border: 1px solid #e0e0e0;
            max-width: 100%;
            width: 100%;
        }
        .card-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
        }
        .table-responsive { 
            width: 100%; 
            overflow-x: auto;
            max-width: 100%;
        }
        table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            table-layout: auto;
        }
        th, td {
            padding: 14px 18px;
            text-align: center;
            font-size: 15px;
        }
        th { 
            background: #fafafa; 
            color: #333; 
            border-bottom: 1px solid #e0e0e0; 
            font-weight: 600;
        }
        th:nth-child(4), td:nth-child(4) {
            text-align: left !important;
        }
        tr { background: #fff; }
        tr:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 13px;
            color: #fff;
        }
        .badge.Info,
        .badge.info { background: #2b4c7e; }
        .badge.Warning,
        .badge.warning { background: #f5a623; }
        .badge.Error,
        .badge.error { background: #dc3545; }
        .badge.Alert,
        .badge.alert { background: #dc3545; }
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                padding: 48px 60px 32px 60px !important;
            }
            .card {
                padding: 40px 32px 32px 32px;
            }
            table {
                font-size: 16px;
            }
            th, td {
                padding: 16px 20px;
            }
        }
        
        @media (min-width: 1600px) {
            .main {
                padding: 48px 80px 32px 80px !important;
            }
            .card {
                padding: 48px 40px 40px 40px;
            }
            .page-title {
                font-size: 2.25rem;
            }
            .card-title {
                font-size: 20px;
            }
        }
        
        @media (min-width: 1920px) {
            .main {
                padding: 48px 120px 32px 120px !important;
            }
            .card {
                padding: 56px 48px 48px 48px;
            }
            .page-title {
                font-size: 2.5rem;
            }
            .card-title {
                font-size: 22px;
            }
            table {
                font-size: 17px;
            }
            th, td {
                padding: 18px 24px;
            }
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main {
                padding: 40px 32px 24px 32px !important;
            }
        }
        
        @media (max-width: 1100px) {
            .main { 
                padding: 24px 20px !important; 
                margin-left: 0 !important;
            }
            .page-title {
                font-size: 1.75rem;
            }
            .card {
                padding: 28px 20px 20px 20px;
            }
            table {
                font-size: 14px;
            }
            th, td {
                padding: 12px 14px;
            }
        }
        
        @media (max-width: 900px) {
            .main {
                padding: 20px 16px !important;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .card {
                padding: 20px 16px 16px 16px;
            }
            table {
                font-size: 13px;
            }
            th, td {
                padding: 10px 12px;
            }
        }
        
        @media (max-width: 768px) {
            .main { 
                padding: 16px 12px !important; 
                margin-left: 0 !important;
            }
            .page-title {
                font-size: 1.25rem;
                margin-bottom: 24px;
            }
            .card {
                padding: 16px 12px 12px 12px;
            }
            .table-responsive {
                -webkit-overflow-scrolling: touch;
            }
            table {
                font-size: 12px;
                min-width: 600px;
            }
            th, td {
                padding: 8px 10px;
                white-space: nowrap;
            }
            .badge {
                font-size: 11px;
                padding: 2px 8px;
            }
            .pagination {
                flex-wrap: wrap;
                gap: 6px;
            }
            .pagination a, .pagination span {
                min-width: 32px;
                height: 32px;
                padding: 0 10px;
                font-size: 13px;
            }
            .pagination-info {
                font-size: 12px;
                margin-top: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .main { 
                padding: 12px 8px !important; 
            }
            .page-title {
                font-size: 1.1rem;
                margin-bottom: 20px;
            }
            .card {
                padding: 12px 10px 10px 10px;
            }
            table {
                font-size: 11px;
                min-width: 500px;
            }
            th, td {
                padding: 6px 8px;
            }
            .badge {
                font-size: 10px;
                padding: 2px 6px;
            }
            .pagination a, .pagination span {
                min-width: 28px;
                height: 28px;
                padding: 0 8px;
                font-size: 12px;
            }
        }
        
        @media (max-width: 400px) {
            .main { 
                padding: 10px 6px !important; 
            }
            .page-title {
                font-size: 1rem;
            }
            table {
                font-size: 10px;
                min-width: 450px;
            }
            th, td {
                padding: 5px 6px;
            }
        }

        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 24px;
            gap: 8px;
        }
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: 8px;
            background: #fff;
            color: #333;
            text-decoration: none;
            font-size: 14px;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
        }
        .pagination a:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }
        .pagination .active {
            background: #2b4c7e;
            color: #fff;
            border-color: #2b4c7e;
        }
        .pagination .disabled {
            color: #ccc;
            cursor: not-allowed;
            pointer-events: none;
        }
        .pagination-info {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-top: 12px;
        }
        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 12px;
        }
        .logs-header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-title">System Logs</div>
        <div class="card">
            <div class="logs-header">
                <div class="card-title mb-0">Recent Logs (last 30 days)</div>
                <div class="logs-header-actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#archiveLogsModal">
                        <i class="bi bi-archive me-1"></i> Archived Logs
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $level = isset($log['level']) && !empty($log['level']) ? trim($log['level']) : 'Info';
                                    // Capitalize first letter for consistency
                                    $level = ucfirst(strtolower($level));
                                    ?>
                                    <span class="badge <?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></span>
                                </td>
                                <td><?php echo $log['created_at']; ?></td>
                                <td><?php echo $log['full_name'] ? htmlspecialchars($log['full_name']) : 'System'; ?></td>
                                <td><?php echo htmlspecialchars($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 32px; color: #888;">No logs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1" title="First Page">&laquo;</a>
                        <a href="?page=<?php echo $page - 1; ?>" title="Previous Page">&lsaquo;</a>
                    <?php else: ?>
                        <span class="disabled">&laquo;</span>
                        <span class="disabled">&lsaquo;</span>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1) {
                        echo '<a href="?page=1">1</a>';
                        if ($start_page > 2) {
                            echo '<span class="disabled">...</span>';
                        }
                    }

                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<span class="active">' . $i . '</span>';
                        } else {
                            echo '<a href="?page=' . $i . '">' . $i . '</a>';
                        }
                    }

                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span class="disabled">...</span>';
                        }
                        echo '<a href="?page=' . $total_pages . '">' . $total_pages . '</a>';
                    }
                    ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" title="Next Page">&rsaquo;</a>
                        <a href="?page=<?php echo $total_pages; ?>" title="Last Page">&raquo;</a>
                    <?php else: ?>
                        <span class="disabled">&rsaquo;</span>
                        <span class="disabled">&raquo;</span>
                    <?php endif; ?>
                </div>
                <div class="pagination-info">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> entries
                </div>
            <?php endif; ?>

            <!-- Archived Logs Modal -->
            <div class="modal fade" id="archiveLogsModal" tabindex="-1" aria-labelledby="archiveLogsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="archiveLogsModalLabel">Archived Logs (older than 30 days)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Level</th>
                                            <th>Timestamp</th>
                                            <th>User</th>
                                            <th>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archived_logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <?php 
                                                    $level = isset($log['level']) && !empty($log['level']) ? trim($log['level']) : 'Info';
                                                    $level = ucfirst(strtolower($level));
                                                    ?>
                                                    <span class="badge <?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></span>
                                                </td>
                                                <td><?php echo $log['created_at']; ?></td>
                                                <td><?php echo $log['full_name'] ? htmlspecialchars($log['full_name']) : 'System'; ?></td>
                                                <td><?php echo htmlspecialchars($log['message']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($archived_logs)): ?>
                                            <tr><td colspan="4" style="text-align: center; padding: 24px; color: #888;">No archived logs found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-2 text-muted" style="font-size: 0.875rem;">
                                Showing up to the 50 most recent archived log entries.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
</body>
</html> 