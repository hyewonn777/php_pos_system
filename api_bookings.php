<?php
require 'auth.php';
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Optional filters: year, month, media
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $media = $_GET['media'] ?? 'Photography';

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $stmt = $conn->prepare("
        SELECT id, client_name, media_type, event_date, status, notes
        FROM bookings
        WHERE media_type = ? AND event_date BETWEEN ? AND ?
        ORDER BY event_date ASC
    ");
    $stmt->bind_param("sss", $media, $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    // Accept both form POST and JSON POST
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $client = trim($input['client_name'] ?? '');
    $media  = $input['media_type'] ?? 'Photography';
    $date   = $input['event_date'] ?? '';
    $status = 'Pending'; // always mark new submissions as Pending
    $notes  = $input['notes'] ?? null;

    if (!$client || !$date) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Client name and date are required']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO bookings (client_name, media_type, event_date, status, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssss", $client, $media, $date, $status, $notes);

    $ok = $stmt->execute();
    $err = $conn->error;
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : $err]);
    exit;
}

// If neither GET nor POST
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
