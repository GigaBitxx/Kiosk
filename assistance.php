<?php
require_once 'config/database.php';

// Handle assistance request submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assistance'])) {
    // Check if assistance_requests table exists, if not create it
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'assistance_requests'");
    if (mysqli_num_rows($check_table) == 0) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS assistance_requests (
            request_id INT PRIMARY KEY AUTO_INCREMENT,
            category VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            urgency ENUM('normal', 'urgent') DEFAULT 'normal',
            custom_category VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending'
        )");
    }
    
    $category = mysqli_real_escape_string($conn, $_POST['category'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $urgency = mysqli_real_escape_string($conn, $_POST['urgency'] ?? 'normal');
    $custom_category = !empty($_POST['custom_category']) ? mysqli_real_escape_string($conn, $_POST['custom_category']) : NULL;
    
    // Validate description length
    $desc_length = strlen($description);
    if ($desc_length > 200) {
        $error_message = 'Description must not exceed 200 characters.';
    } elseif (empty($category)) {
        $error_message = 'Please select a category.';
    } else {
        // If category is 'others', use custom_category
        $final_category = ($category === 'others' && !empty($custom_category)) ? $custom_category : $category;
        
        $insert_query = "INSERT INTO assistance_requests (category, description, urgency, custom_category) VALUES ('$final_category', '$description', '$urgency', " . ($custom_category ? "'$custom_category'" : "NULL") . ")";
        if (mysqli_query($conn, $insert_query)) {
            $success_message = 'Your assistance request has been submitted successfully! We will get back to you soon.';
        } else {
            $error_message = 'Error submitting request. Please try again.';
        }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
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
            min-height: 100vh;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            padding: 2vw 1vw;
            color: var(--primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }
        .assistance-shell {
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
            text-decoration: none;
        }
        .btn-back:hover {
            background: var(--soft);
            color: var(--accent);
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
        .quick-category-section {
            margin-bottom: 2.5rem;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        .section-description {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 1.5rem;
        }
        .category-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .category-btn {
            background: var(--panel);
            border: 2px solid var(--border-soft);
            border-radius: 16px;
            padding: 1.5rem 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            text-align: center;
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
        }
        .category-btn:hover {
            border-color: var(--accent);
            background: rgba(43, 76, 126, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43, 76, 126, 0.15);
        }
        .category-btn.active {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
        }
        .category-btn i {
            font-size: 2rem;
            color: inherit;
        }
        .category-btn.active i {
            color: #fff;
        }
        .category-btn#category-help-finding i {
            color: #e74c3c;
        }
        .category-btn#category-burial-schedule i {
            color: var(--accent);
        }
        .category-btn#category-map-navigation i {
            color: #3498db;
        }
        .category-btn#category-general i {
            color: #f39c12;
        }
        .category-btn#category-others i {
            color: #95a5a6;
        }
        .category-btn.active#category-help-finding i,
        .category-btn.active#category-burial-schedule i,
        .category-btn.active#category-map-navigation i,
        .category-btn.active#category-general i,
        .category-btn.active#category-others i {
            color: #fff;
        }
        .custom-category-input {
            margin-top: 1rem;
            display: none;
        }
        .custom-category-input.active {
            display: block;
        }
        .description-section {
            margin-bottom: 2.5rem;
        }
        .form-label {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.75rem;
            display: block;
        }
        .description-input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border-soft);
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            resize: vertical;
            min-height: 120px;
            transition: border-color 0.3s ease;
        }
        .description-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .char-count {
            text-align: right;
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .char-count.warning {
            color: #e74c3c;
        }
        .char-count.error {
            color: #c0392b;
            font-weight: 600;
        }
        .urgency-section {
            margin-bottom: 2.5rem;
        }
        .urgency-toggle {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .urgency-option {
            flex: 1;
            max-width: 300px;
            padding: 1.25rem;
            border: 2px solid var(--border-soft);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: var(--panel);
        }
        .urgency-option:hover {
            border-color: var(--accent);
            background: rgba(43, 76, 126, 0.05);
        }
        .urgency-option.active {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
        }
        .urgency-option i {
            font-size: 1.5rem;
        }
        .urgency-option.normal i {
            color: #f39c12;
        }
        .urgency-option.urgent i {
            color: #e74c3c;
        }
        .urgency-option.active.normal i,
        .urgency-option.active.urgent i {
            color: #fff;
        }
        .urgency-option span {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .urgency-note {
            text-align: center;
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.75rem;
            font-style: italic;
        }
        .btn-submit {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            background: #1f3659;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43, 76, 126, 0.3);
        }
        .btn-submit:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
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
        .notification-bubble i {
            font-size: 20px;
        }
        .success-notification {
            background: linear-gradient(135deg, #00b894, #00a184);
        }
        .error-notification {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        @media (max-width: 768px) {
            .assistance-shell {
                padding: 2rem 1.5rem;
            }
            .category-buttons {
                grid-template-columns: 1fr;
            }
            .urgency-toggle {
                flex-direction: column;
            }
            .urgency-option {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="assistance-shell">
        <div class="page-heading mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="main.php" class="btn btn-back">
                    <i class="bi bi-arrow-left"></i> Home
                </a>
                <span class="text-uppercase fw-semibold text-muted">Request Assistance</span>
            </div>
            <h1>Request Assistance</h1>
            <p class="lead">We're here to help. Please select a category and describe what you need assistance with.</p>
        </div>

        <form id="assistance-form" method="POST" action="">
            <!-- Quick Category Buttons -->
            <div class="quick-category-section">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <span style="background: var(--accent); color: #fff; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem;">1</span>
                    <h3 class="section-title" style="margin: 0;">Quick Category Buttons</h3>
                </div>
                <div class="category-buttons">
                    <button type="button" class="category-btn" id="category-help-finding" data-category="help-finding-grave">
                        <i class='bx bxs-pin'></i>
                        <span>Help Finding a Grave</span>
                    </button>
                    <button type="button" class="category-btn" id="category-burial-schedule" data-category="burial-schedule-inquiry">
                        <i class='bx bx-square'></i>
                        <span>Burial Schedule Inquiry</span>
                    </button>
                    <button type="button" class="category-btn" id="category-map-navigation" data-category="map-navigation-help">
                        <i class='bx bx-compass'></i>
                        <span>Map Navigation Help</span>
                    </button>
                    <button type="button" class="category-btn" id="category-general" data-category="general-inquiry">
                        <i class='bx bx-bulb'></i>
                        <span>General Inquiry</span>
                    </button>
                    <button type="button" class="category-btn" id="category-others" data-category="others">
                        <i class='bx bx-message-dots'></i>
                        <span>Others</span>
                    </button>
                </div>
                <input type="hidden" name="category" id="selected-category" required>
                
                <!-- Custom category input for "Others" -->
                <div class="custom-category-input" id="custom-category-container">
                    <label class="form-label">Please specify:</label>
                    <input type="text" name="custom_category" id="custom-category" class="form-control description-input" placeholder="Enter your category..." style="min-height: 60px;">
                    <p class="section-description" style="margin-top: 0.5rem; margin-bottom: 0;">(Selecting Others opens a simple text field.)</p>
                </div>
            </div>

            <!-- Short Description Box -->
            <div class="description-section">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <span style="background: var(--accent); color: #fff; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem;">2</span>
                    <label class="form-label" style="margin: 0;">Short Description Box (Optional)</label>
                </div>
                <textarea name="description" id="description" class="description-input" placeholder="Describe what you need help with..." maxlength="200"></textarea>
                <div class="char-count" id="char-count">
                    <span id="char-display">0</span> / 200 characters max
                </div>
            </div>

            <!-- Urgency Selection -->
            <div class="urgency-section">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <span style="background: var(--accent); color: #fff; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem;">3</span>
                    <label class="form-label" style="margin: 0;">Urgency Selection</label>
                </div>
                <div class="urgency-toggle">
                    <div class="urgency-option normal" data-urgency="normal">
                        <i class='bx bx-time-five'></i>
                        <span>Normal</span>
                    </div>
                    <div class="urgency-option urgent" data-urgency="urgent">
                        <i class='bx bxs-hot'></i>
                        <span>Urgent</span>
                    </div>
                </div>
                <input type="hidden" name="urgency" id="selected-urgency" value="normal">
                <p class="urgency-note">(Only needed if staff is actively monitoring requests.)</p>
            </div>

            <!-- Submit Button -->
            <div class="mt-4">
                <button type="submit" name="submit_assistance" class="btn-submit" id="submit-btn" disabled>
                    <i class='bx bx-paper-plane'></i> Submit Request
                </button>
            </div>
        </form>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Category selection
        const categoryButtons = document.querySelectorAll('.category-btn');
        const selectedCategoryInput = document.getElementById('selected-category');
        const customCategoryContainer = document.getElementById('custom-category-container');
        const customCategoryInput = document.getElementById('custom-category');
        
        categoryButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove active class from all buttons
                categoryButtons.forEach(b => b.classList.remove('active'));
                // Add active class to clicked button
                this.classList.add('active');
                
                const category = this.getAttribute('data-category');
                selectedCategoryInput.value = category;
                
                // Show/hide custom category input
                if (category === 'others') {
                    customCategoryContainer.classList.add('active');
                    customCategoryInput.required = true;
                } else {
                    customCategoryContainer.classList.remove('active');
                    customCategoryInput.required = false;
                    customCategoryInput.value = '';
                }
                
                checkFormValidity();
            });
        });

        // Urgency selection
        const urgencyOptions = document.querySelectorAll('.urgency-option');
        const selectedUrgencyInput = document.getElementById('selected-urgency');
        
        urgencyOptions.forEach(option => {
            option.addEventListener('click', function() {
                urgencyOptions.forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                selectedUrgencyInput.value = this.getAttribute('data-urgency');
            });
        });

        // Character count for description
        const descriptionInput = document.getElementById('description');
        const charCount = document.getElementById('char-count');
        const charDisplay = document.getElementById('char-display');
        
        descriptionInput.addEventListener('input', function() {
            const length = this.value.length;
            charDisplay.textContent = length;
            
            charCount.classList.remove('warning', 'error');
            
            if (length < 100) {
                charCount.classList.add('error');
            } else if (length > 180) {
                charCount.classList.add('warning');
            }
            
            checkFormValidity();
        });

        // Form validation
        function checkFormValidity() {
            const category = selectedCategoryInput.value;
            const description = descriptionInput.value.trim();
            const descriptionLength = description.length;
            const customCategory = category === 'others' ? customCategoryInput.value.trim() : '';
            
            const isValid = category !== '' && 
                          descriptionLength <= 200 &&
                          (category !== 'others' || customCategory !== '');
            
            document.getElementById('submit-btn').disabled = !isValid;
        }

        // Show notifications
        const successNotification = document.getElementById('successNotification');
        const errorNotification = document.getElementById('errorNotification');
        
        if (successNotification) {
            successNotification.classList.add('show');
            setTimeout(() => {
                successNotification.classList.remove('show');
                setTimeout(() => {
                    window.location.href = 'main.php';
                }, 250);
            }, 3000);
        }
        
        if (errorNotification) {
            errorNotification.classList.add('show');
            setTimeout(() => {
                errorNotification.classList.remove('show');
            }, 5000);
        }

        // Set default urgency to normal
        document.querySelector('.urgency-option.normal').classList.add('active');
    </script>
</body>
</html>

