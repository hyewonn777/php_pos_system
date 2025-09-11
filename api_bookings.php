<?php require 'auth.php'; require 'db.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method==='GET'){
  $year = (int)($_GET['year'] ?? date('Y'));
  $month = (int)($_GET['month'] ?? date('n')); // 1-12
  $media = $_GET['media'] ?? 'Photography';    // or Videography

  $start = sprintf('%04d-%02d-01', $year, $month);
  $end = date('Y-m-t', strtotime($start));

  $stmt = $conn->prepare("SELECT id, client_name, media_type, event_date, status FROM bookings WHERE media_type=? AND event_date BETWEEN ? AND ?");
  $stmt->bind_param("sss", $media, $start, $end);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
  $stmt->close();

  header('Content-Type: application/json'); echo json_encode($rows); exit;
}

if ($method==='POST'){
  $client = trim($_POST['client_name'] ?? '');
  $media  = $_POST['media_type'] ?? 'Photography';
  $date   = $_POST['event_date'] ?? '';
  $status = $_POST['status'] ?? 'Pending';
  $notes  = $_POST['notes'] ?? null;

  $stmt = $conn->prepare("INSERT INTO bookings (client_name, media_type, event_date, status, notes) VALUES (?,?,?,?,?)");
  $stmt->bind_param("sssss", $client, $media, $date, $status, $notes);
  $ok = $stmt->execute();
  $err = $conn->error;
  $stmt->close();

  header('Content-Type: application/json');
  echo json_encode(['ok'=>$ok,'error'=>$ok?null:$err]); exit;
}

http_response_code(405);
