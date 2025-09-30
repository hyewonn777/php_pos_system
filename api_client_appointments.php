<?php
require 'db.php';

// Receive client submission (POST)
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$customer   = trim($input['customer'] ?? '');
$date       = $input['date'] ?? '';
$start_time = $input['start_time'] ?? '';
$end_time   = $input['end_time'] ?? '';
$location   = $input['location'] ?? '';
$status     = 'Pending';   // all new appointments are pending

if (!$customer || !$date || !$start_time || !$end_time || !$location) {
    echo json_encode(['ok' => false, 'error' => 'All fields are required']);
    exit;
}

// Insert into appointments table
$stmt = $conn->prepare("
    INSERT INTO appointments (customer, event_date, start_time, end_time, location, status)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("ssssss", $customer, $date, $start_time, $end_time, $location, $status);
$ok = $stmt->execute();
$err = $conn->error;
$stmt->close();

// Return JSON feedback
header('Content-Type: application/json');
echo json_encode(['ok' => $ok, 'error' => $ok ? null : $err]);
