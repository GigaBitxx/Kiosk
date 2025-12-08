<?php
/**
 * Security Configuration for Admin Registration
 * 
 * 
 * 
 */

// Admin Registration Security Settings
define('ADMIN_REGISTRATION_ENABLED', true); // Set to false to completely disable admin self-registration
define('ADMIN_REGISTRATION_CODE', 'TMMP-ADMIN-2025'); // FALLBACK: Used only if not set in database
define('ADMIN_REGISTRATION_REQUIRE_APPROVAL', true); // Require existing admin approval
define('MAX_ADMIN_REGISTRATION_ATTEMPTS', 3); // Max attempts per IP per hour
define('ADMIN_REGISTRATION_ATTEMPT_WINDOW', 3600); // 1 hour in seconds

if (!defined('MAX_STANDARD_ADMINS')) {
    define('MAX_STANDARD_ADMINS', 3); // Head admin not included in this limit
}
if (!defined('HEAD_ADMIN_USERNAME')) {
    define('HEAD_ADMIN_USERNAME', 'TMMP-ADMIN');
}
if (!defined('HEAD_ADMIN_PASSWORD')) {
    define('HEAD_ADMIN_PASSWORD', 'TMMP-EDWIN');
}
if (!defined('HEAD_ADMIN_FULL_NAME')) {
    define('HEAD_ADMIN_FULL_NAME', 'Head Administrator');
}

// Get admin registration code from database (preferred) or fallback to constant
function get_admin_registration_code($conn) {
    $query = "SELECT admin_registration_code FROM settings WHERE id = 1 LIMIT 1";
    $result = mysqli_query($conn, $query);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        // Return database value if set, otherwise fallback to constant
        return !empty($row['admin_registration_code']) ? $row['admin_registration_code'] : ADMIN_REGISTRATION_CODE;
    }
    // Fallback to constant if database query fails
    return ADMIN_REGISTRATION_CODE;
}

// IP-based rate limiting
function check_admin_registration_rate_limit($conn, $ip_address) {
    $window_start = time() - ADMIN_REGISTRATION_ATTEMPT_WINDOW;
    $ip_address_escaped = mysqli_real_escape_string($conn, $ip_address);
    
    $query = "SELECT COUNT(*) as attempt_count 
              FROM admin_registration_attempts 
              WHERE ip_address = '$ip_address_escaped' AND attempt_time > $window_start";
    $result = mysqli_query($conn, $query);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['attempt_count'] < MAX_ADMIN_REGISTRATION_ATTEMPTS;
    }
    return true; // Allow if table doesn't exist yet
}

// Log admin registration attempt
function log_admin_registration_attempt($conn, $ip_address, $username, $success, $reason = '') {
    $ip_address_escaped = mysqli_real_escape_string($conn, $ip_address);
    $username_escaped = mysqli_real_escape_string($conn, $username);
    $reason_escaped = mysqli_real_escape_string($conn, $reason);
    $success_int = $success ? 1 : 0;
    $timestamp = time();
    
    $query = "INSERT INTO admin_registration_attempts 
              (ip_address, username, success, reason, attempt_time) 
              VALUES ('$ip_address_escaped', '$username_escaped', $success_int, '$reason_escaped', $timestamp)";
    mysqli_query($conn, $query);
}

// Get client IP address
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, 
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Verify admin registration code
function verify_admin_code($provided_code, $conn = null) {
    // Get the actual code from database or constant
    $actual_code = ADMIN_REGISTRATION_CODE;
    if ($conn) {
        $actual_code = get_admin_registration_code($conn);
    }
    return hash_equals($actual_code, $provided_code);
}

// Check if admin registration requires approval
function requires_admin_approval() {
    return ADMIN_REGISTRATION_REQUIRE_APPROVAL;
}

