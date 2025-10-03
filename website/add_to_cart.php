<?php
// website/add_to_cart.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db.php';

// Require login
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to add to cart.']);
    exit;
}

// read JSON payload
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['item_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$item_id = (int) $input['item_id'];
$quantity = isset($input['quantity']) ? max(1, (int)$input['quantity']) : 1;
$design = isset($input['design']) ? $input['design'] : null;

// limit design payload size (protect DB)
$maxBytes = 5 * 1024 * 1024; // 5 MB
if ($design !== null && strlen($design) > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'Design is too large. Reduce image size.']);
    exit;
}

// Create website_orders table if it does not exist (safe migration)
$create_sql = "
CREATE TABLE IF NOT EXISTS website_orders (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  status ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
  design_data MEDIUMTEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($create_sql)) {
    echo json_encode(['success' => false, 'message' => 'DB error (create table): ' . $conn->error]);
    exit;
}

// Optional: verify item exists and is active
$check = $conn->prepare("SELECT id FROM stock WHERE id = ? AND status = 'active' LIMIT 1");
$check->bind_param("i", $item_id);
$check->execute();
$check_res = $check->get_result();
if (!$check_res || $check_res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Product not found or inactive.']);
    exit;
}
$check->close();

// Insert into website_orders
$stmt = $conn->prepare("INSERT INTO website_orders (user_id, item_id, quantity, design_data) VALUES (?, ?, ?, ?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param("iiis", $user_id, $item_id, $quantity, $design);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}

$inserted_id = $stmt->insert_id;
$stmt->close();

echo json_encode(['success' => true, 'order_id' => $inserted_id, 'message' => 'Added to pending orders.']);
exit;
