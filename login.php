<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/database.php';
require_once 'admin/includes/logging.php';
require_once 'config/security.php';
require_once 'config/password_reset_helper.php';

define('LOGIN_ATTEMPT_WINDOW_SECONDS', 900);

if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

function normalize_login_username($username) {
    return strtolower(trim($username));
}

function get_login_attempt_count($key) {
    if (empty($key) || !isset($_SESSION['login_attempts'][$key])) {
        return 0;
    }
    $entry = $_SESSION['login_attempts'][$key];
    if (!isset($entry['timestamp']) || (time() - $entry['timestamp']) > LOGIN_ATTEMPT_WINDOW_SECONDS) {
        unset($_SESSION['login_attempts'][$key]);
        return 0;
    }
    return (int)$entry['count'];
}

function increment_login_attempt($key) {
    if (empty($key)) {
        return 1;
    }
    $count = get_login_attempt_count($key) + 1;
    $_SESSION['login_attempts'][$key] = [
        'count' => $count,
        'timestamp' => time()
    ];
    return $count;
}

function reset_login_attempts($key) {
    if (!empty($key) && isset($_SESSION['login_attempts'][$key])) {
        unset($_SESSION['login_attempts'][$key]);
    }
}

$headAdminId = ensure_head_admin_account($conn);
if ($headAdminId && !empty($_SESSION['is_head_admin']) && !empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $_SESSION['user_id'] = $headAdminId;
    $_SESSION['admin_user_id'] = $headAdminId;
}

$system_notice = $_SESSION['auth_notice'] ?? null;
if (isset($_SESSION['auth_notice'])) {
    unset($_SESSION['auth_notice']);
}

// Retrieve form data and error from session (from previous POST redirect)
$error = $_SESSION['login_error'] ?? null;
$saved_username = $_SESSION['login_username'] ?? '';
$saved_role = $_SESSION['login_role'] ?? 'staff';

