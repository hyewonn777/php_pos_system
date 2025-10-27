<?php
require 'db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    echo "Database connected successfully!";
    
    // Test query
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo " Users in database: " . $row['count'];
    } else {
        echo " Query failed: " . $conn->error;
    }
}
?>