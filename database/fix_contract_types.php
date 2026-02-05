<?php
/**
 * Fix contract types for existing contracts
 * Sets contract_type to 'temporary' for all occupied plots that have a contract but missing type
 */

require_once '../config/database.php';

echo "Fixing contract types for existing contracts...\n\n";

// Update plots that have contract dates but no contract_type set
$update_query = "UPDATE plots 
                 SET contract_type = 'temporary' 
                 WHERE (contract_start_date IS NOT NULL OR contract_end_date IS NOT NULL)
                 AND (contract_type IS NULL OR contract_type = '')";

$result = mysqli_query($conn, $update_query);

if ($result) {
    $affected = mysqli_affected_rows($conn);
    echo "✓ Updated {$affected} plot(s) to have contract_type = 'temporary'\n";
} else {
    echo "✗ Error: " . mysqli_error($conn) . "\n";
}

// Show all plots with contracts now
$check_query = "SELECT plot_id, plot_number, row_number, contract_type, contract_status, contract_end_date, status
                FROM plots 
                WHERE contract_start_date IS NOT NULL OR contract_end_date IS NOT NULL
                ORDER BY contract_end_date";

$result = mysqli_query($conn, $check_query);

if ($result && mysqli_num_rows($result) > 0) {
    echo "\nPlots with contracts:\n";
    echo str_repeat("-", 80) . "\n";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "Plot {$row['plot_number']} (Row {$row['row_number']}): ";
        echo "Type={$row['contract_type']}, Status={$row['contract_status']}, ";
        echo "End={$row['contract_end_date']}, Plot Status={$row['status']}\n";
    }
}

echo "\nDone!\n";

mysqli_close($conn);
?>
