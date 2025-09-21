<?php
$servername = "localhost";
$username = "root";   // change this
$password = "";   // change this
$dbname = "pos";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
