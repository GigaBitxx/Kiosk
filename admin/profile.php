<?php
require_once 'includes/auth_check.php';
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Ensure profile columns exist
$check_bio = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'bio'");
if (mysqli_num_rows($check_bio) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER full_name");
}

$check_profile = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
if (mysqli_num_rows($check_profile) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER bio");
}

$check_cover = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'cover_photo'");
if (mysqli_num_rows($check_cover) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN cover_photo VARCHAR(255) NULL AFTER profile_picture");
}

$check_birthdate = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'birthdate'");
if (mysqli_num_rows($check_birthdate) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN birthdate DATE NULL AFTER full_name");
}

$check_address = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'address'");
if (mysqli_num_rows($check_address) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN address TEXT NULL AFTER birthdate");
}

$check_phone = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'phone'");
if (mysqli_num_rows($check_phone) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER address");
}

$check_email = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'personal_email'");
if (mysqli_num_rows($check_email) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN personal_email VARCHAR(100) NULL AFTER phone");
}

// Handle individual field update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_field'])) {
    $field = mysqli_real_escape_string($conn, $_POST['field'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $value_escaped = mysqli_real_escape_string($conn, $value);
    
    if ($field && in_array($field, ['full_name', 'birthdate', 'address', 'phone', 'personal_email'])) {
        if ($value) {
            $update_query = "UPDATE users SET $field = '$value_escaped' WHERE user_id = $user_id";
        } else {
            $update_query = "UPDATE users SET $field = NULL WHERE user_id = $user_id";
        }
        
        if (mysqli_query($conn, $update_query)) {
            // Update session if full_name was changed
            if ($field === 'full_name') {
                $_SESSION['full_name'] = $value;
            }
            // Redirect to refresh the page with updated data
            header('Location: profile.php?updated=1');
            exit();
        } else {
            $error = 'Error updating field: ' . mysqli_error($conn);
        }
    }
}

// Show success message if redirected after update
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = 'Field updated successfully!';
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/profiles/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $file_name = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
            $profile_picture = 'assets/uploads/profiles/' . $file_name;
            // Delete old profile picture if exists
            $old_profile = mysqli_query($conn, "SELECT profile_picture FROM users WHERE user_id = $user_id");
            if ($old_row = mysqli_fetch_assoc($old_profile)) {
                if ($old_row['profile_picture'] && file_exists('../' . $old_row['profile_picture'])) {
                    unlink('../' . $old_row['profile_picture']);
                }
            }
            $update_query = "UPDATE users SET profile_picture = '$profile_picture' WHERE user_id = $user_id";
            if (mysqli_query($conn, $update_query)) {
                $message = 'Profile picture updated successfully!';
            } else {
                $error = 'Error updating profile picture: ' . mysqli_error($conn);
            }
        }
    }
}

// Handle cover photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cover_photo'])) {
    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/covers/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['cover_photo']['name'], PATHINFO_EXTENSION);
        $file_name = 'cover_' . $user_id . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['cover_photo']['tmp_name'], $file_path)) {
            $cover_photo = 'assets/uploads/covers/' . $file_name;
            // Delete old cover photo if exists
            $old_cover = mysqli_query($conn, "SELECT cover_photo FROM users WHERE user_id = $user_id");
            if ($old_row = mysqli_fetch_assoc($old_cover)) {
                if ($old_row['cover_photo'] && file_exists('../' . $old_row['cover_photo'])) {
                    unlink('../' . $old_row['cover_photo']);
                }
            }
            $update_query = "UPDATE users SET cover_photo = '$cover_photo' WHERE user_id = $user_id";
            if (mysqli_query($conn, $update_query)) {
                $message = 'Cover photo updated successfully!';
            } else {
                $error = 'Error updating cover photo: ' . mysqli_error($conn);
            }
        }
    }
}

