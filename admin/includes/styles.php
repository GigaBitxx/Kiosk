<style>
    :root {
        --sidebar-width: 220px;
        --sidebar-collapsed-width: 60px;
    }
    body { 
        margin: 0; 
        padding: 0; 
        font-family: 'Raleway', 'Helvetica Neue', sans-serif; 
        background: #f5f5f5;
        overflow-x: hidden;
    }
    .layout { 
        display: flex; 
        min-height: 100vh;
        width: 100%;
        overflow-x: hidden;
    }
    .main {
        flex: 1;
        padding: 48px 40px 32px 40px;
        background: #f5f5f5;
        margin-left: var(--sidebar-width);
        width: calc(100% - var(--sidebar-width));
        box-sizing: border-box;
        overflow-x: hidden;
        transition: margin-left 0.2s ease, width 0.2s ease, padding 0.3s ease;
    }
    .sidebar.collapsed + .main {
        margin-left: var(--sidebar-collapsed-width);
        width: calc(100% - var(--sidebar-collapsed-width));
    }
    
    @media (max-width: 1100px) {
        .main {
            width: 100% !important;
            margin-left: 0 !important;
            padding: 24px 20px !important;
        }
        .sidebar.collapsed + .main {
            width: 100% !important;
            margin-left: 0 !important;
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
    /* Sidebar styles moved to external CSS file */
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
        .page-title.d-flex {
            flex-direction: column;
            align-items: flex-start !important;
        }
        .page-title.d-flex .btn {
            width: 100%;
            margin-top: 8px;
        }
    }
    .table-card {
        background: #fff;
        border-radius: 16px;
        padding: 32px 24px 24px 24px;
        margin-bottom: 32px;
        box-shadow: none;
        border: 1px solid #e0e0e0;
        max-width: 100%;
        width: 100%;
    }
    .table-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 16px;
        color: #222;
    }
    .table-responsive-wrapper {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Better table scrolling on mobile */
    @media (max-width: 768px) {
        .table-responsive-wrapper {
            -webkit-overflow-scrolling: touch;
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
        .table-responsive-wrapper::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0;
        min-width: 600px;
    }
    
    @media (max-width: 768px) {
        table {
            min-width: 500px;
        }
    }
    
    @media (max-width: 576px) {
        table {
            min-width: 450px;
        }
    }
    th, td {
        padding: 14px 18px;
        text-align: left;
        font-size: 15px;
    }
    th { background: #fafafa; color: #333; border-bottom: 1px solid #e0e0e0; }
    tr { background: #fff; }
    tr:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 8px;
        font-size: 13px;
        color: #fff;
    }
    .badge.success { background: #f8c471; color: #222; }
    .badge.warning { background: #76d7c4; color: #222; }
    .badge.danger { background: #e26a2c; }
    /* Consistent Action Buttons System */
    .action-buttons {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    @media (max-width: 576px) {
        .action-buttons {
            flex-direction: column;
            width: 100%;
        }
        .action-buttons .action-btn,
        .action-buttons .btn-action {
            width: 100%;
            justify-content: center;
        }
    }
    
    .action-btn,
    .btn-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    
    /* Action button variants */
    .action-btn.view,
    .btn-action.btn-view {
        background: var(--gray-100, #f3f4f6);
        color: var(--gray-700, #374151);
    }
    .action-btn.view:hover,
    .btn-action.btn-view:hover {
        background: var(--gray-200, #e5e7eb);
        color: var(--gray-800, #1f2937);
    }
    
    .action-btn.edit,
    .btn-action.btn-edit {
        background: #2b4c7e;
        color: white;
    }
    .action-btn.edit:hover,
    .btn-action.btn-edit:hover {
        background: #1f3659;
        color: white;
    }
    
    .action-btn.approve,
    .btn-action.btn-approve,
    .btn-approve {
        background: #10b981;
        color: white;
    }
    .action-btn.approve:hover,
    .btn-action.btn-approve:hover,
    .btn-approve:hover {
        background: #059669;
        color: white;
    }
    
    .action-btn.reject,
    .btn-action.btn-reject,
    .btn-reject {
        background: #ef4444;
        color: white;
    }
    .action-btn.reject:hover,
    .btn-action.btn-reject:hover,
    .btn-reject:hover {
        background: #dc2626;
        color: white;
    }
    
    /* Bootstrap button overrides for consistency */
    .btn.btn-sm.btn-success,
    .btn.btn-sm.btn-approve {
        background: #10b981;
        border-color: #10b981;
        color: white;
        padding: 0.5rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 0.5rem;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        transition: all 0.2s ease;
    }
    .btn.btn-sm.btn-success:hover,
    .btn.btn-sm.btn-approve:hover {
        background: #059669;
        border-color: #059669;
        color: white;
    }
    
    .btn.btn-sm.btn-outline-danger,
    .btn.btn-sm.btn-reject {
        background: #ef4444;
        border-color: #ef4444;
        color: white;
        padding: 0.5rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 0.5rem;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        transition: all 0.2s ease;
    }
    .btn.btn-sm.btn-outline-danger:hover,
    .btn.btn-sm.btn-reject:hover {
        background: #dc2626;
        border-color: #dc2626;
        color: white;
    }
    
    /* Table action column alignment */
    table td:last-child,
    table th:last-child {
        text-align: center;
    }
    table td .action-buttons {
        justify-content: center;
    }
    
    /* Responsive Styles for Large Screens */
    @media (min-width: 1400px) {
        .main { 
            padding: 48px 60px 32px 60px !important; 
        }
        .table-card { 
            padding: 40px 32px 32px 32px; 
        }
        table {
            font-size: 16px;
        }
        th, td {
            padding: 16px 20px;
        }
        .page-title {
            font-size: 2.25rem;
        }
        .table-title {
            font-size: 20px;
        }
    }
    
    @media (min-width: 1600px) {
        .main { 
            padding: 48px 80px 32px 80px !important; 
        }
        .table-card { 
            padding: 48px 40px 40px 40px; 
        }
        .page-title {
            font-size: 2.5rem;
        }
        .table-title {
            font-size: 22px;
        }
        table {
            font-size: 17px;
        }
        th, td {
            padding: 18px 24px;
        }
    }
    
    @media (min-width: 1920px) {
        .main { 
            padding: 48px 120px 32px 120px !important; 
        }
        .table-card { 
            padding: 56px 48px 48px 48px; 
        }
        .page-title {
            font-size: 2.75rem;
        }
        .table-title {
            font-size: 24px;
        }
        table {
            font-size: 18px;
        }
        th, td {
            padding: 20px 28px;
        }
    }
    
    /* Responsive Styles for All Admin Pages */
    @media (max-width: 1200px) {
        .main { 
            padding: 40px 32px 24px 32px !important; 
        }
        .table-card { 
            padding: 28px 20px 20px 20px; 
        }
    }
    
    @media (max-width: 1100px) {
        .main { 
            padding: 24px 20px !important; 
            margin-left: 0 !important;
        }
        .sidebar.collapsed + .main,
        .sidebar + .main {
            margin-left: 0 !important;
        }
        .table-card { 
            padding: 20px 16px 16px 16px; 
        }
        .page-title {
            font-size: 1.75rem;
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
        .table-card {
            padding: 18px 14px 14px 14px;
        }
        .page-title {
            font-size: 1.5rem;
        }
        .table-title {
            font-size: 16px;
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
        }
        .table-card { 
            padding: 16px 12px 12px 12px; 
        }
        .page-title {
            font-size: 1.25rem;
            margin-bottom: 24px;
        }
        .table-title {
            font-size: 15px;
            margin-bottom: 12px;
        }
        table {
            font-size: 12px;
        }
        th, td {
            padding: 8px 10px;
        }
        .action-btn {
            padding: 5px 12px;
            font-size: 12px;
            margin-right: 4px;
        }
    }
    
    @media (max-width: 768px) {
        .main { 
            padding: 16px 12px !important; 
            margin-left: 0 !important;
        }
        .sidebar + .main,
        .sidebar.collapsed + .main {
            margin-left: 0 !important;
        }
        .table-card { 
            padding: 16px 12px 12px 12px; 
        }
        .page-title {
            font-size: 1.25rem;
            margin-bottom: 24px;
        }
        .table-title {
            font-size: 15px;
            margin-bottom: 12px;
        }
        table {
            font-size: 12px;
        }
        th, td {
            padding: 8px 10px;
        }
        .action-btn {
            padding: 5px 12px;
            font-size: 12px;
            margin-right: 4px;
        }
    }
    
    @media (max-width: 700px) {
        .main { 
            margin-left: 0 !important; 
            padding: 16px 12px !important; 
        }
        .sidebar + .main,
        .sidebar.collapsed + .main { 
            margin-left: 0 !important; 
        }
        .table-card { 
            padding: 12px 10px 10px 10px; 
        }
        .page-title {
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        table {
            font-size: 11px;
        }
        th, td {
            padding: 6px 8px;
        }
    }
    
    @media (max-width: 576px) {
        .main {
            padding: 12px 8px !important;
        }
        .table-card {
            padding: 10px 8px 8px 8px;
        }
        .page-title {
            font-size: 1rem;
            margin-bottom: 16px;
        }
        .table-title {
            font-size: 14px;
        }
        table {
            font-size: 10px;
        }
        th, td {
            padding: 5px 6px;
        }
        .action-btn {
            padding: 4px 10px;
            font-size: 11px;
            margin-right: 3px;
        }
        .badge {
            font-size: 10px;
            padding: 2px 8px;
        }
    }
    
    @media (max-width: 400px) {
        .main {
            padding: 10px 6px !important;
        }
        .table-card {
            padding: 8px 6px 6px 6px;
        }
        table {
            font-size: 9px;
            min-width: 500px;
        }
        th, td {
            padding: 4px 5px;
        }
    }
    
    /* Responsive Form Elements */
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
        .form-group {
            margin-bottom: 14px;
        }
    }
    
    /* Responsive Cards and Containers */
    @media (max-width: 768px) {
        .card,
        .table-card {
            border-radius: 12px;
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
    /* Sidebar-bottom styles moved to external CSS file */
    
    /* Notification bubble */
    .notification-bubble {
        position: fixed;
        top: 24px;
        right: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 22px;
        border-radius: 12px;
        color: #fff;
        font-weight: 500;
        font-size: 15px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        z-index: 2000;
        opacity: 0;
        transform: translateY(-20px);
        pointer-events: none;
        transition: opacity 0.25s ease, transform 0.25s ease;
        max-width: 500px;
        word-wrap: break-word;
    }
    
    @media (max-width: 768px) {
        .notification-bubble {
            top: 16px;
            right: 16px;
            left: 16px;
            max-width: calc(100% - 32px);
            padding: 12px 16px;
            font-size: 14px;
        }
    }
    
    @media (max-width: 576px) {
        .notification-bubble {
            top: 12px;
            right: 12px;
            left: 12px;
            max-width: calc(100% - 24px);
            padding: 10px 14px;
            font-size: 13px;
        }
    }
    
    .notification-bubble.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    .notification-bubble.hide {
        opacity: 0;
        transform: translateY(-20px);
    }
    
    .success-notification {
        background: linear-gradient(135deg, #00b894, #00a184);
    }
    
    .error-notification {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
    }
    
    .notification-bubble i {
        font-size: 20px;
    }
    
    .notification-bubble span {
        display: inline-block;
    }
    
    /* Utility classes for responsive design */
    @media (max-width: 768px) {
        .hide-mobile {
            display: none !important;
        }
        .show-mobile {
            display: block !important;
        }
        .text-center-mobile {
            text-align: center !important;
        }
    }
    
    @media (min-width: 769px) {
        .show-mobile {
            display: none !important;
        }
    }
    
    /* Prevent horizontal scroll on all screen sizes */
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    * {
        box-sizing: border-box;
    }
</style> 