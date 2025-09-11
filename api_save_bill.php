<?php require 'auth.php'; require 'db.php';
$data = json_decode(file_get_contents('php://input'), true);
$items = $data['items'] ?? [];
$total = 0;
foreach($items as $it){ $total += ($it['price'] * $it['qty']); }

$stmt = $conn->prepare("INSERT INTO bills (created_by, total) VALUES (?, ?)");
$who = $_SESSION['admin_user'];
$stmt->bind_param("sd", $who, $total);
$stmt->execute();
$billId = $stmt->insert_id;
$stmt->close();

$bi = $conn->prepare("INSERT INTO bill_items (bill_id, product_id, name, qty, price) VALUES (?,?,?,?,?)");
foreach($items as $it){
  $bi->bind_param("iisid", $billId, $it['id'], $it['name'], $it['qty'], $it['price']);
  $bi->execute();
}
$bi->close();

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'bill_id'=>$billId,'total'=>$total]);
