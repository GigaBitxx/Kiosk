<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);

// Determine if user is admin or staff
$is_admin = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$is_staff = strpos($_SERVER['PHP_SELF'], '/staff/') !== false;

// Set the appropriate navigation links
if ($is_admin) {
    $nav_links = [
        'dashboard.php' => 'Dashboard',
        'calendar.php' => 'Calendar',
        'user_management.php' => 'Manage User Roles',
        'reports.php' => 'Reports & Analytics',
        'logs.php' => 'System Logs',
        'settings.php' => 'System Settings'
    ];
} else if ($is_staff) {
    $nav_links = [
        'staff_dashboard.php' => 'Dashboard',
        'plots.php' => 'Plots',
        'contracts.php' => 'Contracts',
        'deceased_records.php' => 'Deceased Records'
    ];
}
?>
<link rel="stylesheet" href="../assets/css/consistency.css">
<link rel="stylesheet" href="../assets/css/sidebar.css">
<div class="sidebar" id="sidebar">
    <div>
        <div class="logo">Trece Martires<br>Memorial Park</div>
        <button class="collapse-btn" onclick="document.getElementById('sidebar').classList.toggle('collapsed')">&#9776;</button>
        <nav>
            <?php foreach ($nav_links as $page => $label): ?>
                <a href="<?php echo $page; ?>" class="<?php echo $current_page === $page ? 'active' : ''; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="sidebar-bottom">
        <a href="<?php echo $is_admin ? 'settings.php' : 'settings.php'; ?>" class="settings-icon" title="System Settings">&#9881;</a>
    </div>
</div> 