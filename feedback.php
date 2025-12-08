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
            overflow: hidden;
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
        .virtual-keyboard {
            position: fixed;
            left: 50%;
            bottom: 2rem;
            transform: translateX(-50%);
            background: var(--panel);
            border-radius: 20px;
            width: min(1200px, 95vw);
            border: 1px solid var(--border-soft);
            box-shadow: 0 20px 50px rgba(15,23,42,0.12);
            padding: 1rem;
            display: none;
            z-index: 1000;
        }
        .virtual-keyboard.active {
            display: block;
        }
        .keyboard-row {
            display: flex;
            justify-content: center;
            gap: 0.6rem;
            margin-bottom: 0.6rem;
            flex-wrap: nowrap;
        }
        .keyboard-row:last-child {
            margin-bottom: 0;
        }
        .key-btn {
            flex: 1;
            max-width: 80px;
            padding: 0.9rem 0;
            border: none;
            border-radius: 12px;
            background: rgba(43, 76, 126, 0.08);
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 600;
        }
        .key-btn.special {
            max-width: 140px;
            background: var(--accent);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }
        .key-btn:active {
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
    
        <div class="virtual-keyboard" id="virtual-keyboard">
            <div class="keyboard-row" data-row="1"></div>
            <div class="keyboard-row" data-row="2"></div>
            <div class="keyboard-row" data-row="3"></div>
            <div class="keyboard-row" data-row="4"></div>
        </div>

    <script>
        const layout = [
            ['ESC','1','2','3','4','5','6','7','8','9','0','-','=','DELETE'],
            ['CAPS','Q','W','E','R','T','Y','U','I','O','P','[',']'],
            ['A','S','D','F','G','H','J','K','L','`','/','\\'],
            ['Z','X','C','V','B','N','M','SPACE','CLEAR']
        ];

        const keyboard = document.getElementById('virtual-keyboard');
        const rows = keyboard.querySelectorAll('.keyboard-row');
        const inputs = document.querySelectorAll('.touch-input');
        let activeInput = null;
        let isUppercase = true;
        let caseButton = null;

        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                activeInput = input;
                keyboard.classList.add('active');
            });
            input.addEventListener('click', () => {
                activeInput = input;
                keyboard.classList.add('active');
            });
        });

        document.addEventListener('click', (event) => {
            if (!keyboard.contains(event.target) && !event.target.closest('.touch-input') && !event.target.closest('.feedback-shell')) {
                keyboard.classList.remove('active');
            }
        });

        layout.forEach((keys, idx) => {
            const row = rows[idx];
            keys.forEach(key => {
                const button = document.createElement('button');
                button.type = 'button';
                const isSpecial = ['SPACE','DELETE','CLEAR','CAPS','ESC'].includes(key);
                button.className = 'key-btn' + (isSpecial ? ' special' : '');
                button.dataset.key = key;

                if (key === 'SPACE') {
                    button.innerHTML = '<span>SPACE</span>';
                } else if (key === 'CAPS') {
                    button.innerHTML = '<i class="bx bx-up-arrow-alt"></i><span>CAPS</span>';
                } else if (key === 'DELETE') {
                    button.innerHTML = '<i class="bx bx-left-arrow-alt"></i>';
                } else if (key === 'ESC') {
                    button.innerHTML = '<span>ESC</span><i class="bx bx-refresh"></i>';
                } else {
                    button.textContent = key;
                }

                if (key === 'CAPS') {
                    caseButton = button;
                }
                button.addEventListener('click', handleKeyPress);
                row.appendChild(button);
            });
        });

        function handleKeyPress(event) {
            if (!activeInput) return;
            const key = event.currentTarget.dataset.key;

            if (key === 'CAPS') {
                isUppercase = !isUppercase;
                if (caseButton) {
                    const label = caseButton.querySelector('span');
                    const icon = caseButton.querySelector('i');
                    if (label) label.innerHTML = isUppercase ? 'CAPS' : 'caps';
                    if (icon) icon.className = isUppercase ? 'bx bx-up-arrow-alt' : 'bx bx-down-arrow-alt';
                }
                document.querySelectorAll('.key-btn').forEach(btn => {
                    const keyChar = btn.dataset.key;
                    if (!['SPACE','DELETE','CLEAR','CAPS','ESC'].includes(keyChar) && /^[a-zA-Z]$/.test(keyChar)) {
                        btn.textContent = isUppercase ? keyChar.toUpperCase() : keyChar.toLowerCase();
                    }
                });
                return;
            }

            if (key === 'ESC') {
                keyboard.classList.remove('active');
                return;
            }

            if (key === 'DELETE') {
                const value = activeInput.value;
                activeInput.value = value.slice(0, -1);
                return;
            }

            if (key === 'CLEAR') {
                activeInput.value = '';
                return;
            }

            if (key === 'SPACE') {
                activeInput.value += ' ';
                return;
            }

            if (/^[a-zA-Z]$/.test(key)) {
                activeInput.value += isUppercase ? key.toUpperCase() : key.toLowerCase();
                return;
            }

            activeInput.value += key;
        }

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