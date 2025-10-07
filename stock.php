<?php
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Enhanced error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Test database query
$test_query = $conn->query("SELECT 1");
if (!$test_query) {
    die("Database test query failed: " . $conn->error);
}

// Debug function
function debug_log($message) {
    error_log("STOCK_DEBUG: " . $message);
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

// Log all POST requests for debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("POST Request: " . print_r($_POST, true));
    if (!empty($_FILES)) {
        debug_log("FILES Data: " . print_r($_FILES, true));
    }
}

/* ------------------ BULK DELETE ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    debug_log("Bulk delete triggered");
    
    if (!empty($_POST['selected']) && is_array($_POST['selected'])) {
        $ids = array_map('intval', $_POST['selected']);
        $in = implode(',', $ids);
        
        debug_log("Deleting IDs: " . $in);
        
        $sql = "UPDATE stock SET status='inactive' WHERE id IN ($in)";
        if ($conn->query($sql)) {
            $_SESSION['flash'] = ['message' => count($ids) . " product(s) deleted successfully!", 'type' => 'success'];
            debug_log("Bulk delete successful");
        } else {
            $_SESSION['flash'] = ['message' => "Error deleting products: " . $conn->error, 'type' => 'error'];
            debug_log("Bulk delete failed: " . $conn->error);
        }
    } else {
        $_SESSION['flash'] = ['message' => "Please select at least one product.", 'type' => 'error'];
        debug_log("Bulk delete - no products selected");
    }
    
    header("Location: stock.php");
    exit;
}

/* ------------------ UPDATE PRODUCT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    debug_log("Update product triggered");
    
    $id = intval($_POST['edit_id'] ?? 0);
    
    if ($id <= 0) {
        $_SESSION['flash'] = ['message' => "Invalid product ID.", 'type' => 'error'];
        header("Location: stock.php"); 
        exit;
    }

    // Get form data
    $category = trim($_POST['category'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $qty = intval($_POST['qty'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);

    debug_log("Updating product ID: $id, Category: $category, Name: $name, Qty: $qty, Price: $price");

    // Validate required fields
    if (empty($category) || empty($name) || $price <= 0) {
        $_SESSION['flash'] = ['message' => "Please fill all required fields with valid data.", 'type' => 'error'];
        debug_log("Update validation failed");
        header("Location: stock.php");
        exit;
    }

    // Handle image upload
    $image_path = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        debug_log("Image upload detected");
        
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $_FILES['image']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image_path = 'uploads/' . $file_name;
            debug_log("Image uploaded successfully: $image_path");
        } else {
            debug_log("Image upload failed");
        }
    }

    // Build update query
    if ($image_path) {
        $stmt = $conn->prepare("UPDATE stock SET category=?, name=?, qty=?, price=?, image_path=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssidsi", $category, $name, $qty, $price, $image_path, $id);
        }
    } else {
        $stmt = $conn->prepare("UPDATE stock SET category=?, name=?, qty=?, price=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssidi", $category, $name, $qty, $price, $id);
        }
    }

    if ($stmt && $stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['flash'] = ['message' => "Product updated successfully!", 'type' => 'success'];
            debug_log("Product update successful");
        } else {
            $_SESSION['flash'] = ['message' => "No changes were made.", 'type' => 'warning'];
            debug_log("Product update - no changes made");
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = ['message' => "Update failed: " . ($stmt ? $stmt->error : $conn->error), 'type' => 'error'];
        debug_log("Product update failed: " . ($stmt ? $stmt->error : $conn->error));
    }

    header("Location: stock.php");
    exit;
}

/* ------------------ ADD PRODUCT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    debug_log("Add product triggered");
    
    $category = trim($_POST['category'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $qty = intval($_POST['qty'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);

    debug_log("Adding product - Category: $category, Name: $name, Qty: $qty, Price: $price");

    // Validate required fields
    if (empty($category) || empty($name) || $price <= 0) {
        $_SESSION['flash'] = ['message' => "Please fill all required fields with valid data.", 'type' => 'error'];
        debug_log("Add product validation failed");
        header("Location: stock.php");
        exit;
    }

    // Handle image upload
    $image_path = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        debug_log("Image upload for new product");
        
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $_FILES['image']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image_path = 'uploads/' . $file_name;
            debug_log("New product image uploaded: $image_path");
        }
    }

    // Insert new product
    $stmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssids", $category, $name, $qty, $price, $image_path);
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['message' => "Product added successfully!", 'type' => 'success'];
            debug_log("Product added successfully");
        } else {
            $_SESSION['flash'] = ['message' => "Error adding product: " . $stmt->error, 'type' => 'error'];
            debug_log("Product add failed: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = ['message' => "Database error: " . $conn->error, 'type' => 'error'];
        debug_log("Prepare failed: " . $conn->error);
    }

    header("Location: stock.php");
    exit;
}

// Get statistics
$totalProducts = $conn->query("SELECT COUNT(*) as cnt FROM stock WHERE status='active'")->fetch_assoc()['cnt'];
$totalValue = $conn->query("SELECT SUM(price * qty) as total FROM stock WHERE status='active'")->fetch_assoc()['total'];
$lowStockCount = $conn->query("SELECT COUNT(*) as cnt FROM stock WHERE status='active' AND qty < 20")->fetch_assoc()['cnt'];

// Get all active products
$resAll = $conn->query("SELECT * FROM stock WHERE status='active' ORDER BY id ASC");

debug_log("Page loaded successfully. Products: " . $resAll->num_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory Management - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* Your existing CSS styles remain the same */
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
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

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

    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 20px;
      transition: var(--transition);
    }

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
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px var(--shadow);
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

    .form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text-muted);
      font-size: 14px;
    }

    .form-control {
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    .btn {
      padding: 12px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
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

    .btn-success {
      background: var(--success);
      color: white;
    }

    .btn-success:hover {
      background: #3aa8d8;
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #c53030;
    }

    .btn-warning {
      background: var(--warning);
      color: white;
    }

    .btn-warning:hover {
      background: #d61a6e;
    }

    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
    }

    .table-header {
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

    .table {
      width: 100%;
      border-collapse: collapse;
    }

    .table th, .table td {
      padding: 14px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .table th {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 14px;
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .table tr:hover {
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .table tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    .checkbox-cell {
      width: 40px;
      text-align: center;
    }

    .product-image {
      width: 50px;
      height: 50px;
      object-fit: cover;
      border-radius: 6px;
    }

    .low-stock {
      background: rgba(239, 68, 68, 0.1) !important;
    }

    .low-stock-badge {
      background: var(--danger);
      color: white;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      margin-left: 8px;
    }

    .edit-row {
      background: rgba(67, 97, 238, 0.05);
    }

    .edit-form {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      align-items: end;
    }

    .flash-message {
      padding: 12px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-weight: 500;
      animation: slideIn 0.5s ease-out;
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

    .flash-warning {
      background: rgba(245, 158, 11, 0.1);
      color: #f59e0b;
      border-left: 4px solid #f59e0b;
    }

    .csv-tools {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 20px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
    }

    .csv-actions {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .csv-note {
      margin-top: 12px;
      font-size: 13px;
      color: var(--text-muted);
    }

    .footer {
      text-align: center;
      padding: 20px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 30px;
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
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
      .form-grid {
        grid-template-columns: 1fr;
      }
      .table-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      .search-box {
        width: 100%;
      }
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

    .hidden {
      display: none !important;
    }

    .debug-info {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      padding: 10px;
      margin: 10px 0;
      border-radius: 5px;
      font-family: monospace;
      font-size: 12px;
    }
  </style>
</head>
<body class="light-mode">
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
        <a href="orders.php" class="menu-item">
          <i class="fas fa-clipboard-list"></i>
          <span class="menu-text">Purchase Orders</span>
        </a>
        <a href="stock.php" class="menu-item active">
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
          <h1>Inventory Management</h1>
          <p>Manage your product inventory and stock levels</p>
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
        <div class="flash-message flash-<?= $_SESSION['flash']['type'] === 'error' ? 'error' : ($_SESSION['flash']['type'] === 'warning' ? 'warning' : 'success') ?>">
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- Debug Info (remove in production) -->
      <div class="debug-info">
        <strong>Debug Info:</strong> 
        Products in DB: <?= $resAll->num_rows ?>, 
        DB Connected: <?= $conn->ping() ? 'Yes' : 'No' ?>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?= $totalProducts ?></div>
              <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-boxes"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?= number_format($totalValue, 2) ?></div>
              <div class="stat-label">Total Inventory Value</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-dollar-sign"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?= $lowStockCount ?></div>
              <div class="stat-label">Low Stock Items</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-exclamation-triangle"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Product Form -->
      <div class="form-container">
        <h3 style="margin-bottom: 20px; color: var(--text);">Add New Product</h3>
        <form id="productForm" enctype="multipart/form-data" method="POST" onsubmit="return validateForm(this)">
          <div class="form-grid">
            <div class="form-group">
              <label for="category">Category *</label>
              <input type="text" id="category" name="category" class="form-control" placeholder="Enter category" required>
            </div>
            <div class="form-group">
              <label for="name">Product Name *</label>
              <input type="text" id="name" name="name" class="form-control" placeholder="Enter product name" required>
            </div>
            <div class="form-group">
              <label for="qty">Quantity</label>
              <input type="number" id="qty" name="qty" class="form-control" placeholder="0" min="0" value="0">
            </div>
            <div class="form-group">
              <label for="price">Price *</label>
              <input type="number" step="0.01" id="price" name="price" class="form-control" placeholder="0.00" required>
            </div>
            <div class="form-group">
              <label for="image">Product Image</label>
              <input type="file" id="image" name="image" class="form-control" accept="image/*">
            </div>
            <div class="form-group">
              <button type="submit" name="add_product" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Product
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Products Table -->
      <div class="table-container">
        <div class="table-header">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search products...">
          </div>
          <form id="bulkDeleteForm" method="POST" action="stock.php">
            <button type="submit" name="bulk_delete" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete selected products?')">
              <i class="fas fa-trash"></i> Delete Selected
            </button>
          </form>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th class="checkbox-cell">
                <input type="checkbox" id="selectAll">
              </th>
              <th>#</th>
              <th>Category</th>
              <th>Image</th>
              <th>Product Name</th>
              <th>Price</th>
              <th>Quantity</th>
              <th>Total Value</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($resAll && $resAll->num_rows > 0): ?>
              <?php $counter = 1; ?>
              <?php while ($row = $resAll->fetch_assoc()): ?>
                <?php 
                  $lowStock = ($row['qty'] < 20);
                  $totalValue = $row['price'] * $row['qty'];
                ?>
                <tr class="<?= $lowStock ? 'low-stock' : '' ?>" id="row-<?= $row['id'] ?>">
                  <td class="checkbox-cell">
                    <input type="checkbox" name="selected[]" value="<?= $row['id'] ?>" form="bulkDeleteForm">
                  </td>
                  <td><?= $counter ?></td>
                  <td><?= htmlspecialchars($row['category']) ?></td>
                  <td>
                    <?php if (!empty($row['image_path'])): ?>
                      <img src="<?= htmlspecialchars($row['image_path']) ?>" class="product-image" alt="<?= htmlspecialchars($row['name']) ?>">
                    <?php else: ?>
                      <div style="width:50px;height:50px;background:var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);">
                        <i class="fas fa-image"></i>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= htmlspecialchars($row['name']) ?>
                    <?php if ($lowStock): ?>
                      <span class="low-stock-badge">LOW STOCK</span>
                    <?php endif; ?>
                  </td>
                  <td>₱<?= number_format((float)$row['price'], 2) ?></td>
                  <td><?= intval($row['qty']) ?></td>
                  <td>₱<?= number_format($totalValue, 2) ?></td>
                  <td>
                    <button type="button" class="btn btn-primary" onclick="toggleEditRow(<?= $row['id'] ?>)">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                  </td>
                </tr>

                <!-- Edit Row -->
                <tr id="edit-row-<?= $row['id'] ?>" class="hidden edit-row">
                  <td colspan="9">
                    <form method="POST" enctype="multipart/form-data" action="stock.php" class="edit-form" onsubmit="return validateForm(this)">
                      <input type="hidden" name="edit_id" value="<?= $row['id'] ?>">
                      <div class="form-group">
                        <label>Category *</label>
                        <input type="text" name="category" value="<?= htmlspecialchars($row['category']) ?>" class="form-control" required>
                      </div>
                      <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" class="form-control" required>
                      </div>
                      <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="qty" value="<?= intval($row['qty']) ?>" class="form-control" min="0">
                      </div>
                      <div class="form-group">
                        <label>Price *</label>
                        <input type="number" step="0.01" name="price" value="<?= $row['price'] ?>" class="form-control" required>
                      </div>
                      <div class="form-group">
                        <label>Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <?php if (!empty($row['image_path'])): ?>
                          <small style="color: var(--text-muted); margin-top: 5px; display: block;">
                            Current: <?= htmlspecialchars($row['image_path']) ?>
                          </small>
                        <?php endif; ?>
                      </div>
                      <div class="form-group">
                        <button type="submit" name="update_product" class="btn btn-success">
                          <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-warning" onclick="toggleEditRow(<?= $row['id'] ?>)">
                          <i class="fas fa-times"></i> Cancel
                        </button>
                      </div>
                    </form>
                  </td>
                </tr>
                <?php $counter++; ?>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 40px;">
                  <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                  No products found in inventory
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p>&copy; <?= date('Y') ?> Marcomedia POS. All rights reserved.</p>
      </div>
    </div>
  </div>

  <script>
    // Theme Toggle
    const themeToggle = document.getElementById('theme-toggle');
    
    function initializeTheme() {
      const savedTheme = localStorage.getItem('theme');
      const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      
      if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
        document.body.classList.remove('light-mode');
        document.body.classList.add('dark-mode');
        themeToggle.checked = true;
        updateThemeIcon('moon');
      } else {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        themeToggle.checked = false;
        updateThemeIcon('sun');
      }
    }

    function updateThemeIcon(mode) {
      const themeIcon = document.querySelector('.theme-toggle i');
      if (themeIcon) {
        themeIcon.className = mode === 'moon' ? 'fas fa-moon' : 'fas fa-sun';
      }
    }

    themeToggle.addEventListener('change', function() {
      if (document.body.classList.contains('dark-mode')) {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        localStorage.setItem('theme', 'light');
        updateThemeIcon('sun');
      } else {
        document.body.classList.remove('light-mode');
        document.body.classList.add('dark-mode');
        localStorage.setItem('theme', 'dark');
        updateThemeIcon('moon');
      }
    });

    // Select All Checkboxes
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
      selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('input[name="selected[]"]');
        checkboxes.forEach(cb => cb.checked = this.checked);
      });
    }

    // Toggle Edit Row
    function toggleEditRow(id) {
      console.log('Toggling edit row for ID:', id);
      
      const editRow = document.getElementById("edit-row-" + id);
      if (!editRow) {
        console.error('Edit row not found for ID:', id);
        return;
      }
      
      // Close all other edit rows
      document.querySelectorAll('.edit-row').forEach(row => {
        if (row.id !== "edit-row-" + id) {
          row.classList.add("hidden");
        }
      });
      
      // Toggle current row
      editRow.classList.toggle("hidden");
      
      // Scroll to edit form
      if (!editRow.classList.contains("hidden")) {
        setTimeout(() => {
          editRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
      }
    }

    // Form Validation
    function validateForm(form) {
      const category = form.querySelector('input[name="category"]');
      const name = form.querySelector('input[name="name"]');
      const price = form.querySelector('input[name="price"]');
      
      if (category && category.value.trim() === '') {
        alert('Category is required');
        category.focus();
        return false;
      }
      
      if (name && name.value.trim() === '') {
        alert('Product name is required');
        name.focus();
        return false;
      }
      
      if (price && (parseFloat(price.value) <= 0 || isNaN(parseFloat(price.value)))) {
        alert('Price must be a number greater than 0');
        price.focus();
        return false;
      }
      
      return true;
    }

    // Search Functionality
    const searchInput = document.getElementById("searchInput");
    if (searchInput) {
      searchInput.addEventListener("keyup", function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll(".table tbody tr");
        
        rows.forEach(row => {
          if (row.classList.contains("edit-row")) return;
          
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(filter) ? "" : "none";
        });
      });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
      initializeTheme();
      console.log('Page loaded successfully');
    });
  </script>
</body>
</html>