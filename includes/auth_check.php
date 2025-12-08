<?php
session_start();

// Check if staff session exists and is valid
if (!isset($_SESSION['staff_session']) || !isset($_SESSION['staff_user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Check if session has expired (30 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();
// No closing PHP tag to prevent whitespace issues