// Fetch user data - columns are now guaranteed to exist
$user_query = "SELECT user_id, username, full_name, 
               COALESCE(bio, '') as bio,
               COALESCE(birthdate, '') as birthdate,
               COALESCE(address, '') as address,
               COALESCE(phone, '') as phone,
               COALESCE(personal_email, '') as personal_email,
               COALESCE(profile_picture, '') as profile_picture,
               COALESCE(cover_photo, '') as cover_photo
               FROM users WHERE user_id = $user_id";
$user_result = mysqli_query($conn, $user_query);
$user = mysqli_fetch_assoc($user_result);

// Set defaults if user not found
if (!$user) {
    $user = [
        'user_id' => $user_id,
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'bio' => '',
        'birthdate' => '',
        'address' => '',
        'phone' => '',
        'personal_email' => '',
        'profile_picture' => '',
        'cover_photo' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <?php include 'includes/styles.php'; ?>
    <style>
        body { margin: 0; padding: 0; font-family: 'Raleway', 'Helvetica Neue', sans-serif; background: #f5f5f5; }
        .main {
            padding: 0;
            padding-top: 80px;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
            background: #ffffff;
        }
        .profile-header {
            background: #ffffff;
            border-bottom: none;
            margin-bottom: 0;
            width: 100%;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
        }
        .top-actions {
            position: fixed;
            top: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 24px 40px;
            z-index: 100;
            background: transparent;
        }
        .profile-info-section {
            position: relative;
            padding: 0 40px 20px 40px;
            margin-top: 0;
            max-width: 100%;
            margin-left: auto;
            margin-right: auto;
            background: #ffffff;
        }
        .profile-picture-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .profile-picture-container {
            position: relative;
            display: inline-block;
        }
        .profile-picture {
            width: 168px;
            height: 168px;
            border-radius: 50%;
            border: none;
            object-fit: cover;
            background: transparent;
        }
        .edit-profile-pic-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: #2b4c7e;
            color: #fff;
            border: 3px solid #ffffff;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .edit-profile-pic-btn:hover {
            background: #1f3659;
            transform: scale(1.1);
        }
        .profile-details {
            margin-top: 20px;
            text-align: center;
        }
        .profile-name {
            font-size: 32px;
            font-weight: 700;
            color: #1d2a38;
            margin: 0;
        }
        .profile-bio {
            font-size: 16px;
            color: #4a5568;
            margin: 8px 0;
            line-height: 1.5;
        }
        .profile-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .btn-edit-profile {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 2.1rem;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .btn-edit-profile:hover {
            background: #1f3659;
            transform: translateY(-2px);
        }
        .top-actions .btn-settings {
            background: #f5f5f5;
            color: #1d2a38;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 18px;
            text-decoration: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            cursor: pointer;
        }
        .top-actions .btn-settings:hover {
            background: #e0e0e0;
            transform: translateY(-1px);
        }
        .top-actions .btn-logout {
            background: #c44536;
            color: #fff;
            padding: 8px 22px;
            font-size: 15px;
            font-weight: 500;
            width: auto;
            border-radius: 999px;
            text-decoration: none;
            letter-spacing: 0.5px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            user-select: none;
        }
        .top-actions .btn-logout:hover {
            background: #a03a2d;
            transform: translateY(-1px);
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal.show {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
        }
        .modal-content {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            margin: 0 auto;
            padding: 24px;
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .modal-title {
            font-size: 1.5rem;
            font-weight: 500;
            color: #1d2a38;
            margin: 0;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            margin: 0;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 15px;
            color: #1d2a38;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 0;
            font-size: 15px;
            background: #ffffff;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            color: #1d2a38;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        .form-group input[type="file"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 0;
            font-size: 14px;
            background: #ffffff;
        }
        .file-preview {
            margin-top: 8px;
            padding: 8px;
            background: #f4f6f9;
            border-radius: 0;
        }
        .file-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 0;
            margin-top: 8px;
        }
        .btn-submit {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 2.1rem;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            background: #1f3659;
            transform: translateY(-2px);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .profile-bio[style*="color: #999"] {
            color: #8b9bb7 !important;
        }
        .profile-information {
            margin-top: 40px;
            padding: 24px 0;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            background: #ffffff;
        }
        .profile-info-title {
            font-size: 20px;
            font-weight: 600;
            color: #1d2a38;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e0e0e0;
        }
        .profile-info-title-wrapper .btn-settings:hover {
            background: #e0e0e0;
            transform: translateY(-1px);
        }
        .profile-info-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .profile-info-item:last-child {
            border-bottom: none;
        }
        .profile-info-icon {
            font-size: 24px;
            color: #2b4c7e;
            min-width: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .profile-info-content {
            flex: 1;
        }
        .profile-info-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
            font-weight: 500;
        }
        .profile-info-value {
            font-size: 16px;
            color: #1d2a38;
            word-break: break-word;
        }
        .profile-info-value.empty {
            color: #999;
            font-style: italic;
        }
        .profile-info-edit {
            background: #f0f0f0;
            color: #1d2a38;
            text-decoration: none;
            font-size: 18px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .profile-info-edit:hover {
            background: #e0e0e0;
            transform: scale(1.1);
        }
        .profile-info-item.editing .profile-info-value {
            display: none;
        }
        .profile-info-item.editing .profile-info-edit-form {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .profile-info-edit-form {
            display: none;
        }
        .profile-info-edit-form input,
        .profile-info-edit-form textarea {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            font-family: inherit;
        }
        .profile-info-edit-form textarea {
            min-height: 60px;
            resize: vertical;
        }
        .btn-save {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save:hover {
            background: #1f3659;
        }
        .btn-cancel {
            background: #999;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel:hover {
            background: #777;
        }
        .thumbnail-modal .modal-content {
            max-width: 500px;
        }
        .thumbnail-preview-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 24px 0;
            min-height: 300px;
            background: #f5f5f5;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
        }
        .thumbnail-preview-wrapper {
            position: relative;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .thumbnail-preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform-origin: center;
            transition: transform 0.1s;
        }
        .thumbnail-zoom-controls {
            margin: 20px 0;
        }
        .zoom-slider-container {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .zoom-icon {
            font-size: 20px;
            color: #666;
            width: 24px;
            text-align: center;
        }
        .zoom-slider {
            flex: 1;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }
        .zoom-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            background: #2b4c7e;
            border-radius: 50%;
            cursor: pointer;
        }
        .zoom-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            background: #2b4c7e;
            border-radius: 50%;
            cursor: pointer;
            border: none;
        }
        .thumbnail-privacy {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .thumbnail-privacy i {
            font-size: 16px;
        }
        .thumbnail-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .thumbnail-modal .btn-cancel {
            background: transparent;
            color: #2b4c7e;
            border: none;
            padding: 8px 16px;
        }
        .thumbnail-modal .btn-cancel:hover {
            background: #f5f5f5;
        }
        .thumbnail-modal .btn-save {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 24px;
        }
        .thumbnail-modal .btn-save:hover {
            background: #1f3659;
        }
        /* Responsive Styles for Large Screens */
        @media (min-width: 1400px) {
            .main {
                margin-left: 0 !important;
            }
            .profile-header {
                max-width: 1000px;
            }
            .top-actions {
                padding: 20px 60px;
            }
            .profile-info-section {
                padding: 0 60px 32px 60px;
                max-width: 1000px;
            }
            .profile-information {
                max-width: 1000px;
                padding: 24px 60px;
            }
            .profile-picture {
                width: 180px;
                height: 180px;
            }
            .profile-name {
                font-size: 36px;
            }
            .profile-info-title {
                font-size: 22px;
            }
        }
        
        @media (min-width: 1600px) {
            .profile-header {
                max-width: 1200px;
            }
            .top-actions {
                padding: 24px 80px;
            }
            .profile-info-section {
                padding: 0 80px 40px 80px;
                max-width: 1200px;
            }
            .profile-information {
                max-width: 1200px;
                padding: 24px 80px;
            }
            .profile-picture {
                width: 200px;
                height: 200px;
            }
            .profile-name {
                font-size: 40px;
            }
            .profile-info-title {
                font-size: 24px;
            }
        }
        
        @media (min-width: 1920px) {
            .profile-header {
                max-width: 1400px;
            }
            .top-actions {
                padding: 28px 120px;
            }
            .profile-info-section {
                padding: 0 120px 48px 120px;
                max-width: 1400px;
            }
            .profile-information {
                max-width: 1400px;
                padding: 24px 120px;
            }
            .profile-picture {
                width: 220px;
                height: 220px;
            }
            .profile-name {
                font-size: 44px;
            }
            .profile-info-title {
                font-size: 26px;
            }
        }
        
        @media (max-width: 1200px) {
            .main {
                margin-left: 0 !important;
            }
        }
        
        @media (max-width: 1100px) {
            .main { 
                padding: 0;
                padding-top: 80px;
                margin-left: 0 !important;
            }
            .profile-header {
                max-width: 100%;
            }
            .top-actions { 
                padding: 16px 24px; 
            }
            .profile-info-section { 
                padding: 0 24px 20px 24px; 
                max-width: 100%;
            }
            .profile-information { 
                max-width: 100%;
                padding: 24px 24px !important; 
            }
        }
        
        @media (max-width: 768px) {
            .main {
                padding-top: 70px;
            }
            .profile-header {
                max-width: 100%;
            }
            .top-actions { 
                padding: 14px 20px; 
                gap: 10px;
            }
            .profile-info-section { 
                padding: 0 20px 16px 20px; 
                max-width: 100%;
            }
            .profile-information {
                max-width: 100%;
                padding: 24px 20px !important;
            }
            .profile-picture { 
                width: 140px; 
                height: 140px; 
            }
            .profile-name { 
                font-size: 28px; 
            }
            .profile-info-title {
                font-size: 18px;
            }
        }
        
        @media (max-width: 700px) {
            .layout { 
                flex-direction: column; 
            }
            .sidebar { 
                width: 100vw; 
                min-height: 60px; 
                height: 60px; 
                position: static; 
            }
            .sidebar.collapsed { 
                width: 100px; 
            }
            .main { 
                margin-left: 0;
                padding-top: 70px;
            }
            .sidebar.collapsed + .main { 
                margin-left: 0; 
            }
            .top-actions { 
                padding: 12px 16px; 
                gap: 8px; 
            }
            .top-actions .btn-settings { 
                width: 36px; 
                height: 36px; 
                font-size: 16px; 
            }
            .top-actions .btn-logout { 
                padding: 6px 16px; 
                font-size: 14px; 
            }
            .profile-picture { 
                width: 120px; 
                height: 120px; 
            }
            .profile-name { 
                font-size: 24px; 
            }
            .profile-header {
                max-width: 100%;
            }
            .profile-info-section {
                padding: 0 16px 12px 16px;
                max-width: 100%;
            }
            .profile-information {
                max-width: 100%;
                padding: 24px 16px !important;
            }
        }
        
        @media (max-width: 576px) {
            .main {
                padding-top: 65px;
            }
            .top-actions {
                padding: 10px 12px;
                gap: 6px;
            }
            .profile-header {
                max-width: 100%;
            }
            .profile-info-section {
                padding: 0 12px 10px 12px;
                max-width: 100%;
            }
            .profile-information {
                max-width: 100%;
                padding: 24px 12px !important;
            }
            .profile-picture {
                width: 100px;
                height: 100px;
            }
            .profile-name {
                font-size: 20px;
            }
            .profile-info-title {
                font-size: 16px;
            }
            .profile-info-item {
                padding: 12px 0;
                gap: 12px;
            }
            .profile-info-label {
                font-size: 13px;
            }
            .profile-info-value {
                font-size: 14px;
            }
            .profile-info-edit {
                width: 32px;
                height: 32px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
            <div class="top-actions">
                <a href="logout.php" class="btn-logout">Log Out</a>
            </div>
        <div class="profile-header">
            <div class="profile-info-section">
                <div class="profile-picture-wrapper">
                    <div class="profile-picture-container">
                        <img src="<?php echo !empty($user['profile_picture']) ? '../' . htmlspecialchars($user['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name']) . '&size=168&background=667eea&color=fff&bold=true'; ?>" 
                             alt="Profile Picture" 
                             class="profile-picture"
                             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['full_name']); ?>&size=168&background=2b4c7e&color=fff&bold=true'">
                        <form method="POST" action="" enctype="multipart/form-data" id="profilePictureForm" style="display: none;">
                            <input type="file" id="profile_picture_input" name="profile_picture" accept="image/*">
                            <input type="hidden" name="update_profile_picture" value="1">
                        </form>
                        <button class="edit-profile-pic-btn" onclick="openThumbnailModal()" title="Edit Profile Picture">
                            <i class='bx bx-camera'></i>
                        </button>
                    </div>
                </div>
                <div class="profile-details">
                    <h1 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h1>
                </div>
            </div>
            
            <div class="profile-information">
                <div class="profile-info-title-wrapper" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h2 class="profile-info-title" style="margin: 0;">Profile Information</h2>
                    <a href="settings.php" class="btn-settings" title="Settings" style="background: #f5f5f5; color: #1d2a38; border: 1px solid #e0e0e0; border-radius: 8px; padding: 8px 16px; font-size: 18px; text-decoration: none; transition: all 0.2s; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px;">
                        <i class='bx bx-cog'></i>
                    </a>
                </div>
                
                <div class="profile-info-item" id="item-full_name">
                    <div class="profile-info-icon">
                        <i class='bx bx-user'></i>
                    </div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Full Name</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <form method="POST" action="" class="profile-info-edit-form" onsubmit="return saveField('full_name', this);">
                            <input type="hidden" name="update_field" value="1">
                            <input type="hidden" name="field" value="full_name">
                            <input type="text" name="value" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            <button type="submit" class="btn-save">Save</button>
                            <button type="button" class="btn-cancel" onclick="cancelEdit('full_name')">Cancel</button>
                        </form>
                    </div>
                    <a href="#" class="profile-info-edit" onclick="editField('full_name'); return false;" title="Edit">
                        <i class='bx bx-edit'></i>
                    </a>
                </div>
                
                <div class="profile-info-item" id="item-phone">
                    <div class="profile-info-icon">
                        <i class='bx bx-phone'></i>
                    </div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Phone Number</div>
                        <div class="profile-info-value <?php echo empty($user['phone']) ? 'empty' : ''; ?>">
                            <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : 'Not set'; ?>
                        </div>
                        <form method="POST" action="" class="profile-info-edit-form" onsubmit="return saveField('phone', this);">
                            <input type="hidden" name="update_field" value="1">
                            <input type="hidden" name="field" value="phone">
                            <input type="text" name="value" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="Enter phone number">
                            <button type="submit" class="btn-save">Save</button>
                            <button type="button" class="btn-cancel" onclick="cancelEdit('phone')">Cancel</button>
                        </form>
                    </div>
                    <a href="#" class="profile-info-edit" onclick="editField('phone'); return false;" title="Edit">
                        <i class='bx bx-edit'></i>
                    </a>
                </div>
                
                <div class="profile-info-item" id="item-personal_email">
                    <div class="profile-info-icon">
                        <i class='bx bx-envelope'></i>
                    </div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Personal Email</div>
                        <div class="profile-info-value <?php echo empty($user['personal_email']) ? 'empty' : ''; ?>">
                            <?php echo !empty($user['personal_email']) ? htmlspecialchars($user['personal_email']) : 'Not set'; ?>
                        </div>
                        <form method="POST" action="" class="profile-info-edit-form" onsubmit="return saveField('personal_email', this);">
                            <input type="hidden" name="update_field" value="1">
                            <input type="hidden" name="field" value="personal_email">
                            <input type="email" name="value" value="<?php echo htmlspecialchars($user['personal_email']); ?>" placeholder="Enter personal email">
                            <button type="submit" class="btn-save">Save</button>
                            <button type="button" class="btn-cancel" onclick="cancelEdit('personal_email')">Cancel</button>
                        </form>
                    </div>
                    <a href="#" class="profile-info-edit" onclick="editField('personal_email'); return false;" title="Edit">
                        <i class='bx bx-edit'></i>
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px 40px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin: 20px 40px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Thumbnail Modal -->
<div class="modal thumbnail-modal" id="thumbnailModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Edit thumbnail</h3>
            <button class="modal-close" onclick="closeThumbnailModal()">&times;</button>
        </div>
        <div class="thumbnail-preview-container">
            <div class="thumbnail-preview-wrapper">
                <img id="thumbnailPreview" src="" alt="Profile Preview" class="thumbnail-preview-img">
            </div>
        </div>
        <div class="thumbnail-zoom-controls">
            <div class="zoom-slider-container">
                <span class="zoom-icon">−</span>
                <input type="range" id="zoomSlider" class="zoom-slider" min="1" max="3" step="0.1" value="1" oninput="updateZoom(this.value)">
                <span class="zoom-icon">+</span>
            </div>
        </div>
        <div class="thumbnail-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeThumbnailModal()">Cancel</button>
            <button type="button" class="btn-save" onclick="saveThumbnail()">Save</button>
        </div>
    </div>
</div>

<script>
function editField(fieldName) {
    const item = document.getElementById('item-' + fieldName);
    item.classList.add('editing');
    const input = item.querySelector('input[name="value"], textarea[name="value"]');
    if (input) {
        input.focus();
        if (input.tagName === 'TEXTAREA') {
            input.style.height = 'auto';
            input.style.height = input.scrollHeight + 'px';
        }
    }
}

function cancelEdit(fieldName) {
    const item = document.getElementById('item-' + fieldName);
    item.classList.remove('editing');
    // Reset form value
    const form = item.querySelector('.profile-info-edit-form');
    if (form) {
        form.reset();
    }
}

function saveField(fieldName, form) {
    // Form will submit normally
    return true;
}

let currentImageFile = null;
let currentZoom = 1;

function openThumbnailModal() {
    document.getElementById('profile_picture_input').click();
}

document.getElementById('profile_picture_input').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        currentImageFile = this.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('thumbnailPreview').src = e.target.result;
            document.getElementById('thumbnailModal').classList.add('show');
            currentZoom = 1;
            document.getElementById('zoomSlider').value = 1;
        };
        reader.readAsDataURL(this.files[0]);
    }
});

function updateZoom(value) {
    currentZoom = parseFloat(value);
    const img = document.getElementById('thumbnailPreview');
    img.style.transform = `scale(${currentZoom})`;
}

function closeThumbnailModal() {
    document.getElementById('thumbnailModal').classList.remove('show');
    // Reset file input
    document.getElementById('profile_picture_input').value = '';
    currentImageFile = null;
    currentZoom = 1;
}

function saveThumbnail() {
    if (currentImageFile) {
        // Submit the form to upload the image
        document.getElementById('profilePictureForm').submit();
    }
}

// Close modal when clicking outside
document.getElementById('thumbnailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeThumbnailModal();
    }
});

// Auto-hide alerts after 3 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.remove();
        }, 500);
    });
}, 3000);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-settings.js"></script>
</body>
</html>


