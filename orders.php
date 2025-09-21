<?php
session_start();
require 'db.php';

/* ---------------- CREATE ORDER ---------------- */
if (isset($_POST['create_order'])) {
    $customer_name = trim($_POST['customer_name']);
    $items = $_POST['items'] ?? [];

    if (empty($items)) {
        $_SESSION['flash'] = "No items selected!";
        header("Location: orders.php"); exit;
    }

    $conn->begin_transaction();
    try {
        // Insert order
        $orderSql = "INSERT INTO orders (customer_name, status) VALUES (?, 'Pending')";
        $orderStmt = $conn->prepare($orderSql);
        if (!$orderStmt) throw new Exception("Prepare failed (orders): " . $conn->error);
        $orderStmt->bind_param("s", $customer_name);
        $orderStmt->execute();
        $order_id = $conn->insert_id;

        // Insert order items
        $itemSql = "INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)";
        $itemStmt = $conn->prepare($itemSql);
        if (!$itemStmt) throw new Exception("Prepare failed (order_items): " . $conn->error);

        foreach ($items as $product_id => $qty_ordered) {
            if ($qty_ordered > 0) {
                $itemStmt->bind_param("iii", $order_id, $product_id, $qty_ordered);
                $itemStmt->execute();
            }
        }

        $conn->commit();
        $_SESSION['flash'] = "Order #$order_id created!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = "Error creating order: " . $e->getMessage();
    }
    header("Location: orders.php"); exit;
}

/* ---------------- CONFIRM ORDER ---------------- */
if (isset($_POST['confirm_order'])) {
    $order_id = intval($_POST['confirm_order']);

    $conn->begin_transaction();
    try {
        // Fetch items
        $items = [];
        $itemsQ = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        if (!$itemsQ) {
            throw new Exception("Prepare failed (fetch order_items): " . $conn->error);
        }
        $itemsQ->bind_param("i", $order_id);
        $itemsQ->execute();
        $result = $itemsQ->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $itemsQ->close();

        foreach ($items as $row) {
            $product_id  = $row['product_id'];
            $qty_ordered = $row['quantity'];

            // Deduct stock
            $updateStock = $conn->prepare("UPDATE stock SET qty = GREATEST(qty - ?, 0) WHERE id = ?");
            $updateStock->bind_param("ii", $qty_ordered, $product_id);
            $updateStock->execute();
            $updateStock->close();

            // Get product price + name
            $getPrice = $conn->prepare("SELECT name, price FROM stock WHERE id = ?");
            $getPrice->bind_param("i", $product_id);
            $getPrice->execute();
            $getPrice->bind_result($productName, $price);
            $getPrice->fetch();
            $getPrice->close();

            // Insert into sales
            $total = $price * $qty_ordered;
            $insertSale = $conn->prepare("INSERT INTO sales (product, quantity, total, sale_date, status) VALUES (?, ?, ?, NOW(), 'Pending')");
            $insertSale->bind_param("sid", $productName, $qty_ordered, $total);
            $insertSale->execute();
            $insertSale->close();
        }

        // Update order status
        $updateOrder = $conn->prepare("UPDATE orders SET status='Confirmed' WHERE id=?");
        $updateOrder->bind_param("i", $order_id);
        $updateOrder->execute();
        $updateOrder->close();

        $conn->commit();
        $_SESSION['flash'] = "Order #$order_id confirmed! Stock updated & sales recorded.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = "Error confirming order: " . $e->getMessage();
    }

    header("Location: orders.php");
    exit;
}


