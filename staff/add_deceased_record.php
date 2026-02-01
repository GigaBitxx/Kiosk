<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

$plot_id = isset($_GET['plot_id']) ? (int)$_GET['plot_id'] : 0;
$plot = null;
$success_message = '';
$error_message = '';

if ($plot_id <= 0) {
    header('Location: plots.php');
    exit();
}

// Fetch basic plot + section details
$query = "SELECT p.plot_id, p.plot_number, p.row_number, p.status,
                 s.section_name, s.section_code
          FROM plots p
          LEFT JOIN sections s ON p.section_id = s.section_id
          WHERE p.plot_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $plot_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$plot = mysqli_fetch_assoc($result);

if (!$plot) {
    header('Location: plots.php');
    exit();
}

// Handle form submission – always INSERT a new deceased record for this plot
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = mysqli_real_escape_string($conn, trim($_POST['full_name'] ?? ''));
    $date_of_birth  = $_POST['date_of_birth'] ?? null;
    $date_of_death  = $_POST['date_of_death'] ?? null;
    $burial_date    = $_POST['burial_date'] ?? null;
    $next_of_kin    = mysqli_real_escape_string($conn, trim($_POST['next_of_kin'] ?? ''));
    $address        = mysqli_real_escape_string($conn, trim($_POST['address'] ?? '')); // kept for future compatibility, not stored if column missing
    $contact_number = mysqli_real_escape_string($conn, trim($_POST['contact_number'] ?? ''));

    $errors = [];
    if ($full_name === '') {
        $errors[] = "Full name is required.";
    }

    // Basic date validations (optional/soft)
    if (!empty($date_of_birth) && !empty($date_of_death) && strtotime($date_of_birth) > strtotime($date_of_death)) {
        $errors[] = "Date of birth cannot be after date of death.";
    }
    if (!empty($date_of_death) && !empty($burial_date) && strtotime($date_of_death) > strtotime($burial_date)) {
        $errors[] = "Date of death cannot be after burial date.";
    }

    if (empty($errors)) {
        // Match the base deceased_records schema (no address column in some databases)
        $insert = "INSERT INTO deceased_records 
                      (full_name, date_of_birth, date_of_death, burial_date, plot_id, next_of_kin, contact_number)
                   VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert);
        mysqli_stmt_bind_param(
            $stmt,
            "ssssiss",
            $full_name,
            $date_of_birth,
            $date_of_death,
            $burial_date,
            $plot_id,
            $next_of_kin,
            $contact_number
        );

        if (mysqli_stmt_execute($stmt)) {
            // Ensure plot is marked occupied when any deceased exists
            mysqli_query($conn, "UPDATE plots SET status = 'occupied' WHERE plot_id = " . (int)$plot_id);

            // Store success message in session and redirect to prevent form resubmission
            $_SESSION['add_record_success'] = "Deceased record added successfully!";
            header("Location: plot_details.php?id=" . (int)$plot_id);
            exit();
        } else {
            // Store error message in session and redirect back to this page
            $_SESSION['add_record_error'] = "Error saving deceased record: " . mysqli_error($conn);
            header("Location: add_deceased_record.php?plot_id=" . (int)$plot_id);
            exit();
        }
    } else {
        // Store validation errors in session and redirect back to this page
        $_SESSION['add_record_error'] = implode("<br>", $errors);
        header("Location: add_deceased_record.php?plot_id=" . (int)$plot_id);
        exit();
    }
}

