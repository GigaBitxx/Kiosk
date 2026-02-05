<?php
/**
 * Fix and cleanup expired contracts
 * This script will:
 * 1. Ensure all contracts have a contract_type set
 * 2. Update contract statuses based on dates
 * 3. Run the maintenance to archive expired contracts (7+ days old)
 * 4. Show a detailed report of what was done
 */

require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';
require_once __DIR__ . '/contract_maintenance.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Expired Contracts - Trece Martires Memorial Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .step {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-left: 4px solid #0d6efd;
            border-radius: 4px;
        }
        .step h3 {
            margin-top: 0;
            color: #0d6efd;
        }
        .result {
            font-family: 'Courier New', monospace;
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            white-space: pre-wrap;
        }
        .success {
            color: #198754;
            font-weight: bold;
        }
        .warning {
            color: #ffc107;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .info {
            color: #0dcaf0;
        }
        .back-btn {
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Fix Expired Contracts</h1>
        <p class="text-muted">This utility will fix and cleanup expired contracts in the system.</p>
        <hr>

        <?php
        // Step 1: Fix missing contract types
        echo '<div class="step">';
        echo '<h3>Step 1: Fix Missing Contract Types</h3>';
        echo '<div class="result">';
        
        $update_query = "UPDATE plots 
                         SET contract_type = 'temporary' 
                         WHERE (contract_start_date IS NOT NULL OR contract_end_date IS NOT NULL)
                         AND (contract_type IS NULL OR contract_type = '')";
        
        $result = mysqli_query($conn, $update_query);
        
        if ($result) {
            $affected = mysqli_affected_rows($conn);
            if ($affected > 0) {
                echo "<span class='success'>✓ Fixed {$affected} plot(s) with missing contract_type</span>\n";
            } else {
                echo "<span class='info'>✓ All contracts already have contract_type set</span>\n";
            }
        } else {
            echo "<span class='error'>✗ Error: " . mysqli_error($conn) . "</span>\n";
        }
        
        echo '</div>';
        echo '</div>';

        // Step 2: Update contract statuses based on dates
        echo '<div class="step">';
        echo '<h3>Step 2: Update Contract Statuses</h3>';
        echo '<div class="result">';
        
        // Mark expired contracts
        $expired_query = "UPDATE plots 
                          SET contract_status = 'expired' 
                          WHERE contract_end_date < CURDATE() 
                          AND contract_status IN ('active', 'renewal_needed')";
        $result = mysqli_query($conn, $expired_query);
        $expired_count = mysqli_affected_rows($conn);
        
        // Mark renewal needed contracts
        $renewal_query = "UPDATE plots 
                          SET contract_status = 'renewal_needed' 
                          WHERE contract_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                          AND contract_status = 'active'";
        $result = mysqli_query($conn, $renewal_query);
        $renewal_count = mysqli_affected_rows($conn);
        
        if ($expired_count > 0) {
            echo "<span class='warning'>⚠ Marked {$expired_count} contract(s) as expired</span>\n";
        }
        if ($renewal_count > 0) {
            echo "<span class='info'>→ Marked {$renewal_count} contract(s) as renewal needed</span>\n";
        }
        if ($expired_count === 0 && $renewal_count === 0) {
            echo "<span class='info'>✓ All contract statuses are up to date</span>\n";
        }
        
        echo '</div>';
        echo '</div>';

        // Step 3: Show contracts that will be archived
        echo '<div class="step">';
        echo '<h3>Step 3: Contracts Pending Archive (7+ Days Expired)</h3>';
        echo '<div class="result">';
        
        $pending_query = "SELECT 
            p.plot_id,
            p.plot_number,
            p.row_number,
            s.section_name,
            p.contract_end_date,
            p.contract_type,
            p.contract_status,
            dr.full_name,
            DATEDIFF(CURDATE(), p.contract_end_date) as days_since_expired
        FROM plots p
        LEFT JOIN sections s ON p.section_id = s.section_id
        LEFT JOIN deceased_records dr ON p.plot_id = dr.plot_id
        WHERE p.contract_end_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND p.contract_end_date IS NOT NULL
        AND p.contract_type = 'temporary'
        AND p.contract_status = 'expired'
        AND dr.record_id IS NOT NULL";
        
        $result = mysqli_query($conn, $pending_query);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $count = mysqli_num_rows($result);
            echo "<span class='warning'>Found {$count} contract(s) that will be archived:</span>\n\n";
            while ($row = mysqli_fetch_assoc($result)) {
                $section = $row['section_name'] ?? 'Unknown';
                $row_letter = $row['row_number'] ? chr(64 + $row['row_number']) : '?';
                $plot = $section . '-' . $row_letter . ($row['plot_number'] ?? '?');
                
                echo "  • {$plot}: {$row['full_name']}\n";
                echo "    Expired: {$row['contract_end_date']} ({$row['days_since_expired']} days ago)\n";
            }
        } else {
            echo "<span class='info'>✓ No contracts pending archive</span>\n";
        }
        
        echo '</div>';
        echo '</div>';

        // Step 4: Run maintenance
        echo '<div class="step">';
        echo '<h3>Step 4: Run Contract Maintenance</h3>';
        echo '<div class="result">';
        
        $summary = run_contract_maintenance($conn, true);
        
        echo "<span class='info'>Maintenance completed:</span>\n\n";
        echo "  • Updated {$summary['expired']} contract(s) to expired status\n";
        if ($summary['archived'] > 0) {
            echo "  • <span class='success'>Archived {$summary['archived']} deceased record(s)</span>\n";
        } else {
            echo "  • Archived {$summary['archived']} deceased record(s)\n";
        }
        if ($summary['freed_plots'] > 0) {
            echo "  • <span class='success'>Freed {$summary['freed_plots']} plot(s)</span>\n";
        } else {
            echo "  • Freed {$summary['freed_plots']} plot(s)\n";
        }
        echo "  • Updated {$summary['renewal_needed']} contract(s) to renewal needed status\n";
        
        echo '</div>';
        echo '</div>';

        // Step 5: Show current state
        echo '<div class="step">';
        echo '<h3>Step 5: Current State Summary</h3>';
        echo '<div class="result">';
        
        $stats_query = "SELECT 
            SUM(CASE WHEN contract_status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN contract_status = 'renewal_needed' THEN 1 ELSE 0 END) as renewal_needed,
            SUM(CASE WHEN contract_status = 'expired' THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied
        FROM plots
        WHERE contract_start_date IS NOT NULL OR contract_end_date IS NOT NULL OR status = 'occupied'";
        
        $result = mysqli_query($conn, $stats_query);
        $stats = mysqli_fetch_assoc($result);
        
        echo "Contract Statuses:\n";
        echo "  • Active: {$stats['active']}\n";
        echo "  • Renewal Needed: {$stats['renewal_needed']}\n";
        echo "  • Expired: {$stats['expired']}\n\n";
        echo "Plot Statuses:\n";
        echo "  • Available: {$stats['available']}\n";
        echo "  • Occupied: {$stats['occupied']}\n";
        
        echo '</div>';
        echo '</div>';

        mysqli_close($conn);
        ?>

        <div class="back-btn">
            <a href="contracts.php" class="btn btn-primary">← Back to Contracts</a>
            <a href="plots.php" class="btn btn-secondary">View Plots</a>
        </div>
    </div>
</body>
</html>
