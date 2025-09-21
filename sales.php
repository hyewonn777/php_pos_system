<?php
session_start();
require 'db.php';

/* ----------------- SUMMARY TABLE ----------------- */
$check = $conn->query("SELECT id FROM summary WHERE id=1");
if ($check->num_rows === 0) {
    $conn->query("INSERT INTO summary (id, total_sales, total_revenue) VALUES (1, 0, 0)");
}

/* -------------------- CREATE SALE -------------------- */
if (isset($_POST['add_sale'])) {
    $sale_date = $_POST['sale_date'];
    $product   = $_POST['product'];
    $quantity  = intval($_POST['quantity']);
    $total     = floatval($_POST['total']);

    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssid", $sale_date, $product, $quantity, $total);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE name=?");
        if ($stmt) {
            $stmt->bind_param("is", $quantity, $product);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: sales.php");
    exit;
}

/* -------------------- BATCH UPDATE -------------------- */
if (isset($_POST['update_selected']) && !empty($_POST['selected_sales'])) {
    foreach ($_POST['selected_sales'] as $sale_id) {
        $sale_id = intval($sale_id);
        $new_qty = intval($_POST['quantity'][$sale_id] ?? 0);

        $stmt = $conn->prepare("SELECT product, quantity, total FROM sales WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $sale_id);
            $stmt->execute();
            $stmt->bind_result($product, $old_qty, $old_total);
            $stmt->fetch();
            $stmt->close();

            $qty_diff = $new_qty - $old_qty;

            $stmt = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE name=?");
            if ($stmt) {
                $stmt->bind_param("is", $qty_diff, $product);
                $stmt->execute();
                $stmt->close();
            }

            $price_per_unit = $old_qty > 0 ? $old_total / $old_qty : 0;
            $new_total = $price_per_unit * $new_qty;

            $stmt = $conn->prepare("UPDATE sales SET quantity=?, total=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("idi", $new_qty, $new_total, $sale_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    header("Location: sales.php");
    exit;
}

/* -------------------- BATCH DELETE -------------------- */
if (isset($_POST['delete_selected']) && !empty($_POST['selected_sales'])) {
    foreach ($_POST['selected_sales'] as $sale_id) {
        $sale_id = intval($sale_id);

        $stmt = $conn->prepare("SELECT product, quantity FROM sales WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $sale_id);
            $stmt->execute();
            $stmt->bind_result($product, $qty);
            $stmt->fetch();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE stock SET qty = qty + ? WHERE name=?");
            if ($stmt) {
                $stmt->bind_param("is", $qty, $product);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM sales WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $sale_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    header("Location: sales.php");
    exit;
}

/* -------------------- READ SALES -------------------- */
$result = $conn->query("SELECT id, product, quantity, total, sale_date FROM sales ORDER BY sale_date DESC");
$sales = [];
$totalSales = 0;
$skuMargins = [];

while ($row = $result->fetch_assoc()) {
    $sales[] = $row;
    $totalSales += floatval($row['total']);

    $sku = $row['product'];
    $profit = $row['total'] * 0.1; // 10% profit assumed
    if (!isset($skuMargins[$sku])) $skuMargins[$sku] = ['profit'=>0,'discount'=>0];
    $skuMargins[$sku]['profit'] += $profit;
}

/* -------------------- READ DISCOUNT & LEVEL -------------------- */
foreach ($skuMargins as $sku => &$data) {
    $stmt = $conn->prepare("SELECT discount FROM stock WHERE name=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $stmt->bind_result($disc);
        $data['discount'] = $stmt->fetch() ? $disc : 0;
        $stmt->close();
    } else {
        $data['discount'] = 0;
    }

    // Apply admin discount + 20% global discount
    $data['profit_after_discount'] = $data['profit'] * (1 - $data['discount']/100) * 0.8;

    if ($data['profit_after_discount'] >= 5000) $data['level'] = "High";
    elseif ($data['profit_after_discount'] >= 1000) $data['level'] = "Medium";
    else $data['level'] = "Low";
}
unset($data);

/* -------------------- SUMMARY CALCULATIONS -------------------- */
$grossSales = $totalSales;                 // sum of all sales totals
$globalDiscountPercent = 10;               // 10% discount for net sales (assume rani ahak)
$discountAmount = $grossSales * $globalDiscountPercent / 100;
$netSales = $grossSales - $discountAmount;

$totalProfitAfterDiscount = array_sum(array_column($skuMargins,'profit_after_discount'));
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
      width: 100%;
    }

    body, .card, .sidebar, #salesTable th, #salesTable td {
      transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    /* Summary Cards */
    .summary { display:flex; gap:20px; margin:20px 0; }
    .summary .card { flex:1; padding:20px; border-radius:10px; text-align:center; background:#fff; font-size:20px; font-weight:bold; }
    /* Table */
    #salesTable, #skuTable { width:100%; border-collapse:collapse; margin-top:20px; }
    #salesTable th, #salesTable td, #skuTable th, #skuTable td { border:1px solid #e3e3e3; padding:12px; text-align:left; }
    #salesTable th, #skuTable th { background:#f4f4f4; font-weight:bold; }
    .search-bar { margin:10px 0; width:100%; }

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
    <h1>Sales & Tracking</h1>
    <p>Track revenue and sales performance</p><br>

    <!-- Updated Summary -->
    <div class="summary">
      <div class="card"><b>Gross Sales:</b><br>₱<?= number_format($grossSales,2) ?></div>
      <div class="card"><b>Discount:</b><br>₱<?= number_format($discountAmount,2) ?></div>
      <div class="card"><b>Net Sales:</b><br>₱<?= number_format($netSales,2) ?></div>
    </div>


<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
  <button type="submit" name="delete_selected" style="padding:5px 10px; background:#e74c3c; color:white; border:none; border-radius:5px; cursor:pointer;" 
          onclick="return confirm('Delete selected sales?');">Delete Selected</button>
</div>
    <form method="POST">
    <table id="salesTable">
    <thead>
    <tr>
      <th><input type="checkbox" id="selectAll"></th>
      <th>Date</th>
      <th>Product</th>
      <th>Quantity</th>
      <th>Total</th>

      <th>Profit</th>
      <th>Profit After Discount</th>
      <th>Level</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($sales as $row): 
      $sku = $row['product'];
      $profit = $skuMargins[$sku]['profit'] ?? 0;
      $discount = $skuMargins[$sku]['discount'] ?? 0;
      $profitAfter = $skuMargins[$sku]['profit_after_discount'] ?? $profit;
      $level = $skuMargins[$sku]['level'] ?? 'Low';
    ?>
    <tr>
      <td>
        <input type="checkbox" name="selected_sales[]" value="<?= $row['id'] ?>">

      </td>
      <td><?= htmlspecialchars($row['sale_date']) ?></td>
      <td><?= htmlspecialchars($sku) ?></td>
      <td><input type="number" name="quantity[<?= $row['id'] ?>]" value="<?= $row['quantity'] ?>" min="1"></td>
      <td>₱<?= number_format($row['total'],2) ?></td>
      <td>₱<?= number_format($profit,2) ?></td>
      <td>₱<?= number_format($profitAfter,2) ?></td>
      <td><?= $level ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    </form>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
        // --- Real-time Clock ---
        function updateClock() {
            const now = new Date();
            document.getElementById("clock").innerText =
                now.toLocaleDateString() + " " + now.toLocaleTimeString();
        }
        setInterval(updateClock, 1000);
        updateClock();

        // --- Toggle Dark Mode ---
        const toggleBtn = document.querySelector(".toggle-btn");
        if (toggleBtn) {
            toggleBtn.addEventListener("click", function() {
                document.body.classList.toggle("dark");
                localStorage.setItem(
                    "theme",
                    document.body.classList.contains("dark") ? "dark" : "light"
                );
            });
        }
        if (localStorage.getItem("theme") === "dark") {
            document.body.classList.add("dark");
        }

        // --- Toggle Edit Row ---
        window.toggleEdit = function(id) {
            const row = document.getElementById("editRow" + id);
            if (row) {
                row.style.display = row.style.display === "none" ? "table-row" : "none";
            }
        }

        // --- Instant Search ---
        const searchInput = document.getElementById("searchInput");
        if (searchInput) {
            searchInput.addEventListener("keyup", function() {
                const filter = this.value.toLowerCase();
                document.querySelectorAll("#salesTable tbody tr").forEach(r => {
                    r.style.display = r.innerText.toLowerCase().includes(filter) ? "" : "none";
                });
            });
        }

        // --- Select/Deselect All Checkboxes ---
        const selectAll = document.getElementById("selectAll");
        if (selectAll) {
            selectAll.addEventListener("change", function() {
                const checkboxes = document.querySelectorAll('input[name="selected_sales[]"]');
                checkboxes.forEach(cb => cb.checked = this.checked);
            });
        }
    });
</script>
</body>
</html>
