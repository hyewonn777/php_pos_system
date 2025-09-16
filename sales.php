<?php
session_start();
require 'db.php'; // mysqli connection

/* ----------------- SUMMARY TABLE ----------------- */
$check = $conn->query("SELECT id FROM summary WHERE id=1");
if ($check->num_rows === 0) {
    $conn->query("INSERT INTO summary (id, total_sales, total_revenue) VALUES (1, 0, 0)");
}

/* -------------------- CREATE -------------------- */
if (isset($_POST['add_sale'])) {
    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total) VALUES (?, ?, ?, ?)");
    if (!$stmt) die("Prepare failed: " . $conn->error);

    $sale_date = $_POST['sale_date'];
    $product   = $_POST['product'];
    $quantity  = intval($_POST['quantity']);
    $total     = floatval($_POST['total']);

    $stmt->bind_param("ssid", $sale_date, $product, $quantity, $total);
    $stmt->execute();
    $stmt->close();
    header("Location: sales.php");
    exit;
}

/* -------------------- UPDATE -------------------- */
if (isset($_POST['update_sale'])) {
    $stmt = $conn->prepare("UPDATE sales SET sale_date=?, product=?, quantity=?, total=? WHERE id=?");
    if (!$stmt) die("Prepare failed: " . $conn->error);

    $sale_date = $_POST['sale_date'];
    $product   = $_POST['product'];
    $quantity  = intval($_POST['quantity']);
    $total     = floatval($_POST['total']);
    $id        = intval($_POST['id']);

    $stmt->bind_param("ssidi", $sale_date, $product, $quantity, $total, $id);
    $stmt->execute();
    $stmt->close();
    header("Location: sales.php");
    exit;
}

/* -------------------- DELETE -------------------- */
if (isset($_POST['delete_sale'])) {
    $stmt = $conn->prepare("DELETE FROM sales WHERE id=?");
    if (!$stmt) die("Prepare failed: " . $conn->error);

    $id = intval($_POST['id']); // variable first
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: sales.php");
    exit;
}

/* -------------------- MANUAL SUMMARY -------------------- */
if (isset($_POST['update_summary'])) {
    $stmt = $conn->prepare("UPDATE summary SET total_sales=?, total_revenue=? WHERE id=1");
    if (!$stmt) die("Prepare failed: " . $conn->error);

    $manual_sales   = floatval($_POST['manual_sales']);
    $manual_revenue = floatval($_POST['manual_revenue']);

    $stmt->bind_param("dd", $manual_sales, $manual_revenue);
    $stmt->execute();
    $stmt->close();
    header("Location: sales.php");
    exit;
}


/* -------------------- READ SALES -------------------- */
$result = $conn->query("SELECT * FROM sales ORDER BY sale_date DESC");
if (!$result) die("Query failed: " . $conn->error);

$sales = [];
$totalSales = $totalRevenue = 0;
while ($row = $result->fetch_assoc()) {
    $sales[] = $row;
    $totalSales += floatval($row['total']);
    $totalRevenue += floatval($row['total']) * 0.9; // let's assume there's a 10% cost
}
$result->free();
$transactions = count($sales);

/* -------------------- SUMMARY OVERRIDE -------------------- */
$summaryResult = $conn->query("SELECT * FROM summary WHERE id=1");
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = $summaryResult->fetch_assoc();
    if ($summary['total_sales'] > 0 || $summary['total_revenue'] > 0) {
        $totalSales = $summary['total_sales'];
        $totalRevenue = $summary['total_revenue'];
    }
    $summaryResult->free();
}