/* ---------------- DELETE / CANCEL ORDERS ---------------- */
if (isset($_POST['delete_orders']) && !empty($_POST['order_ids'])) {
    $order_ids = $_POST['order_ids'];
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));

    $conn->begin_transaction();
    try {
        // Optional: Restock if already confirmed
        $checkConfirmed = $conn->prepare("SELECT id FROM orders WHERE status='Confirmed' AND id IN ($placeholders)");
        if (!$checkConfirmed) {
            throw new Exception("Prepare failed (check confirmed): " . $conn->error);
        }
        $checkConfirmed->bind_param($types, ...$order_ids);
        $checkConfirmed->execute();
        $result = $checkConfirmed->get_result();

        while ($row = $result->fetch_assoc()) {
            $confirmed_id = $row['id'];

            // Fetch order items for restock
            $restockQ = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
            $restockQ->bind_param("i", $confirmed_id);
            $restockQ->execute();
            $res2 = $restockQ->get_result();
            while ($item = $res2->fetch_assoc()) {
                $conn->query("UPDATE stock SET qty = qty + {$item['quantity']} WHERE id={$item['product_id']}");
            }
            $restockQ->close();

            // Cancel related sales
            $conn->query("UPDATE sales SET status='Cancelled' WHERE sale_date IN (SELECT created_at FROM orders WHERE id=$confirmed_id)");
        }
        $checkConfirmed->close();

        // Delete items
        $delItems = $conn->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
        $delItems->bind_param($types, ...$order_ids);
        $delItems->execute();
        $delItems->close();

        // Delete orders
        $delOrders = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        $delOrders->bind_param($types, ...$order_ids);
        $delOrders->execute();
        $delOrders->close();

        $conn->commit();
        $_SESSION['flash'] = "Selected orders cancelled/deleted!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = "Error deleting orders: " . $e->getMessage();
    }

    header("Location: orders.php");
    exit;
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Orders</title>
  <link rel="stylesheet" href="css/admin.css">
  <style>

     :root {
      --bg: #f9f9f9;
      --text: #222;
      --card-bg: #fff;
      --sidebar-bg: #2c3e50;
      --sidebar-text: #ecf0f1;
    }

    .dark {
      --bg: #1e1e1e;
      --text: #f5f5f5;
      --card-bg: #2c2c2c;
      --sidebar-bg: #111;
      --sidebar-text: #bbb;
    }

    body {
      margin: 0; 
      font-family: Arial, sans-serif;
      background: var(--bg); 
      color: var(--text);
      display: flex; 
      transition: all 0.3s ease;
    }

    .sidebar {
      width: 220px; 
      background: var(--sidebar-bg); 
      color: var(--sidebar-text);
      height: 100vh; 
      padding: 20px; 
      display: flex; 
      flex-direction: column;
    }

    .sidebar { 
      width:220px; 
      background:var(--sidebar-bg); 
      color:var(--sidebar-text); 
      height:100vh; 
      padding:20px; 
      display:flex; 
      flex-direction:column; 
    }
    
    .sidebar h2 { 
      text-align:center; 
      margin-bottom:20px; 
    }
    
    .sidebar ul { 
      list-style:none; 
      padding:0; flex:1; 
    }
    
    .sidebar ul li { 
      margin:15px 0; 
    }
    
    .sidebar ul li a { 
      color:var(--sidebar-text); 
      text-decoration:none; 
    }
    
    .content { 
      flex:1; 
      padding:20px; 
    }
    
    .topbar { 
      display:flex; 
      justify-content:flex-end; 
      margin-bottom:20px; 
    }

    .cards {
      display: grid; 
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px; 
      margin-top: 20px;
    }

    .card {
      background: var(--card-bg); 
      padding: 20px; 
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center; 
      font-size: 18px; 
      font-weight: bold;
      transition: transform 0.2s ease;
    }

    .card:hover { 
      transform: translateY(-3px); 
    }

    button.toggle-btn {
      cursor: pointer; 
      padding: 8px 12px; 
      border-radius: 5px;
      border: none; 
      background: #3498db; 
      color: white; 
      font-weight: bold;
    }

    button.toggle-btn:hover { 
        background: #2980b9; 
    }

      /* Table Container */
  table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    background: var(--card-bg);
  }

  thead {
    background: #3498db;
    color: #fff;
    text-align: left;
  }

  thead th {
    padding: 12px 15px;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
  }

  tbody td {
    padding: 12px 15px;
    font-size: 14px;
    color: var(--text);
    border-bottom: 1px solid #ddd;
  }

  tbody tr:hover {
    background: rgba(52, 152, 219, 0.05);
  }

  tbody tr:last-child td {
    border-bottom: none;
  }

  /* Checkbox */
  input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
  }

  /* Buttons */
  button {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    font-size: 13px;
    transition: 0.2s ease;
  }

  button[name="confirm_order"] {
    background: #27ae60;
    color: #fff;
  }

  button[name="confirm_order"]:hover {
    background: #219150;
  }

  button[name="delete_orders"] {
    background: #e74c3c;
    color: #fff;
  }

  button[name="delete_orders"]:hover {
    background: #c0392b;
  }

  /* Total column */
  td:nth-child(5) {
    font-weight: bold;
  }

  /* Status badges */
  td:nth-child(6) {
    font-weight: bold;
    padding: 8px 10px;
    border-radius: 6px;
    text-align: center;
  }

  td:nth-child(6):contains('Pending') {
    background: #f1c40f;
    color: #fff;
  }

  td:nth-child(6):contains('Confirmed') {
    background: #27ae60;
    color: #fff;
  }

  td:nth-child(6):contains('Cancelled') {
    background: #e74c3c;
    color: #fff;
  }

  /* Responsive table */
  @media (max-width: 900px) {
    table, thead, tbody, th, td, tr {
      display: block;
    }

    thead {
      display: none;
    }

    tbody tr {
      margin-bottom: 15px;
      border-radius: 12px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.05);
      background: var(--card-bg);
      padding: 15px;
    }

    tbody td {
      display: flex;
      justify-content: space-between;
      padding: 8px 12px;
      border-bottom: 1px solid #eee;
    }

    tbody td:last-child {
      border-bottom: none;
    }

    tbody td::before {
      content: attr(data-label);
      font-weight: 600;
      color: #555;
    }
  }

  </style>