// Check for session messages (from redirect after POST)
if (isset($_SESSION['add_record_success'])) {
    $success_message = $_SESSION['add_record_success'];
    unset($_SESSION['add_record_success']);
}
if (isset($_SESSION['add_record_error'])) {
    $error_message = $_SESSION['add_record_error'];
    unset($_SESSION['add_record_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <!-- Flatpickr datepicker for consistent MM/DD/YYYY date inputs -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        .layout { display: flex; min-height: 100vh; }
        .main {
            flex: 1;
            padding: 48px 40px 32px 40px;
            background: #f5f5f5;
            margin-left: 240px;
        }
        .sidebar.collapsed + .main {
            margin-left: 60px;
        }
        
        /* Responsive Design - Standard Breakpoints */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .main {
                padding: 24px 20px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .form-card {
                padding: 18px 16px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            
            .form-card {
                padding: 12px 10px;
            }
            
            /* Prevent horizontal scroll */
            body, html {
                overflow-x: hidden;
                max-width: 100vw;
            }
            
            .layout {
                overflow-x: hidden;
                max-width: 100vw;
            }
        }
        .page-header-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            background: #f3f4f6;
            color: #374151;
        }
        .btn-back:hover {
            background: #e5e7eb;
            color: #1f2937;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            text-decoration: none;
        }
        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: #000000;
            margin: 0;
            flex: 1;
            letter-spacing: 1px;
        }
        .form-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        .form-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            transition: border-color 0.2s;
            box-sizing: border-box;
            min-height: 40px;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }
        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }
        .btn-save {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-save:hover {
            background: #1f3659;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-cancel:hover {
            background: #5c636a;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        .alert-error {
            background: #f8d7da;
            color: #dc3545;
            border: 1px solid #f5c6cb;
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(-20px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        
        .notification-bubble.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        
        .notification-bubble.hide {
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-bubble span {
            flex: 1;
        }
        
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            border-left: 4px solid #065f46;
        }
        
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-left: 4px solid #991b1b;
        }
        
        .alert {
            display: none;
        }
        .record-info {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .record-info h3 {
            margin: 0 0 12px 0;
            color: #333;
            font-size: 16px;
        }
        .record-info p {
            margin: 4px 0;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-header-container">
            <a href="plot_details.php?id=<?php echo (int)$plot_id; ?>" class="btn-back">
                <i class="bx bx-arrow-back"></i> Back
            </a>
            <div class="page-title">Add Deceased / Urn to Plot</div>
        </div>
        
        <!-- Notification Bubbles -->
        <div id="successNotification" class="notification-bubble success-notification" style="display: none;">
            <i class="bi bi-check-circle-fill"></i>
            <span></span>
        </div>
        <div id="errorNotification" class="notification-bubble error-notification" style="display: none;">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span></span>
        </div>
        
        <!-- Display Messages (hidden, used for JS) -->
        <?php if (isset($success_message) && $success_message): ?>
            <div class="alert alert-success" data-message="<?php echo htmlspecialchars($success_message); ?>"></div>
        <?php endif; ?>
        <?php if (isset($error_message) && $error_message): ?>
            <div class="alert alert-error" data-message="<?php echo htmlspecialchars($error_message); ?>"></div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-title">Plot Information</div>
            <div class="record-info">
                <h3>Target Plot</h3>
                <?php
                    $sectionDisplay = 'N/A';
                    if (!empty($plot['section_name']) || !empty($plot['section_code'])) {
                        $name = $plot['section_name'] ?? '';
                        $code = $plot['section_code'] ?? '';
                        $sectionDisplay = trim($name . ($code ? " ($code)" : ""));
                    }
                    $rowLetter = chr(64 + (int)($plot['row_number'] ?? 1));
                    $sectionName = htmlspecialchars($sectionDisplay);
                    $rowDisplay = htmlspecialchars($rowLetter ? $rowLetter : 'N/A');
                    $plotNumber = htmlspecialchars($plot['plot_number'] ?? 'N/A');
                ?>
                <p><strong>Section:</strong> <?php echo $sectionName; ?></p>
                <p><strong>Row:</strong> <?php echo $rowDisplay; ?></p>
                <p><strong>Plot Number:</strong> <?php echo $plotNumber; ?></p>
                <p>
                    <strong>Current Status:</strong>
                    <?php echo ucfirst(htmlspecialchars($plot['status'])); ?>
                </p>
                <p style="margin-top:8px; color:#555;">
                    You are adding an <strong>additional deceased/urn</strong> to this plot. Existing records for this plot will be kept.
                </p>
            </div>

            <form method="POST" action="" id="deceased-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="text" class="date-mdY" id="date_of_birth" name="date_of_birth" placeholder="mm/dd/yyyy">
                    </div>
                    <div class="form-group">
                        <label for="date_of_death">Date of Death</label>
                        <input type="text" class="date-mdY" id="date_of_death" name="date_of_death" placeholder="mm/dd/yyyy">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="burial_date">Burial / Inurnment Date</label>
                        <input type="text" class="date-mdY" id="burial_date" name="burial_date" placeholder="mm/dd/yyyy">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="next_of_kin">Name of Lessee</label>
                        <input type="text" id="next_of_kin" name="next_of_kin" placeholder="Name of lessee / contract holder">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" placeholder="Enter complete address...">
                    </div>
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" id="contact_number" name="contact_number" placeholder="Enter phone number...">
                    </div>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn-save">Save Deceased Record</button>
                    <button type="button" class="btn-cancel" onclick="window.location.href='plot_details.php?id=<?php echo (int)$plot_id; ?>'">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Flatpickr setup for this page
    document.addEventListener('DOMContentLoaded', function () {
        const dateInputs = document.querySelectorAll('input.date-mdY');
        if (!dateInputs.length || !window.flatpickr) return;

        dateInputs.forEach(function (input) {
            flatpickr(input, {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "m/d/Y",
                allowInput: true
            });
        });
    });

    // Notification bubble handling
    document.addEventListener('DOMContentLoaded', function() {
        const successNotification = document.getElementById('successNotification');
        const errorNotification = document.getElementById('errorNotification');
        const successAlert = document.querySelector('.alert-success');
        const errorAlert = document.querySelector('.alert-error');
        
        const showNotification = (notification, message) => {
            if (!notification) return;
            const span = notification.querySelector('span');
            if (span) span.textContent = message;
            notification.style.display = 'flex';
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => {
                    notification.style.display = 'none';
                    notification.classList.remove('hide');
                }, 250);
            }, 4000);
        };
        
        if (successAlert) {
            const message = successAlert.getAttribute('data-message');
            if (message) {
                showNotification(successNotification, message);
            }
        }
        
        if (errorAlert) {
            const message = errorAlert.getAttribute('data-message');
            if (message) {
                showNotification(errorNotification, message);
            }
        }
    });
</script>
</body>
</html>


