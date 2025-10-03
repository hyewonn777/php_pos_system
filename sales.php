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

// Get sales data for charts
$monthlySales = [];
$result = $conn->query("
    SELECT MONTH(sale_date) as month, SUM(total) as total 
    FROM sales 
    WHERE YEAR(sale_date) = YEAR(CURDATE()) 
    GROUP BY MONTH(sale_date) 
    ORDER BY month
");
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $monthlySales[$row['month']] = $row['total'];
    }
}

// Fill in missing months with zero
$monthlySalesData = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlySalesData[] = isset($monthlySales[$i]) ? floatval($monthlySales[$i]) : 0;
}

// Get top products
$topProducts = [];
$result = $conn->query("
    SELECT product, SUM(quantity) as total_qty, SUM(total) as total_sales 
    FROM sales 
    GROUP BY product 
    ORDER BY total_sales DESC 
    LIMIT 5
");
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $topProducts[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales & Tracking - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #4361ee;
      --primary-dark: #3a56d4;
      --secondary: #7209b7;
      --success: #4cc9f0;
      --warning: #f72585;
      --danger: #e63946;
      --info: #4895ef;
      --light: #f8f9fa;
      --dark: #212529;
      --gray: #6c757d;
      --gray-light: #adb5bd;
      --sidebar-width: 260px;
      --header-height: 70px;
      --card-radius: 12px;
      --transition: all 0.3s ease;
    }

    .dark-mode {
      --bg: #121826;
      --card-bg: #1e293b;
      --text: #f1f5f9;
      --text-muted: #94a3b8;
      --sidebar-bg: #0f172a;
      --border: #334155;
    }

    .light-mode {
      --bg: #f1f5f9;
      --card-bg: #ffffff;
      --text: #1e293b;
      --text-muted: #64748b;
      --sidebar-bg: #1e293b;
      --border: #e2e8f0;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      background-color: var(--bg);
      color: var(--text);
      transition: var(--transition);
      line-height: 1.6;
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Styles */
    .sidebar {
      width: var(--sidebar-width);
      background: var(--sidebar-bg);
      color: white;
      height: 100vh;
      position: fixed;
      overflow-y: auto;
      transition: var(--transition);
      z-index: 1000;
    }

    .sidebar-header {
      padding: 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .logo {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 18px;
    }

    .sidebar-title {
      font-size: 18px;
      font-weight: 600;
    }

    .sidebar-menu {
      padding: 20px 0;
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 12px 20px;
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: var(--transition);
      gap: 12px;
    }

    .menu-item:hover, .menu-item.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border-left: 4px solid var(--primary);
    }

    .menu-item i {
      width: 20px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: auto;
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 20px;
      transition: var(--transition);
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 0 20px 0;
      border-bottom: 1px solid var(--border);
      margin-bottom: 25px;
    }

    .header-title h1 {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 16px;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .theme-toggle, .notification-btn, .user-menu {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--card-bg);
      color: var(--text);
      cursor: pointer;
      transition: var(--transition);
      border: 1px solid var(--border);
    }

    .theme-toggle:hover, .notification-btn:hover, .user-menu:hover {
      background: var(--primary);
      color: white;
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
      transition: var(--transition);
      border-left: 4px solid var(--primary);
      cursor: pointer;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .stat-card.warning {
      border-left-color: var(--warning);
    }

    .stat-card.danger {
      border-left-color: var(--danger);
    }

    .stat-card.success {
      border-left-color: var(--success);
    }

    .stat-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }

    .stat-icon {
      width: 50px;
      height: 50px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      background: rgba(67, 97, 238, 0.1);
      color: var(--primary);
    }

    .stat-card.warning .stat-icon {
      background: rgba(247, 37, 133, 0.1);
      color: var(--warning);
    }

    .stat-card.danger .stat-icon {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
    }

    .stat-card.success .stat-icon {
      background: rgba(76, 201, 240, 0.1);
      color: var(--success);
    }

    .stat-value {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .stat-label {
      color: var(--text-muted);
      font-size: 14px;
    }

    .stat-change {
      display: flex;
      align-items: center;
      font-size: 12px;
      margin-top: 8px;
    }

    .stat-change.positive {
      color: #10b981;
    }

    .stat-change.negative {
      color: var(--danger);
    }

    /* Charts and Tables */
    .content-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 25px;
      margin-bottom: 30px;
    }

    .chart-container, .top-products {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .section-title {
      font-size: 18px;
      font-weight: 600;
    }

    .view-all {
      color: var(--primary);
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
    }

    .chart-placeholder {
      height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(67, 97, 238, 0.05);
      border-radius: 8px;
      margin-top: 15px;
    }

    .top-products table {
      width: 100%;
      border-collapse: collapse;
    }

    .top-products th, .top-products td {
      padding: 12px 15px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .top-products th {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 14px;
    }

    /* Sales Table */
    .sales-table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
      margin-bottom: 30px;
    }

    .table-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .search-box {
      position: relative;
      width: 300px;
    }

    .search-box input {
      width: 100%;
      padding: 10px 15px 10px 40px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
    }

    .search-box i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
    }

    .action-buttons {
      display: flex;
      gap: 10px;
    }

    .btn {
      padding: 10px 16px;
      border-radius: 8px;
      border: none;
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--primary-dark);
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #c53030;
    }

    .btn-success {
      background: var(--success);
      color: white;
    }

    .btn-success:hover {
      background: #3aa8d8;
    }

    .sales-table {
      width: 100%;
      border-collapse: collapse;
    }

    .sales-table th, .sales-table td {
      padding: 14px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .sales-table th {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 14px;
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .sales-table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .sales-table tr:hover {
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .sales-table tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    .qty-input {
      width: 70px;
      padding: 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      text-align: center;
    }

    .level-badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .level-high {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
    }

    .level-medium {
      background: rgba(245, 158, 11, 0.1);
      color: #f59e0b;
    }

    .level-low {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
    }

    .checkbox-cell {
      width: 40px;
      text-align: center;
    }

    /* Footer */
    .footer {
      text-align: center;
      padding: 20px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 30px;
    }

    /* Responsive */
    @media (max-width: 992px) {
      .content-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
        overflow: visible;
      }
      .sidebar-title, .menu-text {
        display: none;
      }
      .main-content {
        margin-left: 70px;
      }
      .sidebar-header {
        justify-content: center;
        padding: 20px 10px;
      }
      .menu-item {
        justify-content: center;
        padding: 15px;
      }
      .table-actions {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      .search-box {
        width: 100%;
      }
    }

    /* Toggle Switch */
    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 50px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: var(--gray-light);
      transition: .4s;
      border-radius: 24px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 16px;
      width: 16px;
      left: 4px;
      bottom: 4px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }

    input:checked + .slider {
      background-color: var(--primary);
    }

    input:checked + .slider:before {
      transform: translateX(26px);
    }
  </style>
</head>
  <div class="container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="sidebar-header">
        <div class="logo">M</div>
        <div class="sidebar-title">Marcomedia POS</div>
      </div>
      <div class="sidebar-menu">
        <a href="index.php" class="menu-item">
          <i class="fas fa-chart-line"></i>
          <span class="menu-text">Dashboard</span>
        </a>
        <a href="sales.php" class="menu-item active">
          <i class="fas fa-shopping-cart"></i>
          <span class="menu-text">Sales Tracking</span>
        </a>
        <a href="orders.php" class="menu-item">
          <i class="fas fa-clipboard-list"></i>
          <span class="menu-text">Purchase Orders</span>
        </a>
        <a href="stock.php" class="menu-item">
          <i class="fas fa-boxes"></i>
          <span class="menu-text">Inventory</span>
        </a>
        <a href="appointment.php" class="menu-item">
          <i class="fas fa-calendar-alt"></i>
          <span class="menu-text">Appointments</span>
        </a>
        <a href="user_management.php" class="menu-item">
          <i class="fas fa-users"></i>
          <span class="menu-text">Account Management</span>
        </a>
      </div>
      <div class="sidebar-footer">
        <form action="logout.php" method="POST">
          <button type="submit" style="background: var(--danger); color: white; border: none; padding: 10px; width: 100%; border-radius: 6px; cursor: pointer;">
            <i class="fas fa-sign-out-alt"></i> Logout
          </button>
        </form>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="header">
        <div class="header-title">
          <h1>Sales & Tracking</h1>
          <p>Track revenue and sales performance</p>
        </div>
        <div class="header-actions">
          <label class="theme-toggle" for="theme-toggle">
            <i class="fas fa-moon"></i>
          </label>
          <input type="checkbox" id="theme-toggle" style="display: none;">
          <div class="user-menu">
            <i class="fas fa-user"></i>
          </div>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?php echo number_format($grossSales, 2); ?></div>
              <div class="stat-label">Gross Sales</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-dollar-sign"></i>
            </div>
          </div>
          <div class="stat-change positive">
            <i class="fas fa-arrow-up"></i> 12.5% from last month
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?php echo number_format($discountAmount, 2); ?></div>
              <div class="stat-label">Discounts</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-tag"></i>
            </div>
          </div>
          <div class="stat-change negative">
            <i class="fas fa-arrow-up"></i> 8.2% from last month
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?php echo number_format($netSales, 2); ?></div>
              <div class="stat-label">Net Sales</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-chart-line"></i>
            </div>
          </div>
          <div class="stat-change positive">
            <i class="fas fa-arrow-up"></i> 15.3% from last month
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?php echo number_format($totalProfitAfterDiscount, 2); ?></div>
              <div class="stat-label">Profit After Discount</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-money-bill-wave"></i>
            </div>
          </div>
          <div class="stat-change positive">
            <i class="fas fa-arrow-up"></i> 9.7% from last month
          </div>
        </div>
      </div>

      <!-- Charts and Top Products -->
      <div class="content-grid">
        <div class="chart-container">
          <div class="section-header">
            <div class="section-title">Monthly Sales</div>
            <a href="#" class="view-all">View Report <i class="fas fa-arrow-right"></i></a>
          </div>
          <div class="chart-placeholder">
            <canvas id="salesChart" width="400" height="300"></canvas>
          </div>
        </div>

        <div class="top-products">
          <div class="section-header">
            <div class="section-title">Top Products</div>
            <a href="stock.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>Quantity</th>
                <th>Sales</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($topProducts)): ?>
                <?php foreach ($topProducts as $product): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($product['product']); ?></td>
                    <td><?php echo $product['total_qty']; ?></td>
                    <td>₱<?php echo number_format($product['total_sales'], 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="3" style="text-align: center;">No product data available</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Sales Table -->
      <div class="sales-table-container">
        <div class="section-header">
          <div class="section-title">Sales Records</div>
        </div>
        
        <form method="POST">
          <div class="table-actions">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" id="searchInput" placeholder="Search products, date...">
            </div>
            <div class="action-buttons">
              <button type="submit" name="update_selected" class="btn btn-success">
                <i class="fas fa-save"></i> Update Selected
              </button>
              <button type="submit" name="delete_selected" class="btn btn-danger" onclick="return confirm('Delete selected sales?');">
                <i class="fas fa-trash"></i> Delete Selected
              </button>
            </div>
          </div>

          <table class="sales-table">
            <thead>
              <tr>
                <th class="checkbox-cell">
                  <input type="checkbox" id="selectAll">
                </th>
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
                <td class="checkbox-cell">
                  <input type="checkbox" name="selected_sales[]" value="<?= $row['id'] ?>">
                </td>
                <td><?= htmlspecialchars($row['sale_date']) ?></td>
                <td><?= htmlspecialchars($sku) ?></td>
                <td>
                  <input type="number" class="qty-input" name="quantity[<?= $row['id'] ?>]" 
                         value="<?= $row['quantity'] ?>" min="1">
                </td>
                <td>₱<?= number_format($row['total'],2) ?></td>
                <td>₱<?= number_format($profit,2) ?></td>
                <td>₱<?= number_format($profitAfter,2) ?></td>
                <td>
                  <span class="level-badge level-<?= strtolower($level) ?>">
                    <?= $level ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved.</p>
      </div>
    </div>
  </div>

  <script>
// Enhanced Theme Toggle with Smooth Transitions
const themeToggle = document.getElementById('theme-toggle');

// Apply theme transition to specific elements only (better performance)
function applyThemeTransition() {
  const transitionElements = document.querySelectorAll('body, .stat-card, .form-container, .table-container, .csv-tools, .btn, .form-control, .table, .table th, .table td');
  transitionElements.forEach(el => {
    el.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
  });
}

// Initialize theme on page load
document.addEventListener('DOMContentLoaded', function() {
  applyThemeTransition();
  
  // Set initial theme
  setTimeout(() => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      document.body.classList.add('dark-mode');
      if (themeToggle) themeToggle.checked = true;
    } else {
      document.body.classList.add('light-mode');
      if (themeToggle) themeToggle.checked = false;
    }
  }, 100);
});

// Theme toggle event
if (themeToggle) {
  themeToggle.addEventListener('change', function() {
    // Add loading state
    document.body.style.pointerEvents = 'none';
    
    setTimeout(() => {
      if (this.checked) {
        document.body.classList.remove('light-mode');
        document.body.classList.add('dark-mode');
        localStorage.setItem('theme', 'dark');
      } else {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        localStorage.setItem('theme', 'light');
      }
      
      // Remove loading state
      document.body.style.pointerEvents = 'auto';
    }, 200);
  });
}

    // Select/Deselect All Checkboxes
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
      selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('input[name="selected_sales[]"]');
        checkboxes.forEach(cb => cb.checked = this.checked);
      });
    }

    // Instant Search
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
      searchInput.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('.sales-table tbody tr').forEach(r => {
          r.style.display = r.innerText.toLowerCase().includes(filter) ? "" : "none";
        });
      });
    }

    // Simple Chart Implementation
    const salesChart = document.getElementById('salesChart').getContext('2d');
    
    // Create gradient
    const gradient = salesChart.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(67, 97, 238, 0.5)');
    gradient.addColorStop(1, 'rgba(67, 97, 238, 0.1)');
    
    // Chart data
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const salesData = <?php echo json_encode($monthlySalesData); ?>;
    
    // Draw chart
    salesChart.beginPath();
    salesChart.moveTo(30, 250);
    
    for (let i = 0; i < salesData.length; i++) {
      const x = 30 + (i * 30);
      // Normalize data to fit in chart (assuming max value is 10000 for demo)
      const y = 250 - (salesData[i] / 10000 * 200);
      salesChart.lineTo(x, y);
    }
    
    salesChart.strokeStyle = '#4361ee';
    salesChart.lineWidth = 2;
    salesChart.stroke();
    
    // Fill area under the line
    salesChart.lineTo(30 + (11 * 30), 250);
    salesChart.closePath();
    salesChart.fillStyle = gradient;
    salesChart.fill();
    
    // Add month labels
    salesChart.fillStyle = document.body.classList.contains('dark-mode') ? '#94a3b8' : '#64748b';
    salesChart.font = '10px Arial';
    salesChart.textAlign = 'center';
    
    for (let i = 0; i < months.length; i++) {
      salesChart.fillText(months[i], 30 + (i * 30), 270);
    }
  </script>
</body>
</html>