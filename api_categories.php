<?php require 'auth.php'; require 'db.php';
$res = $conn->query("SELECT DISTINCT category FROM stock ORDER BY category");
$data=[]; while($r=$res->fetch_assoc()){$data[]=$r['category'];}
header('Content-Type: application/json'); echo json_encode($data);
