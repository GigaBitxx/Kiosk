<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
    /* Staff Sidebar Styles - Consistent across all staff pages */
    .layout { display: flex; min-height: 100vh; }
    .sidebar {
        width: 240px;
        background: #fff;
        color: #222;
        border-right: 1px solid #e0e0e0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 100vh;
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        transition: transform 0.3s ease, width 0.2s ease;
        z-index: 100;
        font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .sidebar.collapsed { width: 100px; }
    .sidebar .logo {
        font-size: 22px;
        font-weight: 700;
        padding: 0;
        letter-spacing: 1px;
        text-align: center;
        color: #222;
        transition: all 0.2s ease;
        line-height: 1.3;
    }
    .sidebar.collapsed .logo { 
        font-size: 0; 
        padding: 0; 
        margin: 0;
        height: 0;
    }
    .sidebar.collapsed > div:first-child > div:first-child {
        padding: 24px 12px 16px 12px;
    }
    .sidebar.collapsed .sidebar-logo {
        margin-bottom: 0;
    }
    .sidebar > div:first-child {
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative !important;
        overflow: visible !important;
    }
    .sidebar nav {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 24px;
        flex: 1;
        /* Extra bottom padding so last item never sits under the profile tab */
        padding-bottom: 72px;
        transition: all 0.2s ease;
    }
    .sidebar a {
        color: #222;
        text-decoration: none;
        padding: 12px 24px;
        display: flex;
        align-items: center;
        border-left: 6px solid transparent;
        font-size: 16px;
        font-weight: 500;
        transition: all 0.2s ease;
        border-radius: 0 20px 20px 0;
        position: relative;
        white-space: nowrap;
    }
    .sidebar a.active, .sidebar a:hover {
        background: #f5f5f5;
        color: #111;
        border-left: 6px solid transparent;
        font-weight: 500;
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
    .sidebar .collapse-btn {
        position: absolute !important;
        top: 16px !important;
        right: -18px !important;
        background: #fff !important;
        border: 2px solid #e0e0e0 !important;
        color: #222 !important;
        border-radius: 50% !important;
        width: 36px !important;
        height: 36px !important;
        cursor: pointer;
        display: flex !important;
        align-items: center;
        justify-content: center;
        z-index: 101 !important;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
    }
    .sidebar .collapse-btn:hover {
        background: #f5f5f5;
        border-color: #ccc;
    }
    .sidebar .sidebar-bottom {
        position: absolute !important;
        left: 0 !important;
        bottom: 0 !important;
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
        gap: 0;
    }
    .sidebar.collapsed .sidebar-bottom {
        padding: 8px 0;
    }
    .sidebar .sidebar-bottom .profile-icon {
        font-size: 28px;
        color: #888;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 8px 0;
        text-decoration: none;
        border: none;
        background: transparent;
    }
    .sidebar .sidebar-bottom .profile-icon:hover {
        color: #000;
        background: transparent;
    }
    .sidebar .sidebar-bottom .profile-icon.active {
        color: #000;
    }
    .main {
        flex: 1;
        padding: 48px 40px 32px 40px;
        background: #f5f5f5;
        margin-left: 240px;
        transition: margin-left 0.2s ease, padding 0.3s ease;
    }
    .sidebar.collapsed + .main {
        margin-left: 100px;
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
    }
    .sidebar a i {
        font-size: 18px;
        margin-right: 10px;
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
    }
    .sidebar.collapsed a i {
        margin-right: 0;
        font-size: 20px;
    }
    .sidebar a.active i {
        font-size: 18px;
        margin-right: 10px;
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
        .sidebar .collapse-btn {
            display: none;
        }
        .main {
            margin-left: 0 !important;
            width: 100% !important;
        }
        .sidebar.collapsed + .main {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }
    
    @media (min-width: 1101px) {
        .mobile-menu-btn,
        .sidebar-overlay {
            display: none !important;
        }
    }
    
    /* Responsive Main Content */
    @media (max-width: 1100px) {
        .main {
            padding: 24px 20px !important;
        }
    }
    
    @media (max-width: 768px) {
        .main {
            padding: 16px 12px !important;
        }
    }
    
    @media (max-width: 576px) {
        .main {
            padding: 12px 8px !important;
        }
    }
    
    /* Prevent horizontal scroll */
    body {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .layout {
        width: 100%;
        overflow-x: hidden;
    }
    
    .main {
        width: calc(100% - 240px);
        box-sizing: border-box;
        overflow-x: hidden;
    }
    
    .sidebar.collapsed + .main {
        width: calc(100% - 100px);
    }
    
    @media (max-width: 1100px) {
        .main {
            width: 100% !important;
        }
        .sidebar.collapsed + .main {
            width: 100% !important;
        }
    }
    
    /* Responsive Tables */
    .table-responsive-wrapper,
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }
    
    @media (max-width: 768px) {
        table {
            min-width: 500px;
            font-size: 12px;
        }
        th, td {
            padding: 8px 10px;
        }
        .table-responsive-wrapper {
            scrollbar-width: thin;
        }
        .table-responsive-wrapper::-webkit-scrollbar {
            height: 6px;
        }
        .table-responsive-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .table-responsive-wrapper::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
    }
    
    @media (max-width: 576px) {
        table {
            min-width: 450px;
            font-size: 11px;
        }
        th, td {
            padding: 6px 8px;
        }
    }
    
    /* Responsive Forms */
    @media (max-width: 768px) {
        .form-group {
            margin-bottom: 16px;
        }
        .form-control,
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        input[type="time"],
        input[type="number"],
        select,
        textarea {
            font-size: 14px;
            padding: 10px 12px;
            width: 100%;
            box-sizing: border-box;
        }
        .btn {
            padding: 10px 16px;
            font-size: 14px;
            width: 100%;
        }
        .form-row {
            flex-direction: column;
            gap: 12px;
        }
        .form-row > * {
            width: 100% !important;
        }
    }
    
    @media (max-width: 576px) {
        .form-control,
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        input[type="time"],
        input[type="number"],
        select,
        textarea {
            font-size: 13px;
            padding: 8px 10px;
        }
        .btn {
            padding: 8px 14px;
            font-size: 13px;
        }
    }
    
    /* Responsive Modals */
    @media (max-width: 768px) {
        .modal-dialog {
            margin: 10px;
            max-width: calc(100% - 20px);
            width: calc(100% - 20px);
        }
        .modal-content {
            padding: 20px 16px;
        }
        .modal-body {
            padding: 16px;
        }
        .modal-header {
            padding: 16px;
        }
        .modal-footer {
            padding: 12px 16px;
        }
    }
    
    @media (max-width: 576px) {
        .modal-dialog {
            margin: 5px;
            max-width: calc(100% - 10px);
            width: calc(100% - 10px);
        }
        .modal-content {
            padding: 16px 12px;
        }
        .modal-body {
            padding: 12px;
        }
        .modal-header {
            padding: 12px;
        }
        .modal-footer {
            padding: 10px 12px;
            flex-direction: column;
            gap: 8px;
        }
        .modal-footer .btn {
            width: 100%;
            margin: 0;
        }
    }
    
    /* Shared Page Title/Header (staff pages) */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .page-title {
        font-size: 2.25rem;
        font-weight: 700;
        color: #000000;
        letter-spacing: 0;
        margin: 0 0 32px 0;
        word-wrap: break-word;
    }

    .page-header .page-title {
        margin: 0;
    }

    .page-subtitle {
        color: #6b7280;
        font-size: 1rem;
        margin-top: 0.5rem;
        margin-bottom: 0;
    }
    
    .page-title.d-flex {
        flex-wrap: wrap;
        gap: 12px;
    }
    
    @media (max-width: 768px) {
        .page-title {
            font-size: 1.5rem !important;
        }
        .page-title.d-flex {
            flex-direction: column;
            align-items: flex-start !important;
        }
        .page-title.d-flex .btn {
            width: 100%;
            margin-top: 8px;
        }
    }
    
    @media (max-width: 576px) {
        .page-title {
            font-size: 1.25rem !important;
        }
    }
    
    /* Responsive Action Buttons */
    .action-buttons {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    @media (max-width: 576px) {
        .action-buttons {
            flex-direction: column;
            width: 100%;
        }
        .action-buttons .btn,
        .action-buttons .action-btn {
            width: 100%;
            justify-content: center;
        }
    }
    
    /* Responsive Cards */
    .card,
    .table-card {
        width: 100%;
        box-sizing: border-box;
    }
    
    @media (max-width: 768px) {
        .card,
        .table-card {
            padding: 16px 12px !important;
        }
    }
    
    @media (max-width: 576px) {
        .card,
        .table-card {
            padding: 12px 8px !important;
        }
    }
    
    /* Utility Classes */
    @media (max-width: 768px) {
        .hide-mobile {
            display: none !important;
        }
        .show-mobile {
            display: block !important;
        }
    }
    
    @media (min-width: 769px) {
        .show-mobile {
            display: none !important;
        }
    }
    
    /* Global Box Sizing */
    * {
        box-sizing: border-box;
    }
</style>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>
<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()" aria-label="Toggle menu">
    &#9776;
</button>

<div class="sidebar" id="sidebar">
    <div>
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 32px 24px 24px 24px;">
            <img src="../assets/images/tmmp-logo.png" class="sidebar-logo" style="margin-bottom: 16px;" alt="Trece Martires Memorial Park">
            <div class="logo">Trece Martires<br>Memorial Park</div>
        </div>
        <button class="collapse-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('collapsed')" aria-label="Toggle sidebar">&#9776;</button>
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
            <a href="staff_dashboard.php"<?php echo $current_page === 'staff_dashboard.php' ? ' class="active"' : ''; ?>><i class="bx bx-home-alt-2"></i>Home</a>
            <a href="maps.php"<?php echo $current_page === 'maps.php' ? ' class="active"' : ''; ?>><i class="bx bx-map-alt"></i>Maps</a>
            <a href="calendar.php"<?php echo $current_page === 'calendar.php' ? ' class="active"' : ''; ?>><i class="bx bx-calendar"></i>Calendar</a>
            <a href="deceased_records.php"<?php echo in_array($current_page, ['deceased_records.php','add_deceased_record.php','edit_record.php']) ? ' class="active"' : ''; ?>><i class="bx bx-book-alt"></i>Deceased Records</a>
            <a href="plots.php"<?php echo in_array($current_page, ['plots.php','existing_plots.php','add_plots.php','add_plot.php','edit_plot.php','plot_details.php','section_layout.php','sections.php']) ? ' class="active"' : ''; ?>><i class="bx bx-grid-alt"></i>Plots</a>
            <a href="contracts.php"<?php echo in_array($current_page, ['contracts.php','contract_management.php','contract_status_checker.php']) ? ' class="active"' : ''; ?>><i class="bx bx-file"></i>Renewal Tracking</a>
        </nav>
    </div>
    <div class="sidebar-bottom">
        <a href="profile.php" class="profile-icon<?php echo $current_page === 'profile.php' ? ' active' : ''; ?>"><i class='bx bxs-user'></i></a>
    </div>
</div>

