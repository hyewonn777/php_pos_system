<?php
require 'db.php';
header('Content-Type: application/json');

$result = $conn->query("SELECT * FROM appointments ORDER BY event_date DESC");
$rows = [];
while($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
echo json_encode($rows);
$conn->close();
