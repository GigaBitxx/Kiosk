<?php
require_once __DIR__ . '/../config/database.php';

// Read the SQL file
$sql = file_get_contents(__DIR__ . '/deceased_records.sql');

// Execute the SQL
if (mysqli_multi_query($conn, $sql)) {
    echo "Deceased records table created successfully";
} else {
    echo "Error creating table: " . mysqli_error($conn);
}

mysqli_close($conn);
?> 