/* -------------------- PERFORMANCE -------------------- */
$performance = $totalRevenue > 5000 ? "Excellent 🚀" : ($totalRevenue > 1000 ? "Good 👍" : "Needs Improvement ⚠️");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sales & Tracking</title>
  <link rel="stylesheet" href="css/admin.css?v=2">
  <style>

    .logo-box {
      text-align: center; 
      margin-bottom: 20px; 
    }

    .logo-box img {
      width: 100px; 
      height: auto; 
    }

    .logout button {
      width: 100%; 
      padding: 10px; 
      border: none; 
      border-radius: 5px;
      background: #e74c3c; 
      color: white; 
      font-weight: bold; 
      cursor: pointer;
      box-shadow: 0 0 10px rgba(231,76,60,0.7); 
      transition: 0.3s;
    }

    .logout button:hover { 
      background: #c0392b; 
      box-shadow: 0 0 20px rgba(231,76,60,1); 
    }

    /* Clock Glow */
    #clock { 
      font-weight: bold; 
      font-size: 16px; 
      transition: 0.3s; 
    }

    .dark #clock { 
      color: #0ff; 
      text-shadow: 0 0 10px #00f, 0 0 20px #0ff, 0 0 30px #0ff; 
    }

    /* Dark Mode Toggle */
    .toggle-btn {
      padding: 8px 14px; 
      border: none; 
      border-radius: 5px;
      cursor: pointer; 
      font-weight: bold; 
      transition: 0.3s;
    }

    .dark .toggle-btn { 
      background: #2c3e50; 
      color: #0ff; 
      box-shadow: 0 0 10px #00f, 0 0 20px #0ff; 
    }

    /* Sales Table */
    #salesTable { 
      width: 100%; 
      border-collapse: collapse; 
      margin-top: 20px; 
      table-layout: fixed; 
    }

    #salesTable th, #salesTable td {
      border: 1px solid #e3e3e3; 
      padding: 12px 16px;
      text-align: left; 
      vertical-align: middle; 
      word-wrap: break-word;
    }

    #salesTable th { 
      background: #f4f4f4; 
      font-weight: bold; 
    }

    .dark #salesTable th { 
      background: #333; 
    }

    /* Edit Row */
    .edit-row td { 
      background: #fafafa; 
      padding: 12px 16px; 
    }

    .dark .edit-row td { 
      background: #2c2c2c; 
    }

    .edit-row form {
      display: grid; 
      grid-template-columns: repeat(5, 1fr) auto auto;
      gap: 10px; 
      align-items: center;
    }

    .edit-row input, .edit-row button { 
      width: 100%; 
      padding: 8px; 
      box-sizing: border-box; 
    }

    /* Summary Cards */
    .summary { 
      display: flex; 
      gap: 20px; 
      margin: 20px 0; 
    }

    .summary .card {
      flex: 1; 
      padding: 20px; 
      border-radius: 10px;
      text-align: center; 
      background: var(--card-bg);
      font-size: 20px;
      font-weight: bold;
    }

    /* Sidebar */
    .sidebar {
      width: 220px; 
      background: var(--sidebar-bg); 
      color: var(--sidebar-text);
      height: 100vh; 
      padding: 20px; 
      display: flex; 
      flex-direction: column;
    }

    .sidebar h2 { 
      text-align: center; 
      margin-bottom: 20px; 
    }

    .sidebar ul { 
      list-style: none; 
      padding: 0; 
      flex: 1; 
    }

    .sidebar ul li {
      margin: 15px 0; 
    }

    .sidebar ul li a { 
      color: var(--sidebar-text); 
      text-decoration: none; 
    }

    .sidebar ul li a:hover { 
      text-decoration: underline; 
    }

    .topbar { 
      display: flex; 
      justify-content: flex-end; 
      margin-bottom: 20px; 
    }

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

    .search-bar { 
      margin: 10px 0; 
    }

  </style>
