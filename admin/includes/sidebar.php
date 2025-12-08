<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Match staff typography: use the same Poppins font stack as staff sidebar -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
    /* Match staff sidebar look & behavior + font style */
    .sidebar {
        width: 240px;
        background: #fff;
        color: #222;
        border-right: 1px solid #e0e0e0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 100vh;
        position: fixed;
        font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        transition: all 0.2s ease;
        z-index: 100;
    }
    .sidebar.collapsed { width: 100px; }

    .sidebar > div:first-child {
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative;
    }
    .sidebar-header {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 32px 24px 24px 24px;
        transition: all 0.2s ease;
    }
    .sidebar.collapsed .sidebar-header {
        padding: 24px 12px 16px 12px;
    }

    .sidebar-logo {
        width: 100px;
        height: auto;
        object-fit: contain;
        display: block;
        margin-bottom: 16px;
    }
    .sidebar.collapsed .sidebar-logo {
        width: 50px;
        margin-bottom: 0;
    }

    .sidebar .logo {
        font-size: 22px;
        font-weight: 700; /* match staff sidebar bold weight */
        margin: 0;
        letter-spacing: 1px;
        text-align: center;
        color: #222;
        transition: all 0.2s ease;
        line-height: 1.3;
    }
    .sidebar.collapsed .logo {
        font-size: 0;
        margin: 0;
        height: 0;
    }

    .sidebar nav {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 24px;
        flex: 1;
        padding-bottom: 24px;
        transition: all 0.2s ease;
    }
    .sidebar a {
        color: #222;
        text-decoration: none;
        padding: 12px 24px;
        display: block;
        font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        border-left: 6px solid transparent;
        font-size: 16px;
        font-weight: 500;
        transition: all 0.2s ease;
        border-radius: 0 20px 20px 0;
        position: relative;
        white-space: nowrap;
    }
    .sidebar a i {
        font-size: 18px;
        margin-right: 10px;
        vertical-align: middle;
    }
    .sidebar.collapsed a i {
        margin-right: 0;
        font-size: 20px;
    }

    .sidebar a.active,
    .sidebar a:hover {
        background: #f5f5f5;
        color: #111;
        border-left: 6px solid transparent;
    }
    .sidebar a.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 6px;
        height: 36px;
        background: #111;
        border-radius: 12px 0 0 12px;
        transition: all 0.2s ease;
    }

    .sidebar.collapsed a {
        text-align: center;
        padding: 12px 0;
        font-size: 0;
        border-left: none;
    }
    .sidebar.collapsed a.active::before {
        display: none;
    }

    .collapse-btn {
        position: absolute;
        top: 16px;
        right: -18px;
        background: #fff;
        border: 2px solid #e0e0e0;
        color: #222;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        transition: all 0.2s ease;
    }
    .collapse-btn:hover {
        background: #f5f5f5;
        border-color: #ccc;
    }

    .sidebar-bottom {
        position: absolute;
        left: 0;
        bottom: 0;
        width: 100%;
        padding: 10px 0;
        border-top: 1px solid #e0e0e0;
        text-align: center;
        background: #fff;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .sidebar.collapsed .sidebar-bottom {
        padding: 8px 0;
    }
    .sidebar-bottom .profile-icon {
        font-size: 28px;
        color: #888;
        cursor: pointer;
        transition: all 0.2s ease;
        padding: 8px 0;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        border-left: 6px solid transparent;
        border-radius: 0 20px 20px 0;
        width: 100%;
    }
    .sidebar-bottom .profile-icon:hover {
        color: #000;
        background: transparent;
    }
    .sidebar-bottom .profile-icon.active {
        background: #f5f5f5;
        color: #111;
        border-left: 6px solid transparent;
    }
    .sidebar-bottom .profile-icon.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 6px;
        height: 36px;
        background: #111;
        border-radius: 12px 0 0 12px;
        transition: all 0.2s ease;
    }
    .sidebar.collapsed .sidebar-bottom .profile-icon.active::before {
        display: none;
    }
    
    /* Mobile Sidebar Responsiveness */
    @media (max-width: 1100px) {
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        .sidebar.mobile-open {
            transform: translateX(0);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        .sidebar-overlay.show {
            display: block;
        }
        .mobile-menu-btn {
            display: block;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 101;
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #222;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .mobile-menu-btn:hover {
            background: #f5f5f5;
        }
        .collapse-btn {
            display: none;
        }
    }
    
    @media (min-width: 1101px) {
        .mobile-menu-btn,
        .sidebar-overlay {
            display: none !important;
        }
    }
</style>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>
<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()" aria-label="Toggle menu">
    &#9776;
</button>

<div class="sidebar" id="sidebar">
    <div>
        <div class="sidebar-header">
            <img src="../assets/images/tmmp-logo.png" alt="Trece Martires Memorial Park" class="sidebar-logo">
            <div class="logo">Trece Martires<br>Memorial Park</div>
        </div>
        <button class="collapse-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('collapsed')" aria-label="Toggle sidebar">
            &#9776;
        </button>
        <script>
            function toggleMobileSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && overlay) {
                    sidebar.classList.toggle('mobile-open');
                    overlay.classList.toggle('show');
                }
            }
            function closeMobileSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && overlay) {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('show');
                }
            }
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileMenuBtn');
                const overlay = document.getElementById('sidebarOverlay');
                if (window.innerWidth <= 1100 && sidebar && overlay) {
                    if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target) && overlay.classList.contains('show')) {
                        closeMobileSidebar();
                    }
                }
            });
        </script>
        <nav>
            <a href="dashboard.php"<?php echo $current_page === 'dashboard.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-home-alt-2"></i>Home
            </a>
            <a href="calendar.php"<?php echo $current_page === 'calendar.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-calendar"></i>Calendar
            </a>
            <a href="deceased_records.php"<?php echo $current_page === 'deceased_records.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-box"></i>Exhumation
            </a>
            <a href="user_management.php"<?php echo $current_page === 'user_management.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-user-circle"></i>User Roles
            </a>
            <a href="reports.php"<?php echo $current_page === 'reports.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-bar-chart-alt-2"></i>Reports &amp; Analytics
            </a>
            <a href="logs.php"<?php echo $current_page === 'logs.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-file-blank"></i>System Logs
            </a>
            <a href="settings.php"<?php echo $current_page === 'settings.php' ? ' class="active"' : ''; ?>>
                <i class="bx bx-cog"></i>System Settings
            </a>
        </nav>
    </div>
    <div class="sidebar-bottom">
        <a href="profile.php" class="profile-icon<?php echo $current_page === 'profile.php' ? ' active' : ''; ?>">
            <i class='bx bxs-user'></i>
        </a>
    </div>
</div>