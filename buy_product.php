<?php
session_start();
require 'db.php';

if (isset($_POST['buy_product'])) {
    $product_id = intval($_POST['product_id']);
    $quantity   = intval($_POST['quantity']);
    $customer_name = $_SESSION['username'] ?? 'Guest'; // or pull from session/login

    if ($quantity <= 0) {
        $_SESSION['flash'] = "Invalid quantity!";
        header("Location: buy_product.php"); exit;
    }

    // Get product info
    $stmt = $conn->prepare("SELECT name, price FROM stock WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $stmt->bind_result($productName, $price);
    if (!$stmt->fetch()) {
        $_SESSION['flash'] = "Product not found!";
        header("Location: buy_product.php"); exit;
    }
    $stmt->close();

    // Start transaction (so order + items are always together)
    $conn->begin_transaction();
    try {
        // 1. Create new order in orders table
        $orderSql = "INSERT INTO orders (customer_name, status) VALUES (?, 'Pending')";
        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->bind_param("s", $customer_name);
        $orderStmt->execute();
        $order_id = $conn->insert_id;
        $orderStmt->close();

        // 2. Insert order item
        $itemSql = "INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)";
        $itemStmt = $conn->prepare($itemSql);
        $itemStmt->bind_param("iii", $order_id, $product_id, $quantity);
        $itemStmt->execute();
        $itemStmt->close();

        $conn->commit();
        $_SESSION['flash'] = "Order #$order_id created for $productName ($quantity pcs). Waiting for admin approval.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = "Error creating order: " . $e->getMessage();
    }

    header("Location: buy_product.php"); exit;
}
?>


<!DOCTYPE html>
<html>
<head>
  <title>Buy Product</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
  <h2>Buy Product</h2>

  <?php if (isset($_SESSION['flash'])): ?>
    <div class="alert alert-info"><?= $_SESSION['flash']; unset($_SESSION['flash']); ?></div>
  <?php endif; ?>

  <form method="POST" class="mb-4">
    <div class="mb-3">
      <label for="product_id" class="form-label">Choose Product</label>
      <select name="product_id" id="product_id" class="form-select" required>
        <?php
        $products = $conn->query("SELECT id, name, price, qty FROM stock WHERE qty > 0");
        while ($row = $products->fetch_assoc()):
        ?>
          <option value="<?= $row['id'] ?>">
            <?= htmlspecialchars($row['name']) ?> - ₱<?= number_format($row['price'], 2) ?> (Available: <?= $row['qty'] ?>)
          </option>
        <?php endwhile; ?>
      </select>
    </div>

    <div class="mb-3">
      <label for="quantity" class="form-label">Quantity</label>
      <input type="number" name="quantity" id="quantity" class="form-control" min="1" required>
    </div>

    <button type="submit" name="buy_product" class="btn btn-primary">Buy Now</button>
  </form>
</div>
</body>
</html>
