<?php
require_once '../config/database.php';

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Check if contract fields already exist
    $check_columns = "SHOW COLUMNS FROM plots LIKE 'contract_start_date'";
    $columns_exist = mysqli_query($conn, $check_columns);
    
    if (mysqli_num_rows($columns_exist) == 0) {
        // Read and execute the SQL file
        $sql = file_get_contents(__DIR__ . '/add_contract_fields.sql');
        
        if (mysqli_multi_query($conn, $sql)) {
            // Clear any remaining results
            while (mysqli_next_result($conn)) {
                if ($result = mysqli_store_result($conn)) {
                    mysqli_free_result($result);
                }
            }
            echo "Contract fields added successfully to plots and deceased_records tables!";
        } else {
            throw new Exception("Error adding contract fields: " . mysqli_error($conn));
        }
    } else {
        echo "Contract fields already exist in the database.";
    }

    // Commit transaction
    mysqli_commit($conn);

} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    echo "Migration failed: " . $e->getMessage();
}

mysqli_close($conn);
?>
