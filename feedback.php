<?php
require_once 'config/database.php';

// Handle feedback submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    // Check if feedback table exists, if not create it
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'feedback'");
    if (mysqli_num_rows($check_table) == 0) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS feedback (
            feedback_id INT PRIMARY KEY AUTO_INCREMENT,
            full_name VARCHAR(255) NOT NULL,
            contact VARCHAR(255) NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('new', 'read', 'archived') DEFAULT 'new'
        )");
    }
    
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name'] ?? '');
    $contact = mysqli_real_escape_string($conn, $_POST['contact'] ?? '');
    $message = mysqli_real_escape_string($conn, $_POST['message'] ?? '');
    
    if (!empty($full_name) && !empty($message)) {
        $insert_query = "INSERT INTO feedback (full_name, contact, message) VALUES ('$full_name', " . ($contact ? "'$contact'" : "NULL") . ", '$message')";
        if (mysqli_query($conn, $insert_query)) {
            $success_message = 'Thank you for your feedback!';
        } else {
            $error_message = 'Error submitting feedback. Please try again.';
        }
    } else {
        $error_message = 'Please fill in all required fields.';
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
    <link rel="icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href='https://cdn.jsdelivr.net/npm/boxicons@2.0.9/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #1f2b38;
            --accent: #2b4c7e;
            --soft: #f4f6f9;
            --panel: #ffffff;
            --border-soft: rgba(15,23,42,0.1);
        }
        body {
            background: var(--soft);
            min-height: 50vh;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            padding: 2vw 1vw;
            color: var(--primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .feedback-shell {
            width: min(1400px, 95vw);
            background: var(--panel);
            border: 1px solid var(--border-soft);
            box-shadow: 0 30px 65px rgba(15,23,42,0.12);
            border-radius: 24px;
            padding: 3rem 3.5rem;
            margin: 0 auto;
        }
        .btn-back {
            border-radius: 999px;
            border: 1px solid var(--border-soft);
            color: var(--primary);
            padding: 0.5rem 1.25rem;
            transition: background 0.3s ease, color 0.3s ease;
        }
        .page-heading {
            text-align: center;
        }
        .page-heading h1 {
            font-size: clamp(2rem, 3vw, 2.8rem);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .page-heading p.lead {
            color: #4a5568;
            font-size: 1.2rem;
            margin-bottom: 3rem;
            
        }
        .touch-input {
            border-radius: 18px !important;
            padding: 10px 12px;
            font-size: 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            border: 1px solid rgba(15, 23, 42, 0.15);
            min-height: 40px;
        }
        textarea.touch-input {
            min-height: 100px;
        }
        .btn-submit {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.95rem 2rem;
            font-weight: 550;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .btn-submit:active {
            transform: translateY(2px);
        }
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            .feedback-card {
                padding: 1.5rem;
            }
            .keyboard-row {
                flex-wrap: wrap;
            }
        }
        @media (max-width: 480px) {
        }
        /* Notification Bubble Styles */
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
        }
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
        }
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        .notification-bubble i {
            font-size: 20px;
        }
        .notification-bubble span {
            display: inline-block;
        }
        .success-notification {
            background: linear-gradient(135deg, #00b894, #00a184);
        }
        .error-notification {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
    </style>
</head>
<body>
    <div class="feedback-shell">
        <div class="page-heading mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="main.php" class="btn btn-back">
                    <i class="bi bi-arrow-left"></i> Home
                </a>
                <span class="text-uppercase fw-semibold text-muted">Feedback</span>
            </div>
            <h1>Share Your Experience</h1>
            <p class="lead">We value every visit. Let us know how the experience went so we can continue improving our services.</p>
        </div>
        <div class="feedback-card">

            <form id="feedback-form" method="POST" action="">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control touch-input" placeholder="Enter your name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact (optional)</label>
                        <input type="text" name="contact" class="form-control touch-input" placeholder="Mobile or email">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Feedback / Suggestions</label>
                        <textarea name="message" class="form-control touch-input" placeholder="Type your message here" required></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" name="submit_feedback" class="btn-submit">Submit</button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($success_message): ?>
        <div id="successNotification" class="notification-bubble success-notification">
            <i class="bi bi-check-circle-fill"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div id="errorNotification" class="notification-bubble error-notification">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
    <?php endif; ?>
    <script>
        // Notification handling
        document.addEventListener('DOMContentLoaded', function() {
            const successNotification = document.getElementById('successNotification');
            const errorNotification = document.getElementById('errorNotification');

            const showNotification = (notification) => {
                if (!notification) return;
                setTimeout(() => notification.classList.add('show'), 100);
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hide');
                }, 4000);
            };

            showNotification(successNotification);
            showNotification(errorNotification);
        });
    </script>
</body>
</html>