function users_table_has_head_admin_flag($conn, $force_refresh = false) {
    static $cache = null;
    if ($force_refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_head_admin'");
    $cache = $result && mysqli_num_rows($result) > 0;
    return $cache;
}

function ensure_head_admin_schema($conn) {
    if (users_table_has_head_admin_flag($conn)) {
        return true;
    }
    $alter_sql = "ALTER TABLE users ADD COLUMN is_head_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role";
    if (!mysqli_query($conn, $alter_sql)) {
        error_log('Failed to add is_head_admin column: ' . mysqli_error($conn));
        return false;
    }
    users_table_has_head_admin_flag($conn, true);
    return true;
}

function ensure_head_admin_account($conn) {
    if (!ensure_head_admin_schema($conn)) {
        return false;
    }

    $username = HEAD_ADMIN_USERNAME;
    $password = HEAD_ADMIN_PASSWORD;
    $full_name = HEAD_ADMIN_FULL_NAME;

    $head_stmt = $conn->prepare("SELECT user_id, username, password, role, full_name FROM users WHERE is_head_admin = 1 LIMIT 1");
    if ($head_stmt && $head_stmt->execute()) {
        $result = $head_stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $head_user_id = (int)$row['user_id'];
            $needs_update = ($row['username'] !== $username) ||
                            !password_verify($password, $row['password']) ||
                            $row['role'] !== 'admin';
            if ($needs_update) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                // Only update username, password, and role - preserve user's custom full_name
                $update = $conn->prepare("UPDATE users SET username = ?, password = ?, role = 'admin' WHERE user_id = ?");
                if ($update) {
                    $update->bind_param('ssi', $username, $new_hash, $head_user_id);
                    $update->execute();
                }
            }
            $conn->query("UPDATE users SET is_head_admin = 0 WHERE user_id != $head_user_id AND is_head_admin = 1");
            return ensure_head_admin_primary_slot($conn, $head_user_id);
        }
    }

    $user_stmt = $conn->prepare("SELECT user_id, password FROM users WHERE username = ? LIMIT 1");
    if ($user_stmt) {
        $user_stmt->bind_param('s', $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_result && $user_result->num_rows > 0) {
            $user = $user_result->fetch_assoc();
            $head_user_id = (int)$user['user_id'];
            if (!password_verify($password, $user['password'])) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $pwd_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                if ($pwd_stmt) {
                    $pwd_stmt->bind_param('si', $new_hash, $head_user_id);
                    $pwd_stmt->execute();
                }
            }
            $promote_stmt = $conn->prepare("UPDATE users SET is_head_admin = 1, role = 'admin', full_name = ? WHERE user_id = ?");
            if ($promote_stmt) {
                $promote_stmt->bind_param('si', $full_name, $head_user_id);
                $promote_stmt->execute();
            }
            $conn->query("UPDATE users SET is_head_admin = 0 WHERE user_id != $head_user_id AND is_head_admin = 1");
            return ensure_head_admin_primary_slot($conn, $head_user_id);
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert_stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, is_head_admin) VALUES (?, ?, ?, 'admin', 1)");
    if ($insert_stmt) {
        $insert_stmt->bind_param('sss', $username, $hash, $full_name);
        if ($insert_stmt->execute()) {
            $head_user_id = mysqli_insert_id($conn);
            return ensure_head_admin_primary_slot($conn, $head_user_id);
        }
    }
    return false;
}

function table_exists($conn, $table) {
    $table_safe = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table_safe'");
    $exists = $result && mysqli_num_rows($result) > 0;
    if ($result) {
        mysqli_free_result($result);
    }
    return $exists;
}

function update_user_id_references($conn, $oldId, $newId) {
    $oldId = (int)$oldId;
    $newId = (int)$newId;
    if ($oldId === $newId || $oldId === 0) {
        return;
    }
    $mappings = [
        'logs' => 'user_id',
        'reservations' => 'reserved_by',
        'archived_deceased_records' => 'archived_by',
        'events' => 'created_by',
        'pending_admin_registrations' => 'approved_by'
    ];
    foreach ($mappings as $table => $column) {
        if (table_exists($conn, $table)) {
            mysqli_query($conn, "UPDATE $table SET $column = $newId WHERE $column = $oldId");
        }
    }
}

function get_unused_user_id($conn) {
    $result = mysqli_query($conn, "SELECT MAX(user_id) AS max_id FROM users");
    $max = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $max = (int)$row['max_id'];
    }
    if ($result) {
        mysqli_free_result($result);
    }
    return $max + 1000;
}

