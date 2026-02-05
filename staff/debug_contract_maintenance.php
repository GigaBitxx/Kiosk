<?php
require_once '../config/database.php';
require_once __DIR__ . '/contract_maintenance.php';

header('Content-Type: text/plain');

echo "=== Contract Maintenance Debug ===\n\n";

// Check for expired contracts that should be archived
$debug_query = "SELECT 
    p.plot_id,
    p.plot_number,
    p.row_number,
    s.section_name,
    p.contract_end_date,
    p.contract_type,
    p.contract_status,
    p.status,
    dr.record_id,
    dr.full_name,
    dr.created_at,
    DATEDIFF(CURDATE(), p.contract_end_date) as days_since_expired,
    DATEDIFF(CURDATE(), dr.created_at) as record_age_days
FROM plots p
LEFT JOIN sections s ON p.section_id = s.section_id
LEFT JOIN deceased_records dr ON p.plot_id = dr.plot_id
WHERE p.contract_status = 'expired'
OR p.contract_end_date < CURDATE()
ORDER BY p.contract_end_date";

$result = mysqli_query($conn, $debug_query);

if (!$result) {
    echo "Error: " . mysqli_error($conn) . "\n";
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo "No expired or expiring contracts found.\n\n";
} else {
    echo "Expired/Expiring Contracts:\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = mysqli_fetch_assoc($result)) {
        $section = $row['section_name'] ?? 'Unknown';
        $plot = $section . '-' . ($row['row_number'] ? chr(64 + $row['row_number']) : '?') . ($row['plot_number'] ?? '?');
        
        echo "Plot: {$plot}\n";
        echo "  Plot ID: {$row['plot_id']}\n";
        echo "  Contract End Date: {$row['contract_end_date']}\n";
        echo "  Days Since Expired: {$row['days_since_expired']}\n";
        echo "  Contract Type: " . ($row['contract_type'] ?? 'NULL') . "\n";
        echo "  Contract Status: " . ($row['contract_status'] ?? 'NULL') . "\n";
        echo "  Plot Status: " . ($row['status'] ?? 'NULL') . "\n";
        
        if ($row['record_id']) {
            echo "  Deceased: {$row['full_name']}\n";
            echo "  Deceased Record ID: {$row['record_id']}\n";
            echo "  Record Created: " . ($row['created_at'] ?? 'NULL') . "\n";
            echo "  Record Age (days): " . ($row['record_age_days'] ?? 'NULL') . "\n";
            
            // Check if should be archived
            $should_archive = true;
            $reasons = [];
            
            if ($row['days_since_expired'] < 7) {
                $should_archive = false;
                $reasons[] = "Not 7+ days expired yet ({$row['days_since_expired']} days)";
            }
            
            if ($row['contract_type'] !== 'temporary') {
                $should_archive = false;
                $reasons[] = "Contract type is not 'temporary' (is: " . ($row['contract_type'] ?? 'NULL') . ")";
            }
            
            if ($row['created_at'] && $row['record_age_days'] < 7) {
                $should_archive = false;
                $reasons[] = "Record not 7+ days old ({$row['record_age_days']} days)";
            }
            
            if ($should_archive) {
                echo "  ** SHOULD BE ARCHIVED **\n";
            } else {
                echo "  -- Cannot archive: " . implode(", ", $reasons) . "\n";
            }
        } else {
            echo "  Deceased: None\n";
            echo "  ** No deceased records to archive **\n";
        }
        
        echo "\n";
    }
}

echo "\n=== Running Maintenance ===\n\n";

$summary = run_contract_maintenance($conn, true);

echo "Results:\n";
echo "  - Updated {$summary['expired']} expired contracts\n";
echo "  - Auto-archived {$summary['archived']} deceased record(s)\n";
echo "  - Freed {$summary['freed_plots']} plot(s)\n";
echo "  - Updated {$summary['renewal_needed']} contracts to renewal needed\n";

echo "\n=== Done ===\n";

mysqli_close($conn);
?>
