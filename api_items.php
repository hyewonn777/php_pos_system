<?php require 'auth.php'; require 'db.php';
$cat = $_GET['category'] ?? '';
if ($cat==='ALL' || $cat==='') {
  $stmt = $conn->prepare("SELECT id, name, category, price, image_path FROM stock ORDER BY category, name");
} else {
  $stmt = $conn->prepare("SELECT id, name, category, price, image_path FROM stock WHERE category=? ORDER BY name");
  $stmt->bind_param("s", $cat);
}
$stmt->execute();
$res = $stmt->get_result();
$out=[]; while($r=$res->fetch_assoc()) $out[]=$r;
header('Content-Type: application/json'); echo json_encode($out);
$stmt->close();
