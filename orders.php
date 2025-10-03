<?php
session_start();
require 'db.php';

/* ---------------- FLASH MESSAGE HELPER ---------------- */
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}

/* ---------------- CONFIRM ORDER ---------------- */
if (isset($_POST['action']) && $_POST['action'] == 'confirm_order' && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    
    error_log("Confirm order called for ID: " . $order_id); // Debug
    
    $conn->begin_transaction();
    try {
        // Update order status to Confirmed
        $stmt = $conn->prepare("UPDATE orders SET status='Confirmed' WHERE id=?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        setFlash("Order #$order_id confirmed successfully!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error confirming order: " . $e->getMessage(), 'error');
    }

    header("Location: orders.php");
    exit;
}

/* ---------------- RECEIVE ORDER ---------------- */
if (isset($_POST['action']) && $_POST['action'] == 'receive_order' && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);

    error_log("Receive order called for ID: " . $order_id); // Debug

    $conn->begin_transaction();
    try {
        // Fetch order items
        $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $qty = $item['quantity'];

            // Deduct stock safely
            $stmt = $conn->prepare("UPDATE stock SET qty = GREATEST(qty - ?, 0) WHERE id=?");
            $stmt->bind_param("ii", $qty, $product_id);
            $stmt->execute();
            $stmt->close();

            // Insert sale record
            $stmt = $conn->prepare("SELECT name, price FROM stock WHERE id=?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $stmt->bind_result($product_name, $price);
            $stmt->fetch();
            $stmt->close();

            $total = $price * $qty;
            $stmt = $conn->prepare("INSERT INTO sales (product, quantity, total, sale_date, status) VALUES (?, ?, ?, NOW(), 'paid')");
            $stmt->bind_param("sid", $product_name, $qty, $total);
            $stmt->execute();
            $stmt->close();
        }

        // Update order status to Received
        $stmt = $conn->prepare("UPDATE orders SET status='Received' WHERE id=?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        setFlash("Order #$order_id marked as received!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error receiving order: " . $e->getMessage(), 'error');
    }

    header("Location: orders.php");
    exit;
}

/* ---------------- DELETE ORDERS ---------------- */
if (isset($_POST['delete_orders']) && !empty($_POST['order_ids'])) {
    $order_ids = $_POST['order_ids'];
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));

    $conn->begin_transaction();
    try {
        // Delete order items first
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
        setFlash("Selected orders deleted successfully!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error deleting orders: " . $e->getMessage(), 'error');
    }

    header("Location: orders.php");
    exit;
}

// Get order statistics
$pendingCount = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Pending'")->fetch_assoc()['cnt'];
$confirmedCount = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Confirmed'")->fetch_assoc()['cnt'];
$receivedCount = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Received'")->fetch_assoc()['cnt'];
$cancelledCount = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Cancelled'")->fetch_assoc()['cnt'];