// Clear session data after retrieving
if (isset($_SESSION['login_error'])) {
    unset($_SESSION['login_error']);
}
if (isset($_SESSION['login_username'])) {
    unset($_SESSION['login_username']);
}
if (isset($_SESSION['login_role'])) {
    unset($_SESSION['login_role']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $normalized_username = normalize_login_username($username);
    $password = $_POST['password'];
    $login_role = isset($_POST['login_role']) && $_POST['login_role'] === 'admin' ? 'admin' : 'staff';

    $stmt = $conn->prepare("SELECT user_id, username, password, role, full_name, 
                                   IFNULL(is_head_admin, 0) AS is_head_admin 
                            FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $user_is_admin = $user['role'] === 'admin';

            if ($login_role === 'admin' && !$user_is_admin) {
                log_action('Warning', 'Login failed (role mismatch, staff tried admin) for user: ' . $username);
                $_SESSION['login_error'] = "Invalid role selection. Please choose the correct role.";
                $_SESSION['login_username'] = $username;
                $_SESSION['login_role'] = $login_role;
                header('Location: login.php');
                exit();
            } elseif ($login_role === 'staff' && $user_is_admin) {
                log_action('Warning', 'Login failed (role mismatch, admin tried staff) for user: ' . $username);
                $_SESSION['login_error'] = "Invalid role selection. Please choose the correct role.";
                $_SESSION['login_username'] = $username;
                $_SESSION['login_role'] = $login_role;
                header('Location: login.php');
                exit();
            } else {
                reset_login_attempts($normalized_username);
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['is_head_admin'] = !empty($user['is_head_admin']);
                
                if ($user_is_admin) {
                    $_SESSION['admin_session'] = true;
                    $_SESSION['admin_user_id'] = $user['user_id'];
                } else {
                    $_SESSION['staff_session'] = true;
                    $_SESSION['staff_user_id'] = $user['user_id'];
                }
                
                log_action('Info', 'Login success for user: ' . $username, $user['user_id']);
                if ($user_is_admin) {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: staff/staff_dashboard.php');
                }
                exit();
            }
        } else {
            $attempts = increment_login_attempt($normalized_username);
            log_action('Warning', 'Login failed (wrong password) for user: ' . $username);
            if ($attempts >= 3) {
                $_SESSION['login_error'] = "Password incorrect. Reset password?";
            } else {
                $_SESSION['login_error'] = "Login failed. Please check your credentials.";
            }
            $_SESSION['login_username'] = $username;
            $_SESSION['login_role'] = $login_role;
            header('Location: login.php');
            exit();
        }
    } else {
        log_action('Warning', 'Login failed (user not found) for username: ' . $username);
        $_SESSION['login_error'] = "Account not found. If this is a mistake, contact admin.";
        $_SESSION['login_username'] = $username;
        $_SESSION['login_role'] = $login_role;
        header('Location: login.php');
        exit();
    }
} else {
    // Use saved values from session if available, otherwise default
    $selected_role = $saved_role;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600&family=Playfair+Display:wght@400;600;700&family=Lora:wght@400;600;700&family=Merriweather:wght@400;700&family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@400;600;700&family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            overflow: hidden;
        }
        .container {
            display: flex;
            height: 100vh;
        }
        .left {
            flex: 1;
            background: #f4f6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .right {
            flex: 1;
            background-image: url('assets/images/P2.jpg');
            background-size: cover;
            background-position: center;
            position: relative;
        }
        .right::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(7, 17, 26, 0.4) 0%, rgba(7, 17, 26, 0.6) 100%);
        }
        .form-box {
            width: 100%;
            max-width: 420px;
            padding-top: 2rem;
        }
        .logo-header {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .logo-header img {
            height: 64px;
            width: auto;
        }
        .logo-text {
            font-family: 'Cormorant Garamond', 'Cinzel', serif;
            font-size: 2.2rem; /* Slightly larger for better visibility */
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            line-height: 1.2;
        }
        .hero-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: flex-start; /* Position content toward the top */
            justify-content: center; /* Keep it centered horizontally */
            padding-top: 40%; /* Position at 40% from the top */
            z-index: 1;
        }
        h2 {
            /* Alternative font options - uncomment one to use:
               font-family: 'Lora', 'Playfair Display', serif; - Elegant serif
               font-family: 'Merriweather', 'Lora', serif; - Classic readable serif
               font-family: 'Cormorant Garamond', 'Cinzel', serif; - Refined serif
               font-family: 'Montserrat', 'Helvetica Neue', sans-serif; - Modern sans-serif
               font-family: 'Raleway', 'Helvetica Neue', sans-serif; - Clean sans-serif
            */
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            font-weight: 700;
            font-size: 2.7rem; /* Slightly larger */
            margin-bottom: 0.5rem;
            color: #1d2a38;
            letter-spacing: 0.02em;
        }
        .subtitle {
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            color: #5b6c86;
            font-size: 0.9rem; /* Slightly larger */
            margin-bottom: 2.5rem;
            letter-spacing: 0.05em;
        }
        .form-group {
            margin-bottom: 2rem;
        }
        label {
            display: block;
            margin-bottom: 0.75rem;
            color: #1d2a38;
            font-size: 1rem; /* Slightly larger */
            font-weight: 600;
            letter-spacing: 0.03em;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            border: 2px solid rgba(15, 23, 42, 0.1);
            background: #ffffff;
            padding: 10px 12px;
            padding-right: 50px;
            font-size: 14px;
            border-radius: 8px;
            outline: none;
            transition: all 0.3s ease;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1d2a38;
            min-height: 40px;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #2b4c7e;
            box-shadow: 0 0 0 3px rgba(43, 76, 126, 0.1);
        }
        .password-wrapper {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #5b6c86;
            font-size: 1.2rem;
            transition: color 0.3s ease;
            z-index: 10;
            background: transparent;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .password-toggle:hover {
            color: #2b4c7e;
        }
        .password-toggle:focus {
            outline: none;
            color: #2b4c7e;
        }
        .btn {
            width: 100%;
            background: #2b4c7e;
            color: #fff;
            border: none;
            padding: 16px;
            font-size: 1.05rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: all 0.3s ease;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
        }
        .btn:hover {
            background: #1f3659;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(43, 76, 126, 0.25);
        }
        .btn:active {
            transform: translateY(0);
        }
        .msg {
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            padding: 14px 18px;
            border-radius: 12px;
            line-height: 1.6;
            transition: opacity 0.35s ease, transform 0.35s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            position: relative;
            animation: slideInDown 0.3s ease;
        }
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .msg.fade-out {
            opacity: 0;
            transform: translateY(-6px);
        }
        .msg.error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffe8e8 100%);
            color: #c44536;
            border: 1.5px solid #fecaca;
            box-shadow: 0 4px 12px rgba(196, 69, 54, 0.12);
        }
        .msg.error::before {
            content: "⚠";
            font-size: 1.2rem;
            line-height: 1;
            flex-shrink: 0;
        }
        .msg.success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #0f5132;
            border: 1.5px solid #badbcc;
            box-shadow: 0 4px 12px rgba(15, 81, 50, 0.12);
        }
        .msg.success::before {
            content: "✓";
            font-size: 1.2rem;
            line-height: 1;
            flex-shrink: 0;
            color: #16a34a;
        }
        .link {
            text-align: center;
            margin-top: 1.5rem;
            color: #5b6c86;
        }
        .link-button {
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            background: none;
            border: none;
            padding: 0;
            color: #2b4c7e;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.8rem;
            text-decoration: none;
        }
        .link-button:hover,
        .link-button:focus {
            color: #1f3659;
            outline: none;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(7, 17, 26, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            z-index: 1000;
            padding: 1.5rem;
        }
        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .password-reset-card {
            background: #fff;
            border-radius: 16px;
            padding: 2rem;
            width: 100%;
            max-width: 420px;
            position: relative;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            animation: slideIn 0.3s ease;
        }
        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 32px;
            height: 32px;
            border: none;
            background: rgba(91, 108, 134, 0.08);
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            color: #1d2a38;
        }
        .modal-close:hover {
            background: rgba(91, 108, 134, 0.18);
        }
        .password-reset-card h3 {
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #1d2a38;
        }
        .password-reset-card p.description {
            color: #5b6c86;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .password-reset-card .form-group {
            margin-bottom: 1.25rem;
        }
        .password-reset-card input,
        .password-reset-card textarea {
            width: 100%;
            border: 1px solid rgba(15, 23, 42, 0.15);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.95rem;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            resize: none;
        }
        .password-reset-card textarea {
            min-height: 90px;
        }
        .password-reset-card .confirm-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            color: #1d2a38;
        }
        .password-reset-card .confirm-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .password-reset-card .submit-btn {
            width: 100%;
            border: none;
            background: #2b4c7e;
            color: #fff;
            padding: 14px;
            border-radius: 8px;
            font-weight: 600;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            font-size: 1rem;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .password-reset-card .submit-btn:hover {
            background: #1f3659;
        }
        .reset-response {
            margin-top: 1rem;
            font-size: 0.85rem;
            padding: 12px 14px;
            border-radius: 8px;
            display: none;
        }
        .reset-response.success {
            background: #e6f4ea;
            color: #0f5132;
            border: 1px solid #badbcc;
        }
        .reset-response.error {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        /* Responsive layout adjustments */
        @media (max-width: 1024px) {
            .left {
                padding: 2rem 1.75rem;
            }
            .form-box {
                max-width: 380px;
            }
        }
        @media (max-width: 900px) {
            body {
                overflow-y: auto;
            }
            .container {
                flex-direction: column-reverse;
                height: auto;
                min-height: 100vh;
            }
            .left,
            .right {
                flex: none;
                width: 100%;
            }
            .right {
                min-height: 40vh;
            }
            .left {
                align-items: center;
                justify-content: center;
                padding: 2rem 1.5rem;
            }
            .form-box {
                max-width: 420px;
                padding-top: 1.5rem;
            }
            h2 {
                font-size: 2rem;
            }
            .subtitle {
                margin-bottom: 2rem;
            }
        }
        @media (max-width: 600px) {
            .left {
                padding: 1.75rem 1.25rem;
            }
            .form-box {
                max-width: 100%;
            }
            h2 {
                font-size: 1.8rem;
            }
            .subtitle {
                font-size: 0.75rem;
            }
            .form-group {
                margin-bottom: 1.5rem;
            }
            input[type="text"],
            input[type="password"] {
                padding: 12px 16px;
                font-size: 0.95rem;
            }
            .btn {
                padding: 14px;
                font-size: 0.95rem;
            }
            .logo-header {
                flex-direction: column;
                text-align: center;
            }
            .logo-text {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="left">
            <div class="form-box">
                <h2>Log In</h2>
                <p class="subtitle">Access your account to manage cemetery operations</p>
                <?php if (!empty($system_notice)): ?>
                    <div class="msg success">
                        <?php echo htmlspecialchars($system_notice); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus value="<?php echo htmlspecialchars($saved_username); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required>
                            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password">
                                <i class='bx bx-hide' id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <div style="display:flex;gap:1rem;">
                            <label style="flex:1;display:flex;align-items:center;gap:0.5rem;padding:0.85rem;border:2px solid rgba(15,23,42,0.1);border-radius:8px;cursor:pointer;">
                                <input type="radio" name="login_role" value="staff" <?php echo $selected_role === 'staff' ? 'checked' : ''; ?> required>
                                <span>Staff</span>
                            </label>
                            <label style="flex:1;display:flex;align-items:center;gap:0.5rem;padding:0.85rem;border:2px solid rgba(15,23,42,0.1);border-radius:8px;cursor:pointer;">
                                <input type="radio" name="login_role" value="admin" <?php echo $selected_role === 'admin' ? 'checked' : ''; ?> required>
                                <span>Admin</span>
                            </label>
                        </div>
                        <small style="display:block;margin-top:0.5rem;color:#5b6c86;font-size:0.75rem;font-family:'Raleway','Helvetica Neue',sans-serif;">
                            Need access? Please contact the admin to create an account.
                        </small>
                    </div>
                    <button type="submit" class="btn">Login</button>
                </form>
                <div class="link">
                    <button type="button" class="link-button" id="forgotPasswordBtn">Forgot Password?</button>
                </div>
            </div>
        </div>
        <div class="right">
            <div class="hero-overlay">
                <div class="logo-header">
                    <img src="assets/images/tmmp-logo.png" alt="Trece Martires Memorial Park Logo">
                    <div class="logo-text">Trece Martires<br>Memorial Park</div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-overlay" id="passwordResetModal" aria-hidden="true">
        <div class="password-reset-card" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle">
            <button type="button" class="modal-close" id="closeResetModal" aria-label="Close">&times;</button>
            <h3 id="resetModalTitle">Request Password Reset</h3>
            <p class="description">Enter your details and we'll help you regain access.</p>
            <form id="passwordResetForm">
                <div class="form-group">
                    <label for="resetUsername">Username</label>
                    <input type="text" id="resetUsername" name="username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="resetFullName">Full Name</label>
                    <input type="text" id="resetFullName" name="full_name" required autocomplete="name">
                </div>
                <div class="form-group">
                    <label for="resetReason">Reason <span style="color:#5b6c86;font-weight:400;">(optional)</span></label>
                    <textarea id="resetReason" name="reason" placeholder="Share any details that can help the admin."></textarea>
                </div>
                <div class="form-group confirm-row">
                    <input type="checkbox" id="resetConfirm" name="confirm_reset" value="1" required>
                    <label for="resetConfirm" style="margin:0;cursor:pointer;">I confirm I can't access my account.</label>
                </div>
                <button type="submit" class="submit-btn" id="resetSubmitBtn">Send Request</button>
            </form>
            <div class="reset-response" id="resetResponse" role="status" aria-live="polite"></div>
        </div>
    </div>
    <script>
        // Password visibility toggle
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordIcon = document.getElementById('passwordIcon');
            const errorMsg = document.querySelector('.msg.error');
            const forgotPasswordBtn = document.getElementById('forgotPasswordBtn');
            const resetModal = document.getElementById('passwordResetModal');
            const closeResetModalBtn = document.getElementById('closeResetModal');
            const resetForm = document.getElementById('passwordResetForm');
            const resetResponse = document.getElementById('resetResponse');
            const resetSubmitBtn = document.getElementById('resetSubmitBtn');
            const resetConfirm = document.getElementById('resetConfirm');
            
            const openResetModal = () => {
                if (!resetModal) return;
                resetModal.classList.add('show');
                document.body.classList.add('modal-open');
            };
            
            const clearResetResponse = () => {
                if (!resetResponse) return;
                resetResponse.textContent = '';
                resetResponse.className = 'reset-response';
                resetResponse.style.display = 'none';
            };
            
            const closeResetModal = () => {
                if (!resetModal) return;
                resetModal.classList.remove('show');
                document.body.classList.remove('modal-open');
                if (resetForm) {
                    resetForm.reset();
                }
                clearResetResponse();
                if (resetSubmitBtn) {
                    resetSubmitBtn.disabled = false;
                    resetSubmitBtn.textContent = 'Submit Request';
                }
            };
            
            const showResetResponse = (type, message) => {
                if (!resetResponse) return;
                resetResponse.textContent = message;
                resetResponse.className = `reset-response ${type}`;
                resetResponse.style.display = 'block';
                if (type === 'success') {
                    setTimeout(() => {
                        closeResetModal();
                    }, 10000);
                }
            };
            
            if (passwordToggle && passwordInput && passwordIcon) {
                passwordToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (passwordInput.type === 'password') {
                        // Show password
                        passwordInput.type = 'text';
                        passwordIcon.classList.remove('bx-hide');
                        passwordIcon.classList.add('bx-show');
                        passwordToggle.setAttribute('aria-label', 'Hide password');
                    } else {
                        // Hide password
                        passwordInput.type = 'password';
                        passwordIcon.classList.remove('bx-show');
                        passwordIcon.classList.add('bx-hide');
                        passwordToggle.setAttribute('aria-label', 'Show password');
                    }
                });
            }

            if (errorMsg) {
                setTimeout(() => {
                    errorMsg.classList.add('fade-out');
                    setTimeout(() => errorMsg.remove(), 400);
                }, 10000);
            }

            if (forgotPasswordBtn && resetModal) {
                forgotPasswordBtn.addEventListener('click', openResetModal);
            }
            if (closeResetModalBtn) {
                closeResetModalBtn.addEventListener('click', closeResetModal);
            }
            if (resetModal) {
                resetModal.addEventListener('click', (event) => {
                    if (event.target === resetModal) {
                        closeResetModal();
                    }
                });
            }
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && resetModal && resetModal.classList.contains('show')) {
                    closeResetModal();
                }
            });

            if (resetForm) {
                resetForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    clearResetResponse();

                    if (!resetConfirm.checked) {
                        showResetResponse('error', 'Please confirm that you cannot access your account.');
                        return;
                    }

                    resetSubmitBtn.disabled = true;
                    resetSubmitBtn.textContent = 'Submitting...';

                    const formData = new FormData(resetForm);
                    formData.set('confirm_reset', resetConfirm.checked ? '1' : '0');

                    try {
                        const response = await fetch('api/request_password_reset.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Unable to submit request.');
                        }
                        showResetResponse('success', data.message);
                        resetForm.reset();
                    } catch (err) {
                        showResetResponse('error', err.message || 'Unable to submit request.');
                    } finally {
                        resetSubmitBtn.disabled = false;
                        resetSubmitBtn.textContent = 'Submit Request';
                    }
                });
            }
        });
    </script>
</body>
</html> 