<?php
// Set error reporting for development (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Start output buffering to catch any errors
ob_start();

try {
    require_once '../config/database.php';
    require_once '../includes/auth_check.php';

    // Check if user is staff
    if ($_SESSION['role'] !== 'staff') {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    // Get JSON input
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);

    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit();
    }

    // Validate required fields
    if (!isset($input['plot_id']) || !isset($input['contract_start_date'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    $plot_id = intval($input['plot_id']);
    $contract_start_date = trim($input['contract_start_date']);

    // Validate plot_id
    if ($plot_id <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plot ID']);
        exit();
    }

    // Validate date format (YYYY-MM-DD)
    if (!empty($contract_start_date)) {
        $date_parts = explode('-', $contract_start_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
            exit();
        }
    }

    // Check if plot exists and has an expired contract
    $check_query = "SELECT contract_status FROM plots WHERE plot_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    if (!$check_stmt) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    mysqli_stmt_bind_param($check_stmt, "i", $plot_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $plot_data = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);

    if (!$plot_data) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Plot not found']);
        exit();
    }

    // Only allow updates for expired contracts
    if ($plot_data['contract_status'] !== 'expired') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only expired contracts can have their start date updated']);
        exit();
    }

    // Calculate end date (start date + 5 years) and renewal reminder date (end date - 30 days)
    $contract_end_date = null;
    $renewal_reminder_date = null;
    $contract_status = 'active'; // Default status

    if (!empty($contract_start_date)) {
        try {
            $start_date_obj = new DateTime($contract_start_date);
            
            // Calculate end date: start date + 5 years
            $end_date_obj = clone $start_date_obj;
            $end_date_obj->modify('+5 years');
            $contract_end_date = $end_date_obj->format('Y-m-d');
            
            // Calculate renewal reminder date: end date - 30 days
            $reminder_date_obj = clone $end_date_obj;
            $reminder_date_obj->modify('-30 days');
            $renewal_reminder_date = $reminder_date_obj->format('Y-m-d');
            
            // Determine contract status based on end date
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $end_date_only = clone $end_date_obj;
            $end_date_only->setTime(0, 0, 0);
            
            if ($end_date_only < $today) {
                // End date is in the past
                $contract_status = 'expired';
            } else {
                // Calculate days until expiry
                $interval = $today->diff($end_date_only);
                $days_until_expiry = (int)$interval->format('%a'); // %a gives total days
                
                if ($days_until_expiry <= 30) {
                    // Within 30 days of expiry
                    $contract_status = 'renewal_needed';
                } else {
                    // More than 30 days until expiry
                    $contract_status = 'active';
                }
            }
        } catch (Exception $e) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid date: ' . $e->getMessage()]);
            exit();
        }
    }

    // Update the contract start date, end date, renewal reminder date, and status
    $update_query = "UPDATE plots SET 
                     contract_start_date = NULLIF(?, ''), 
                     contract_end_date = NULLIF(?, ''), 
                     renewal_reminder_date = NULLIF(?, ''),
                     contract_status = ?
                     WHERE plot_id = ?";
    $stmt = mysqli_prepare($conn, $update_query);

    if (!$stmt) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }

    mysqli_stmt_bind_param($stmt, "ssssi", $contract_start_date, $contract_end_date, $renewal_reminder_date, $contract_status, $plot_id);

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        ob_end_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Contract dates updated successfully',
            'contract_start_date' => $contract_start_date,
            'contract_end_date' => $contract_end_date,
            'renewal_reminder_date' => $renewal_reminder_date,
            'contract_status' => $contract_status
        ]);
    } else {
        $error_msg = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update contract: ' . $error_msg]);
    }

    mysqli_close($conn);
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
}
?>
