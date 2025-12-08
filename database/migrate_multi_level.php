<?php
require_once '../config/database.php';

// Start transaction
mysqli_begin_transaction($conn);

try {
    echo "Starting multi-level tomb migration...<br>";
    
    // Check if level_number column already exists
    $check_column = "SHOW COLUMNS FROM plots LIKE 'level_number'";
    $column_exists = mysqli_query($conn, $check_column);
    
    if (mysqli_num_rows($column_exists) == 0) {
        echo "Adding level_number column to plots table...<br>";
        $query = "ALTER TABLE plots ADD COLUMN level_number INT DEFAULT 1";
        mysqli_query($conn, $query);
        
        echo "Adding max_levels column to plots table...<br>";
        $query = "ALTER TABLE plots ADD COLUMN max_levels INT DEFAULT 1";
        mysqli_query($conn, $query);
        
        echo "Adding is_multi_level column to plots table...<br>";
        $query = "ALTER TABLE plots ADD COLUMN is_multi_level BOOLEAN DEFAULT FALSE";
        mysqli_query($conn, $query);
    }
    
    // Check if sections table needs updates
    $check_section_column = "SHOW COLUMNS FROM sections LIKE 'has_multi_level'";
    $section_column_exists = mysqli_query($conn, $check_section_column);
    
    if (mysqli_num_rows($section_column_exists) == 0) {
        echo "Adding multi-level fields to sections table...<br>";
        $query = "ALTER TABLE sections ADD COLUMN has_multi_level BOOLEAN DEFAULT FALSE";
        mysqli_query($conn, $query);
        
        $query = "ALTER TABLE sections ADD COLUMN max_levels INT DEFAULT 1";
        mysqli_query($conn, $query);
    }
    
    // Update existing multi-level tomb sections
    echo "Updating existing multi-level tomb sections...<br>";
    $update_query = "UPDATE sections 
                     SET has_multi_level = TRUE, max_levels = 4 
                     WHERE section_name LIKE '%Multi-Level%' 
                     OR section_name LIKE '%MULTI LEVEL%'
                     OR section_name LIKE '%Multi Level%'";
    mysqli_query($conn, $update_query);
    
    // Update existing plots in multi-level sections
    echo "Updating existing plots in multi-level sections...<br>";
    $update_plots_query = "UPDATE plots p 
                          JOIN sections s ON p.section_id = s.section_id 
                          SET p.is_multi_level = TRUE, p.max_levels = s.max_levels 
                          WHERE s.has_multi_level = TRUE";
    mysqli_query($conn, $update_plots_query);
    
    // Add indexes for better performance
    echo "Adding indexes...<br>";
    try {
        $query = "CREATE INDEX idx_plots_level ON plots(level_number)";
        mysqli_query($conn, $query);
    } catch (Exception $e) {
        echo "Index idx_plots_level may already exist<br>";
    }
    
    try {
        $query = "CREATE INDEX idx_plots_multi_level ON plots(is_multi_level)";
        mysqli_query($conn, $query);
    } catch (Exception $e) {
        echo "Index idx_plots_multi_level may already exist<br>";
    }
    
    // Commit transaction
    mysqli_commit($conn);
    echo "<br><strong>Migration completed successfully!</strong><br>";
    echo "<a href='../staff/plots.php'>Go to Plots Management</a> | <a href='../map.php'>View Map</a>";

} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    echo "<br><strong>Migration failed:</strong> " . $e->getMessage();
}
?>
