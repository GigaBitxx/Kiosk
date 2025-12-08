<?php
require_once '../config/database.php';

// Read the SQL file
$sql = file_get_contents('events.sql');

// Execute the SQL
if ($conn->multi_query($sql)) {
    echo "Events table created successfully";
} else {
    echo "Error creating events table: " . $conn->error;
}

$conn->close();
?> 