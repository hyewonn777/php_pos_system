<?php
// db.php - Improved MySQLi connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "pos";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    // Don't die in production, handle gracefully
    $db_error = "Database connection failed. Please try again later.";
}
?>