</head>
<body>
  <div class="sidebar">
    <h2>Admin Panel</h2>
    <div class="logo-box">
        <img src="images/rsz_logo.png" alt="Logo">
    </div>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php">Sales Tracking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="stock.php">Inventory</a></li>
      <li><a href="appointment.php">Appointments</a></li>
      <li><a href="user_management.php">Account</a></li>
    </ul>
    <div class="logout">
      <form action="logout.php" method="POST">
        <button type="submit">Logout</button>
      </form>
    </div>
  </div>

  <div class="content">
    <div class="topbar">
      <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
      <button class="toggle-btn" onclick="toggleTheme()">Toggle Dark Mode</button>
    </div>

    <?php if(!empty($_SESSION['flash'])): ?>
      <div style="background:#27ae60; color:#fff; padding:10px; margin-bottom:10px; border-radius:5px;">
        <?= $_SESSION['flash']; unset($_SESSION['flash']); ?>
      </div>
    <?php endif; ?>

    <h1>Order Tracking</h1>
    <p>Track customer orders and delivery status here.</p>

<form method="POST">
  <table border="1" cellpadding="10">
    <thead>
      <tr>
        <th>Select</th>
        <th>Order ID</th>
        <th>Customer</th>
        <th>Items</th>
        <th>Total</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
<?php
$orders = $conn->query("SELECT * FROM orders ORDER BY id ASC");

if ($orders && $orders->num_rows > 0) {
    while ($order = $orders->fetch_assoc()) {
        $itemsList = [];
        $total = 0;

        $itemsRes = $conn->prepare("SELECT oi.quantity, s.name, s.price 
                                    FROM order_items oi
                                    JOIN stock s ON s.id = oi.product_id
                                    WHERE oi.order_id = ?");
        $itemsRes->bind_param("i", $order['id']);
        $itemsRes->execute();
        $itemsRes->bind_result($qty, $name, $price);

        while ($itemsRes->fetch()) {
            $itemsList[] = "{$qty}x {$name}";
            $total += $qty * $price;
        }
        $itemsRes->close();
?>
<tr>
    <td><input type="checkbox" name="order_ids[]" value="<?= $order['id']; ?>"></td>
    <td><?= $order['id']; ?></td>
    <td><?= htmlspecialchars($order['customer_name']); ?></td>
    <td><?= !empty($itemsList) ? implode(", ", $itemsList) : "No items"; ?></td>
    <td>₱<?= number_format($total, 2); ?></td>
    <td><?= $order['status']; ?></td>
    <td>
        <?php if ($order['status'] != 'Confirmed') { ?>
            <button type="submit" name="confirm_order" value="<?= $order['id']; ?>">Confirm</button>
        <?php } else { echo "Approved"; } ?>
    </td>
</tr>
<?php
    }
} else {
    echo "<tr><td colspan='7' style='text-align:center;'>No orders found.</td></tr>";
}
?>
    </tbody>
  </table>

  <button type="submit" name="delete_orders" style="margin-top:10px;background:#e74c3c;color:#fff;padding:8px 12px;border:none;border-radius:5px;cursor:pointer;">🗑 Delete Selected</button>
</form>


  <script>
    function toggleTheme() {
      document.body.classList.toggle("dark");
      localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
    }
    if (localStorage.getItem("theme") === "dark") {
      document.body.classList.add("dark");
    }

    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText =
        now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();
  </script>

  <script>
    document.getElementById("selectAll").addEventListener("change", function() {
      let checkboxes = document.querySelectorAll("input[name='order_ids[]']");
      checkboxes.forEach(cb => cb.checked = this.checked);
    });
  </script>
</body>
</html>
