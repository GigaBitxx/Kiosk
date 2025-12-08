<?php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'cemetery_db';

// Create connection
$conn = mysqli_connect($host, $username, $password, $database);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
// No closing PHP tag to prevent whitespace issues