</head>
<body>

  <div class="sidebar">
    <h2>Admin Panel</h2>
    <div class="logo-box"><img src="images/rsz_logo.png" alt="Logo"></div>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php" class="active">Sales & Tracking</a></li>
      <li><a href="stock.php">Product / Stock</a></li>
      <li><a href="appointment.php">Appointments / Booking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="user_management.php">Account Management</a></li>
    </ul>
    <div class="logout">
      <form action="logout.php" method="POST"><button type="submit">Logout 🚪</button></form>
    </div>
  </div>

  <div class="content">
    <div class="topbar">
      <div id="clock" style="margin-right:auto;"></div>
      <button class="toggle-btn" onclick="toggleTheme()">🌙 Toggle Dark Mode</button>
    </div>

    <h1>Sales & Tracking</h1>
    <p>Track revenue and sales performance</p><br>

    <!-- Summary -->
    <div class="summary">
      <div class="card"><b>💰 Total Sales: </b><?= number_format($totalSales, 2) ?></div>
      <div class="card"><b>📈 Revenue: </b><?= number_format($totalRevenue, 2) ?></div>
    </div>
    <p><b><?= $transactions ?> Transactions</b> | Performance Status: <b><?= $performance ?></b></p>

    <!-- Admin Manual Override -->
    <form method="POST" style="margin-bottom:20px;">
      <input type="number" step="0.01" name="manual_sales" placeholder="Manual Total Sales" required>
      <input type="number" step="0.01" name="manual_revenue" placeholder="Manual Revenue" required>
      <button type="submit" name="update_summary">Update Summary 📝</button>
    </form>

    <!-- Add Sale Form -->
    <form method="POST" style="margin-bottom:20px;">
      <input type="date" name="sale_date" required>
      <input type="text" name="product" placeholder="Product" required>
      <input type="number" name="quantity" placeholder="Quantity" required>
      <input type="number" step="0.01" name="total" placeholder="Total ₱" required>
      <button type="submit" name="add_sale">Add Sale 📑➕</button>
    </form>

    <!-- Search -->
    <input type="text" id="searchInput" class="search-bar" placeholder="🔍 Search product/date...">

    <!-- Sales Table -->
    <table id="salesTable">
      <tr>
        <th>Date 📆</th><th>Product 📦</th><th>Quantity 🏷️</th><th>Total 💳</th><th>Action ⚙️</th>
      </tr>
      <?php foreach ($sales as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['sale_date']) ?></td>
          <td><?= htmlspecialchars($row['product']) ?></td>
          <td><?= htmlspecialchars($row['quantity']) ?></td>
          <td>₱<?= number_format($row['total'], 2) ?></td>
          <td>
            <button onclick="toggleEdit(<?= $row['id'] ?>)">✏️ Edit</button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <button type="submit" name="delete_sale" onclick="return confirm('Are you sure?');">🗑️ Delete</button>
            </form>
          </td>
        </tr>
        <tr id="editRow<?= $row['id'] ?>" class="edit-row" style="display:none;">
          <td colspan="5">
            <form method="POST">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <input type="date" name="sale_date" value="<?= $row['sale_date'] ?>" required>
              <input type="text" name="product" value="<?= $row['product'] ?>" required>
              <input type="number" name="quantity" value="<?= $row['quantity'] ?>" required>
              <input type="number" step="0.01" name="total" value="<?= $row['total'] ?>" required>
              <button type="submit" name="update_sale">💾 Save</button>
              <button type="button" onclick="toggleEdit(<?= $row['id'] ?>)">❌ Cancel</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <script>
    function toggleTheme() {
      document.body.classList.toggle("dark");
      localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
    }
    if (localStorage.getItem("theme") === "dark") document.body.classList.add("dark");

    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText = now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000); updateClock();

    function toggleEdit(id) {
      const row = document.getElementById("editRow" + id);
      row.style.display = row.style.display === "none" ? "table-row" : "none";
    }

    // Instant search
    document.getElementById("searchInput").addEventListener("keyup", function() {
      const filter = this.value.toLowerCase();
      document.querySelectorAll("#salesTable tr:not(:first-child)").forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(filter) ? "" : "none";
      });
    });
  </script>
</body>
</html>
