<?php
// Prefer environment variables (Railway, .env, etc.), fall back to local defaults.
$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'cemetery_db';

// Create connection (include port)
$conn = mysqli_connect($host, $username, $password, $database, (int)$port);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
// No closing PHP tag to prevent whitespace issues