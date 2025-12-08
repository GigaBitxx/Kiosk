<?php
require_once '../config/database.php';
require_once __DIR__ . '/contract_maintenance.php';

/**
 * Convert a numeric row index (1 = A) into its letter representation.
 * Supports numbers beyond 26 (27 = AA, 28 = AB, etc.)
 */
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }

    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1) {
        return '';
    }

    $letter = '';
    while ($rowNumber > 0) {
        $remainder = ($rowNumber - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $rowNumber = intval(($rowNumber - 1) / 26);
    }
    return $letter;
}

// This script can be run as a cron job to automatically update contract statuses
// based on end dates and renewal reminder dates

echo "Starting contract status check...\n";

// Run core maintenance logic (silent, returns summary)
$summary = run_contract_maintenance($conn, true);

echo "Updated {$summary['expired']} expired contracts (status only).\n";
echo "Auto-archived {$summary['archived']} deceased record(s) and freed {$summary['freed_plots']} plot(s) due to expired contracts.\n";
echo "Updated {$summary['renewal_needed']} contracts to renewal needed status.\n";

// After core maintenance, generate a simple list of contracts that still need renewal attention
$reminder_query = "SELECT p.plot_id, p.plot_number, p.row_number, s.section_name, d.full_name, p.contract_end_date
                   FROM plots p
                   JOIN sections s ON p.section_id = s.section_id
                   LEFT JOIN deceased_records d ON p.plot_id = d.plot_id
                   WHERE p.contract_status = 'renewal_needed'
                   AND p.contract_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
$result = mysqli_query($conn, $reminder_query);

if ($result && mysqli_num_rows($result) > 0) {
    echo "\nContracts requiring renewal attention:\n";
    echo "=====================================\n";
    while ($row = mysqli_fetch_assoc($result)) {
        $rowLetter = rowNumberToLetter($row['row_number'] ?? 1);
        $plotLocation = ($row['section_name'] ?? '') . '-' . $rowLetter . ($row['plot_number'] ?? '');
        echo "Plot: {$plotLocation}\n";
        echo "Deceased: {$row['full_name']}\n";
        echo "Expires: " . date('M d, Y', strtotime($row['contract_end_date'])) . "\n";
        echo "---\n";
    }
}

echo "\nContract status check completed.\n";
mysqli_close($conn);
?>