// Get all orders for display
$orders = $conn->query("SELECT * FROM orders ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Tracking - Marcomedia POS</title>
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
      --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dark-mode {
      --bg: #121826;
      --card-bg: #1e293b;
      --text: #f1f5f9;
      --text-muted: #94a3b8;
      --sidebar-bg: #0f172a;
      --border: #334155;
      --shadow: rgba(0, 0, 0, 0.3);
    }

    .light-mode {
      --bg: #f1f5f9;
      --card-bg: #ffffff;
      --text: #1e293b;
      --text-muted: #64748b;
      --sidebar-bg: #1e293b;
      --border: #e2e8f0;
      --shadow: rgba(0, 0, 0, 0.1);
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
      min-height: 100vh;
    }

    .container {
      display: flex;
      min-height: 100vh;
      transition: var(--transition);
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
      box-shadow: 2px 0 10px var(--shadow);
    }

    .sidebar-header {
      padding: 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 12px;
      transition: var(--transition);
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
      transition: var(--transition);
    }

    .sidebar-title {
      font-size: 18px;
      font-weight: 600;
      transition: var(--transition);
    }

    .sidebar-menu {
      padding: 20px 0;
      transition: var(--transition);
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 12px 20px;
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: var(--transition);
      gap: 12px;
      border-left: 4px solid transparent;
    }

    .menu-item:hover, .menu-item.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border-left-color: var(--primary);
      transform: translateX(4px);
    }

    .menu-item i {
      width: 20px;
      text-align: center;
      transition: var(--transition);
    }

    .sidebar-footer {
      padding: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: auto;
      transition: var(--transition);
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 20px;
      transition: var(--transition);
      min-height: 100vh;
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 0 20px 0;
      border-bottom: 1px solid var(--border);
      margin-bottom: 25px;
      transition: var(--transition);
    }

    .header-title h1 {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 5px;
      transition: var(--transition);
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 16px;
      transition: var(--transition);
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 15px;
      transition: var(--transition);
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
      box-shadow: 0 2px 8px var(--shadow);
    }

    .theme-toggle:hover, .notification-btn:hover, .user-menu:hover {
      background: var(--primary);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px var(--shadow);
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
      box-shadow: 0 4px 12px var(--shadow);
      transition: var(--transition);
      border-left: 4px solid var(--primary);
      cursor: pointer;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
      transition: var(--transition);
    }

    .stat-card:hover::before {
      left: 100%;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px var(--shadow);
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
      transition: var(--transition);
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
      transition: var(--transition);
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
      transition: var(--transition);
    }

    .stat-label {
      color: var(--text-muted);
      font-size: 14px;
      transition: var(--transition);
    }

    /* Orders Table */
    .orders-table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
    }

    .table-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      transition: var(--transition);
    }

    .search-box {
      position: relative;
      width: 300px;
      transition: var(--transition);
    }

    .search-box input {
      width: 100%;
      padding: 10px 15px 10px 40px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
      box-shadow: 0 2px 6px var(--shadow);
    }

    .search-box input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 2px 8px rgba(67, 97, 238, 0.2);
    }

    .search-box i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      transition: var(--transition);
    }

    .action-buttons {
      display: flex;
      gap: 10px;
      transition: var(--transition);
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
      box-shadow: 0 2px 6px var(--shadow);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px var(--shadow);
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

    .btn-warning {
      background: var(--warning);
      color: white;
    }

    .btn-warning:hover {
      background: #d61a6e;
    }

    .orders-table {
      width: 100%;
      border-collapse: collapse;
      transition: var(--transition);
    }

    .orders-table th, .orders-table td {
      padding: 14px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      transition: var(--transition);
    }

    .orders-table th {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 14px;
      background: rgba(0, 0, 0, 0.02);
      transition: var(--transition);
    }

    .dark-mode .orders-table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .orders-table tr {
      transition: var(--transition);
    }

    .orders-table tr:hover {
      background: rgba(0, 0, 0, 0.02);
      transform: scale(1.01);
    }

    .dark-mode .orders-table tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    .checkbox-cell {
      width: 40px;
      text-align: center;
      transition: var(--transition);
    }

    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
      transition: var(--transition);
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.1);
      color: #f59e0b;
    }

    .status-confirmed {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
    }

    .status-received {
      background: rgba(59, 130, 246, 0.1);
      color: #3b82f6;
    }

    .status-cancelled {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
    }

    .action-cell {
      display: flex;
      gap: 8px;
      transition: var(--transition);
    }

    .action-btn {
      padding: 6px 12px;
      border-radius: 6px;
      border: none;
      font-size: 12px;
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 2px 4px var(--shadow);
    }

    .action-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 8px var(--shadow);
    }

    .action-btn.success {
      background: var(--success);
      color: white;
    }

    .action-btn.success:hover {
      background: #3aa8d8;
    }

    .action-btn.danger {
      background: var(--danger);
      color: white;
    }

    .action-btn.danger:hover {
      background: #c53030;
    }

    .action-btn:disabled {
      background: var(--gray-light);
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    /* Flash Message */
    .flash-message {
      padding: 12px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-weight: 500;
      transition: var(--transition);
      animation: slideIn 0.5s ease-out;
      box-shadow: 0 4px 12px var(--shadow);
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .flash-success {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
      border-left: 4px solid #10b981;
    }

    .flash-error {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
      border-left: 4px solid #ef4444;
    }

    /* Footer */
    .footer {
      text-align: center;
      padding: 20px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 30px;
      transition: var(--transition);
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
      .action-buttons {
        width: 100%;
        justify-content: space-between;
      }
      .orders-table {
        display: block;
        overflow-x: auto;
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
      transition: var(--transition);
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
      transition: var(--transition);
      border-radius: 50%;
    }

    input:checked + .slider {
      background-color: var(--primary);
    }

    input:checked + .slider:before {
      transform: translateX(26px);
    }

    /* Loading Animation */
    .loading {
      opacity: 0.7;
      pointer-events: none;
    }

    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      margin: -10px 0 0 -10px;
      border: 2px solid var(--primary);
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Hidden form for individual actions */
    #actionForm {
      display: none;
    }
  </style>
</head>
<body>
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
        <a href="sales.php" class="menu-item">
          <i class="fas fa-shopping-cart"></i>
          <span class="menu-text">Sales Tracking</span>
        </a>
        <a href="orders.php" class="menu-item active">
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
          <button type="submit" style="background: var(--danger); color: white; border: none; padding: 10px; width: 100%; border-radius: 6px; cursor: pointer; transition: var(--transition);">
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
          <h1>Order Tracking</h1>
          <p>Track pending customer orders</p>
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

      <!-- Flash Message -->
      <?php if(!empty($_SESSION['flash'])): ?>
        <div class="flash-message flash-<?php echo $_SESSION['flash']['type'] === 'error' ? 'error' : 'success'; ?>">
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card" onclick="filterOrders('all')">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $pendingCount + $confirmedCount + $receivedCount + $cancelledCount; ?></div>
              <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-shopping-bag"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning" onclick="filterOrders('Pending')">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $pendingCount; ?></div>
              <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-clock"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success" onclick="filterOrders('Confirmed')">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $confirmedCount; ?></div>
              <div class="stat-label">Confirmed Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-check-circle"></i>
            </div>
          </div>
        </div>

        <div class="stat-card" onclick="filterOrders('Received')">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $receivedCount; ?></div>
              <div class="stat-label">Received Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-truck"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Hidden form for individual actions -->
      <form method="POST" id="actionForm">
        <input type="hidden" name="action" id="formAction">
        <input type="hidden" name="order_id" id="formOrderId">
      </form>

      <!-- Orders Table -->
      <div class="orders-table-container">
        <div class="section-header">
          <div class="section-title">Order Records</div>
        </div>
        
        <!-- Bulk Delete Form -->
        <form method="POST" id="bulkDeleteForm" style="margin-bottom: 20px;">
          <div class="table-actions">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" id="searchInput" placeholder="Search orders...">
            </div>
            <div class="action-buttons">
              <button type="submit" name="delete_orders" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete selected orders?');">
                <i class="fas fa-trash"></i> Delete Selected
              </button>
            </div>
          </div>

          <table class="orders-table">
            <thead>
              <tr>
                <th class="checkbox-cell">
                  <input type="checkbox" id="selectAll">
                </th>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($orders && $orders->num_rows > 0): ?>
                <?php while ($order = $orders->fetch_assoc()): ?>
                  <?php
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
                  <tr class="order-row" data-status="<?= $order['status']; ?>">
                    <td class="checkbox-cell">
                      <input type="checkbox" name="order_ids[]" value="<?= $order['id']; ?>">
                    </td>
                    <td>#<?= $order['id']; ?></td>
                    <td><?= htmlspecialchars($order['customer_name']); ?></td>
                    <td><?= !empty($itemsList) ? implode(", ", $itemsList) : "No items"; ?></td>
                    <td>₱<?= number_format($total, 2); ?></td>
                    <td>
                      <span class="status-badge status-<?= strtolower($order['status']); ?>">
                        <?= $order['status']; ?>
                      </span>
                    </td>
                    <td><?= date('M j, Y', strtotime($order['created_at'])); ?></td>
                    <td class="action-cell">
                      <?php if ($order['status'] === 'Pending'): ?>
                        <button type="button" class="action-btn success" onclick="confirmOrder(<?= $order['id']; ?>)">
                          <i class="fas fa-check"></i> Confirm
                        </button>
                        <button type="button" class="action-btn success" onclick="receiveOrder(<?= $order['id']; ?>)">
                          <i class="fas fa-truck"></i> Receive
                        </button>
                      <?php elseif ($order['status'] === 'Confirmed'): ?>
                        <button type="button" class="action-btn success" onclick="receiveOrder(<?= $order['id']; ?>)">
                          <i class="fas fa-truck"></i> Receive
                        </button>
                      <?php elseif ($order['status'] === 'Received'): ?>
                        <span style="color: var(--success);">
                          <i class="fas fa-check-circle"></i> Received
                        </span>
                      <?php elseif ($order['status'] === 'Cancelled'): ?>
                        <span style="color: var(--danger);">
                          <i class="fas fa-times-circle"></i> Cancelled
                        </span>
                        <?php if (!empty($order['cancellation_note'])): ?>
                          <br><small><?= htmlspecialchars($order['cancellation_note']); ?></small>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" style="text-align: center;">No orders found.</td>
                </tr>
              <?php endif; ?>
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
    const checkboxes = document.querySelectorAll('input[name="order_ids[]"]');
    checkboxes.forEach(cb => {
      cb.checked = this.checked;
      // Add visual feedback for selected rows
      const row = cb.closest('tr');
      if (row) {
        row.style.backgroundColor = this.checked ? 'rgba(67, 97, 238, 0.05)' : '';
      }
    });
  });
}