function reset_user_auto_increment($conn) {
    $result = mysqli_query($conn, "SELECT MAX(user_id) AS max_id FROM users");
    $max = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $max = (int)$row['max_id'];
    }
    if ($result) {
        mysqli_free_result($result);
    }
    $next = max(2, $max + 1);
    mysqli_query($conn, "ALTER TABLE users AUTO_INCREMENT = $next");
}

function db_begin_transaction($conn) {
    if (function_exists('mysqli_begin_transaction')) {
        mysqli_begin_transaction($conn);
    } else {
        mysqli_query($conn, "START TRANSACTION");
    }
}

function ensure_head_admin_primary_slot($conn, $headUserId = null) {
    if ($headUserId === null) {
        $res = mysqli_query($conn, "SELECT user_id FROM users WHERE is_head_admin = 1 LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) {
            return false;
        }
        $row = mysqli_fetch_assoc($res);
        $headUserId = (int)$row['user_id'];
        mysqli_free_result($res);
    }

    $targetId = 1;
    if ($headUserId === $targetId) {
        reset_user_auto_increment($conn);
        return $targetId;
    }

    $swapId = null;
    db_begin_transaction($conn);
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");

    $occupantRes = mysqli_query($conn, "SELECT user_id FROM users WHERE user_id = $targetId LIMIT 1");
    if ($occupantRes && mysqli_num_rows($occupantRes) > 0) {
        $swapId = get_unused_user_id($conn);
        mysqli_query($conn, "UPDATE users SET user_id = $swapId WHERE user_id = $targetId");
        update_user_id_references($conn, $targetId, $swapId);
    }
    if ($occupantRes) {
        mysqli_free_result($occupantRes);
    }

    mysqli_query($conn, "UPDATE users SET user_id = $targetId WHERE user_id = $headUserId");
    update_user_id_references($conn, $headUserId, $targetId);

    if ($swapId !== null) {
        mysqli_query($conn, "UPDATE users SET user_id = $headUserId WHERE user_id = $swapId");
        update_user_id_references($conn, $swapId, $headUserId);
    }

    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    mysqli_commit($conn);
    reset_user_auto_increment($conn);
    return $targetId;
}

// Create pending admin registration (requires approval)
function create_pending_admin_registration($conn, $username, $hashed_password, $full_name, $admin_code, $ip_address) {
    // Check if username already exists in pending registrations
    $username_escaped = mysqli_real_escape_string($conn, $username);
    $check_query = "SELECT id FROM pending_admin_registrations WHERE username = '$username_escaped' AND status = 'pending'";
    $check_result = mysqli_query($conn, $check_query);
    
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        return false; // Username already has a pending registration
    }
    
    $hashed_password_escaped = mysqli_real_escape_string($conn, $hashed_password);
    $full_name_escaped = mysqli_real_escape_string($conn, $full_name);
    $code_hash = password_hash($admin_code, PASSWORD_DEFAULT);
    $code_hash_escaped = mysqli_real_escape_string($conn, $code_hash);
    $ip_address_escaped = mysqli_real_escape_string($conn, $ip_address);
    $timestamp = date('Y-m-d H:i:s');
    
    $query = "INSERT INTO pending_admin_registrations 
              (username, password_hash, full_name, admin_code_hash, ip_address, created_at) 
              VALUES ('$username_escaped', '$hashed_password_escaped', '$full_name_escaped', '$code_hash_escaped', '$ip_address_escaped', '$timestamp')";
    return mysqli_query($conn, $query);
}

// Check if existing admin exists (for approval requirement)
function has_existing_admin($conn) {
    $query = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
    $result = mysqli_query($conn, $query);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['count'] > 0;
    }
    return false;
}

// Get current admin count
function get_admin_count($conn) {
    $where = "role = 'admin'";
    if (users_table_has_head_admin_flag($conn)) {
        $where .= " AND (is_head_admin = 0 OR is_head_admin IS NULL)";
    }
    $query = "SELECT COUNT(*) as count FROM users WHERE $where";
    $result = mysqli_query($conn, $query);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return (int)$row['count'];
    }
    return 0;
}

// Check if admin limit is reached (head admin not counted)
function is_admin_limit_reached($conn, $max_admins = MAX_STANDARD_ADMINS) {
    $current_count = get_admin_count($conn);
    return $current_count >= $max_admins;
}
?>

