<?php
require 'db.php';

$category = "Test Category";
$name = "Test Product";
$price = 99.99;
$image = "uploads/test.jpg";

$sql = "INSERT INTO stock (category, name, price, image) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("❌ SQL Prepare failed: " . $conn->error);
}

$stmt->bind_param("ssds", $category, $name, $price, $image);

if ($stmt->execute()) {
    echo "✅ Insert success!";
} else {
    echo "❌ Insert failed: " . $stmt->error;
}