// Row selection feedback for orders table
document.addEventListener('DOMContentLoaded', function() {
  const checkboxes = document.querySelectorAll('input[name="order_ids[]"]');
  checkboxes.forEach(checkbox => {
    checkbox.addEventListener('change', function() {
      const row = this.closest('tr');
      if (row) {
        row.style.backgroundColor = this.checked ? 'rgba(67, 97, 238, 0.05)' : '';
      }
      
      // Update select all checkbox state
      const allChecked = document.querySelectorAll('input[name="order_ids[]"]:checked').length === checkboxes.length;
      const someChecked = document.querySelectorAll('input[name="order_ids[]"]:checked').length > 0;
      
      if (selectAll) {
        selectAll.checked = allChecked;
        selectAll.indeterminate = someChecked && !allChecked;
      }
    });
  });
});

// Enhanced Instant Search for orders table
const searchInput = document.getElementById('searchInput');
if (searchInput) {
  let searchTimeout;
  searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      const filter = this.value.toLowerCase();
      const rows = document.querySelectorAll('.orders-table tbody tr');
      
      rows.forEach((row, index) => {
        const matches = row.textContent.toLowerCase().includes(filter);
        
        // Smooth animation
        setTimeout(() => {
          if (matches) {
            row.style.display = '';
            setTimeout(() => {
              row.style.opacity = '1';
              row.style.transform = 'scale(1)';
            }, 10);
          } else {
            row.style.opacity = '0.3';
            row.style.transform = 'scale(0.98)';
            setTimeout(() => {
              row.style.display = 'none';
            }, 300);
          }
        }, index * 30);
      });
    }, 300);
  });
}

