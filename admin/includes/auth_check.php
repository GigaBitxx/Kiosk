<?php
session_start();

// Prevent cached authenticated pages from showing after back/refresh.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Check if admin session exists and is valid
if (!isset($_SESSION['admin_session']) || !isset($_SESSION['admin_user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Check if user has admin privileges
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../main.php");
    exit();
}

// Check if session has expired (30 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/logging.php';