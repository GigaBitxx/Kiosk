<?php
require_once '../config/database.php';

// Check if columns already exist
$check_query = "SHOW COLUMNS FROM sections LIKE 'label_lat_offset'";
$result = mysqli_query($conn, $check_query);

if (mysqli_num_rows($result) == 0) {
    // Add label position offset fields to sections table
    $query = "ALTER TABLE sections 
              ADD COLUMN label_lat_offset DECIMAL(10, 8) DEFAULT 0,
              ADD COLUMN label_lng_offset DECIMAL(11, 8) DEFAULT 0";
    
    if (mysqli_query($conn, $query)) {
        echo "Successfully added label_lat_offset and label_lng_offset columns to sections table!";
    } else {
        echo "Error adding columns: " . mysqli_error($conn);
    }
} else {
    echo "Columns already exist in sections table.";
}
?>

