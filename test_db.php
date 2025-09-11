<?php
require 'db.php';

$category = "Test Category";
$name = "Test Product";
$price = 99.99;
$image = "uploads/test.jpg";

$stmt = $conn->prepare("INSERT INTO stock (category, name, price, image) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssds", $category, $name, $price, $image);

if ($stmt->execute()) {
    echo "✅ Insert success!";
} else {
    echo "❌ Insert failed: " . $conn->error;
}