// Enhanced Filter for orders by status
function filterOrders(status) {
  const rows = document.querySelectorAll('.order-row');
  if (rows.length === 0) return; // Exit if no order rows found
  
  rows.forEach((row, index) => {
    setTimeout(() => {
      if (status === 'all') {
        row.style.display = '';
        setTimeout(() => {
          row.style.opacity = '1';
          row.style.transform = 'scale(1)';
        }, 10);
      } else {
        const matches = row.dataset.status === status;
        if (matches) {
          row.style.display = '';
          setTimeout(() => {
            row.style.opacity = '1';
            row.style.transform = 'scale(1)';
          }, 10);
        } else {
          row.style.opacity = '0.3';
          row.style.transform = 'scale(0.98)';
          setTimeout(() => {
            row.style.display = 'none';
          }, 300);
        }
      }
    }, index * 50);
  });
}

// Add hover effects to action buttons
document.addEventListener('DOMContentLoaded', function() {
  const actionButtons = document.querySelectorAll('.btn, .action-btn');
  actionButtons.forEach(btn => {
    btn.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-2px)';
    });
    btn.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0)';
    });
  });
});

// Order Action Functions
function confirmOrder(orderId) {
    if (confirm('Confirm order #' + orderId + '?')) {
        document.getElementById('formAction').value = 'confirm_order';
        document.getElementById('formOrderId').value = orderId;
        document.getElementById('actionForm').submit();
    }
}

function receiveOrder(orderId) {
    if (confirm('Mark order #' + orderId + ' as received?')) {
        document.getElementById('formAction').value = 'receive_order';
        document.getElementById('formOrderId').value = orderId;
        document.getElementById('actionForm').submit();
    }
}

// Add loading state to form submissions
document.addEventListener('DOMContentLoaded', function() {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', function(e) {
      const submitBtn = e.submitter;
      if (submitBtn && submitBtn.type === 'submit') {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
        
        // Re-enable button if form submission fails (after 10 seconds timeout)
        setTimeout(() => {
          if (submitBtn.disabled) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
          }
        }, 10000);
      }
    });
  });
});
  </script>
</body>
</html>