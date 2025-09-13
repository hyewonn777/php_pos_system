<?php
session_start();
require 'db.php'; // <-- your mysqli connection

// CREATE
if (isset($_POST['add_sale'])) {
    $date = $_POST['sale_date'];
    $product = $_POST['product'];
    $quantity = intval($_POST['quantity']);
    $total = floatval($_POST['total']);

    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssii", $date, $product, $quantity, $total);
    $stmt->execute();
    $stmt->close();

    header("Location: sales.php");
    exit;
}

// UPDATE
if (isset($_POST['update_sale'])) {
    $id = intval($_POST['id']);
    $date = $_POST['sale_date'];
    $product = $_POST['product'];
    $quantity = intval($_POST['quantity']);
    $total = floatval($_POST['total']);

    $stmt = $conn->prepare("UPDATE sales SET sale_date=?, product=?, quantity=?, total=? WHERE id=?");
    $stmt->bind_param("ssidi", $date, $product, $quantity, $total, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: sales.php");
    exit;
}

// DELETE
if (isset($_POST['delete_sale'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM sales WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: sales.php");
    exit;
}
// READ
$result = $conn->query("SELECT * FROM sales ORDER BY sale_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sales & Tracking</title>
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
      margin: 0; font-family: Arial, sans-serif;
      background: var(--bg); color: var(--text);
      display: flex; transition: all 0.3s ease;
    }
    .sidebar {
      width: 220px; background: var(--sidebar-bg); color: var(--sidebar-text);
      height: 100vh; padding: 20px; display: flex; flex-direction: column;
    }
    .sidebar h2 { text-align: center; margin-bottom: 20px; }
    .sidebar ul { list-style: none; padding: 0; flex: 1; }
    .sidebar ul li { margin: 15px 0; }
    .sidebar ul li a { color: var(--sidebar-text); text-decoration: none; }
    .sidebar ul li a:hover { text-decoration: underline; }
    .logout { text-align: center; margin-top: auto; }
    .logout button {
      width: 100%; padding: 8px; border: none; border-radius: 5px;
      background: #e74c3c; color: white; font-weight: bold; cursor: pointer;
    }
    .logout button:hover { background: #c0392b; }

    .content { flex: 1; padding: 20px; }
    .topbar { display: flex; justify-content: flex-end; margin-bottom: 20px; }
    .cards {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px; margin-top: 20px;
    }
    .card {
      background: var(--card-bg); padding: 20px; border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center; font-size: 18px; font-weight: bold;
      transition: transform 0.2s ease;
    }
    .card:hover { transform: translateY(-3px); }
    button.toggle-btn {
      cursor: pointer; padding: 8px 12px; border-radius: 5px;
      border: none; background: #3498db; color: white; font-weight: bold;
    }
    button.toggle-btn:hover { background: #2980b9; }
  </style>
</head>
<body>

  <div class="sidebar">
    <h2>Admin Panel</h2>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php">Sales & Tracking</a></li>
      <li><a href="stock.php">Product / Stock</a></li>
      <li><a href="appointment.php">Appointments / Booking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
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
    <button class="toggle-btn" onclick="toggleTheme()">🌙 Toggle Dark Mode</button>
   </div>
    <h1>Sales & Tracking</h1>

<!-- Add Sale Form -->
<form method="POST" style="margin-bottom:20px;">
  <input type="date" name="sale_date" required>
  <input type="text" name="product" placeholder="Product" required>
  <input type="number" name="quantity" placeholder="Qty" required>
  <input type="number" step="0.01" name="total" placeholder="Total ₱" required>
  <button type="submit" name="add_sale">Add Sale</button>
</form>

<!-- Sales Table -->
<table border="1" cellpadding="10">
  <tr><th>Date 📆</th><th>Product 📦</th><th>Quantity 🏷️</th><th>Total 🟰</th><th>Action 🗳️</th></tr>
  <?php while ($row = $result->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($row['sale_date']) ?></td>
      <td><?= htmlspecialchars($row['product']) ?></td>
      <td><?= htmlspecialchars($row['quantity']) ?></td>
      <td>₱<?= number_format($row['total'], 2) ?></td>
      <td>
        <!-- Edit -->
        <form method="POST" style="display:inline;">
          <input type="hidden" name="id" value="<?= $row['id'] ?>">
          <input type="date" name="sale_date" value="<?= $row['sale_date'] ?>" required>
          <input type="text" name="product" value="<?= $row['product'] ?>" required>
          <input type="number" name="quantity" value="<?= $row['quantity'] ?>" required>
          <input type="number" step="0.01" name="total" value="<?= $row['total'] ?>" required>
          <button type="submit" name="update_sale">Save 💾</button>
        </form>

        <!-- Delete -->
        <form method="POST" style="display:inline;">
          <input type="hidden" name="id" value="<?= $row['id'] ?>">
          <button type="submit" name="delete_sale" onclick="return confirm('Are you sure you want to delete this sale?');">Delete 🗑️</button>
        </form>
      </td>
    </tr>
  <?php endwhile; ?>
</table>

  </div>
   <script>
    function toggleTheme() {
      document.body.classList.toggle("dark");
      localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
    }
    if (localStorage.getItem("theme") === "dark") {
      document.body.classList.add("dark");
    }
  </script>

    <script>
    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText =
    now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
      updateClock();
   </script>
</body>
</html>
