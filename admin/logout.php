<?php
require_once '../config/database.php';
require_once 'includes/auth_check.php';
if (isset($_SESSION['user_id'])) {
    log_action('Info', 'User logged out', $_SESSION['user_id']);
}
session_destroy();
header('Location: ../login.php');
exit();
?> 