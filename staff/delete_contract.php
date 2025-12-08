<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// Get plot_id and record_id from request
$plot_id = isset($_GET['plot_id']) ? (int) $_GET['plot_id'] : null;
$record_id = isset($_GET['record_id']) ? (int) $_GET['record_id'] : null;
$reason = isset($_GET['reason']) ? trim($_GET['reason']) : 'Contract deleted by staff';

if (!$plot_id) {
    header('Location: contracts.php?error=missing_plot_id');
    exit();
}

// Check if archived_contracts table exists, create if not
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_contracts'");
$table_exists = $table_check && mysqli_num_rows($table_check) > 0;
if ($table_check) {
    mysqli_free_result($table_check);
}

if (!$table_exists) {
    // Create the table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS archived_contracts (
        archive_id INT PRIMARY KEY AUTO_INCREMENT,
        plot_id INT NOT NULL,
        record_id INT DEFAULT NULL,
        deceased_name VARCHAR(255) NOT NULL,
        section_name VARCHAR(100) DEFAULT NULL,
        plot_number VARCHAR(20) DEFAULT NULL,
        row_number INT DEFAULT NULL,
        contract_start_date DATE DEFAULT NULL,
        contract_end_date DATE DEFAULT NULL,
        contract_type ENUM('perpetual', 'temporary', 'lease') DEFAULT 'temporary',
        contract_status ENUM('active', 'expired', 'renewal_needed', 'cancelled') DEFAULT 'active',
        contract_notes TEXT DEFAULT NULL,
        renewal_reminder_date DATE DEFAULT NULL,
        burial_date DATE DEFAULT NULL,
        date_of_death DATE DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        next_of_kin VARCHAR(255) DEFAULT NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        archived_by INT DEFAULT NULL,
        reason VARCHAR(255) NOT NULL,
        INDEX idx_plot_id (plot_id),
        INDEX idx_record_id (record_id),
        INDEX idx_archived_at (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!mysqli_query($conn, $create_table_sql)) {
        header('Location: contracts.php?error=table_creation_failed');
        exit();
    }
}

// Detect which deceased table to use
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
$use_deceased_records = $table_check && mysqli_num_rows($table_check) > 0;
if ($table_check) {
    mysqli_free_result($table_check);
}

$archive_check = mysqli_query($conn, "SHOW TABLES LIKE 'archived_deceased_records'");
$has_archive_table = $archive_check && mysqli_num_rows($archive_check) > 0;
if ($archive_check) {
    mysqli_free_result($archive_check);
}

// Fetch contract and plot information before archiving
if ($use_deceased_records) {
    $address_select = "";
    $address_check = mysqli_query($conn, "SHOW COLUMNS FROM deceased_records LIKE 'address'");
    $has_address_column = $address_check && mysqli_num_rows($address_check) > 0;
    if ($address_check) {
        mysqli_free_result($address_check);
    }
    if ($has_address_column) {
        $address_select = "d.address AS address,";
    }
    
    $name_expr = "COALESCE(d.full_name, " . ($has_archive_table ? "CONCAT(ad.first_name, ' ', ad.last_name)" : "NULL") . ")";
    
    $query = "SELECT 
                {$name_expr} AS display_name" .
                ($has_address_column ? ",\n                {$address_select}" : "") . "
                p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                COALESCE(d.burial_date" . ($has_archive_table ? ", ad.date_of_burial" : "") . ") AS burial_date,
                COALESCE(d.date_of_death" . ($has_archive_table ? ", ad.date_of_death" : "") . ") AS date_of_death" .
                ($has_address_column ? ",\n                COALESCE(d.address" . ($has_archive_table ? ", ad.address" : "") . ") AS address" : "") . ",
                d.next_of_kin AS next_of_kin
              FROM plots p
              LEFT JOIN deceased_records d ON d.plot_id = p.plot_id " .
              ($has_archive_table ? "LEFT JOIN archived_deceased_records ad ON ad.plot_id = p.plot_id " : "") . "
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.plot_id = ?";
    
    if ($record_id) {
        $query .= " AND (d.record_id = ?" . ($has_archive_table ? " OR ad.deceased_id = ?" : "") . ")";
    }
} else {
    $query = "SELECT 
                CONCAT(d.first_name, ' ', d.last_name) AS display_name,
                p.plot_id,
                p.plot_number,
                p.row_number,
                p.contract_start_date,
                p.contract_end_date,
                p.contract_type,
                p.contract_status,
                p.renewal_reminder_date,
                p.contract_notes,
                s.section_name,
                d.date_of_burial AS burial_date,
                d.date_of_death AS date_of_death,
                NULL AS next_of_kin
              FROM deceased d
              JOIN plots p ON d.plot_id = p.plot_id
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE p.plot_id = ?";
    
    if ($record_id) {
        $query .= " AND d.deceased_id = ?";
    }
}

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    if ($record_id && $use_deceased_records && $has_archive_table) {
        mysqli_stmt_bind_param($stmt, "iii", $plot_id, $record_id, $record_id);
    } elseif ($record_id) {
        mysqli_stmt_bind_param($stmt, "ii", $plot_id, $record_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $plot_id);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $contract_data = mysqli_fetch_assoc($result);
} else {
    header('Location: contracts.php?error=fetch_failed');
    exit();
}

if (!$contract_data) {
    header('Location: contracts.php?error=contract_not_found');
    exit();
}

// Get current user ID for archived_by
$archived_by = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

// Archive the contract data
$archive_query = "INSERT INTO archived_contracts (
    plot_id, record_id, deceased_name, section_name, plot_number, row_number,
    contract_start_date, contract_end_date, contract_type, contract_status,
    contract_notes, renewal_reminder_date, burial_date, date_of_death,
    address, next_of_kin, archived_by, reason
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$archive_stmt = mysqli_prepare($conn, $archive_query);
if ($archive_stmt) {
    mysqli_stmt_bind_param(
        $archive_stmt,
        "iisssissssssssssss",
        $plot_id,
        $record_id,
        $contract_data['display_name'] ?? '',
        $contract_data['section_name'] ?? null,
        $contract_data['plot_number'] ?? null,
        $contract_data['row_number'] ?? null,
        $contract_data['contract_start_date'] ?? null,
        $contract_data['contract_end_date'] ?? null,
        $contract_data['contract_type'] ?? 'temporary',
        $contract_data['contract_status'] ?? 'active',
        $contract_data['contract_notes'] ?? null,
        $contract_data['renewal_reminder_date'] ?? null,
        $contract_data['burial_date'] ?? null,
        $contract_data['date_of_death'] ?? null,
        $contract_data['address'] ?? null,
        $contract_data['next_of_kin'] ?? null,
        $archived_by,
        $reason
    );
    
    if (mysqli_stmt_execute($archive_stmt)) {
        // Clear contract fields from plots table (but don't delete the plot or deceased record)
        $clear_query = "UPDATE plots SET 
            contract_start_date = NULL,
            contract_end_date = NULL,
            contract_type = 'perpetual',
            contract_status = 'active',
            contract_notes = NULL,
            renewal_reminder_date = NULL
            WHERE plot_id = ?";
        
        $clear_stmt = mysqli_prepare($conn, $clear_query);
        if ($clear_stmt) {
            mysqli_stmt_bind_param($clear_stmt, "i", $plot_id);
            mysqli_stmt_execute($clear_stmt);
        }
        
        header('Location: contracts.php?success=contract_archived');
        exit();
    } else {
        header('Location: contracts.php?error=archive_failed');
        exit();
    }
} else {
    header('Location: contracts.php?error=archive_prepare_failed');
    exit();
}
?>

