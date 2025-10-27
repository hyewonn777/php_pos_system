<?php
// Use unique session name for admin
session_name('admin_session');
session_start();
require 'db.php';

/* ----------------- SESSION CHECK ----------------- */
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

/* ---------------- FLASH MESSAGE HELPER ---------------- */
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}

/* ---------------- CREATE PHYSICAL ORDER ---------------- */
if (isset($_POST['create_physical_order'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $payment_method = $_POST['payment_method'];
    $created_by = $_SESSION['admin_username'] ?? 'Admin'; // Updated to admin session

    // Validate required fields
    if (empty($customer_name)) {
        setFlash("Customer name is required", 'error');
        header("Location: physical_orders.php");
        exit;
    }

    // Generate shorter order number for physical orders
    $order_number = 'PO-' . date('YmdHi') . rand(10, 99);

    // Calculate total from items
    $total_amount = 0;
    $total_quantity = 0;
    $items = [];

    // Validate items
    if (!isset($_POST['items']) || empty($_POST['items'])) {
        setFlash("Please add at least one item to the order.", 'error');
        header("Location: physical_orders.php");
        exit;
    }

    foreach ($_POST['items'] as $index => $item) {
        if (!empty($item['product']) && !empty($item['quantity']) && $item['quantity'] > 0) {
            $product = trim($item['product']);
            $quantity = intval($item['quantity']);
            
            if ($quantity <= 0) {
                setFlash("Invalid quantity for product: $product", 'error');
                header("Location: physical_orders.php");
                exit;
            }
            
            $total_quantity += $quantity;
            
            // Get product price and stock
            $stmt = $conn->prepare("SELECT id, name, price, qty FROM stock WHERE name = ? AND qty > 0");
            if ($stmt === false) {
                setFlash("Database error: " . $conn->error, 'error');
                header("Location: physical_orders.php");
                exit;
            }
            $stmt->bind_param("s", $product);
            $stmt->execute();
            $stmt->bind_result($product_id, $product_name, $unit_price, $stock_qty);
            
            if (!$stmt->fetch()) {
                $stmt->close();
                setFlash("Product not found or out of stock: $product", 'error');
                header("Location: physical_orders.php");
                exit;
            }
            $stmt->close();

            if ($stock_qty >= $quantity) {
                $subtotal = $unit_price * $quantity;
                $total_amount += $subtotal;
                
                $items[] = [
                    'product_id' => $product_id,
                    'product' => $product_name,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'subtotal' => $subtotal
                ];
            } else {
                setFlash("Insufficient stock for $product. Available: $stock_qty, Requested: $quantity", 'error');
                header("Location: physical_orders.php");
                exit;
            }
        }
    }

    if (empty($items)) {
        setFlash("Please add at least one valid item to the order.", 'error');
        header("Location: physical_orders.php");
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get the current admin's ID from session - UPDATED
        $admin_id = $_SESSION['admin_id'] ?? 1;
        $role = $_SESSION['admin_role'] ?? 'admin'; // Updated to admin role
        
        // Create physical order - FIXED: Correct number of parameters
        $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, quantity, order_type, payment_method, status, created_by, user_id, role, total_amount) VALUES (?, ?, ?, ?, 'physical', ?, 'Completed', ?, ?, ?, ?)");
        if ($stmt === false) {
            throw new Exception("Failed to prepare order statement: " . $conn->error);
        }
        
        // FIXED: Correct bind_param with proper number of parameters
        $stmt->bind_param("sssisisid", 
            $order_number, 
            $customer_name, 
            $customer_phone, 
            $total_quantity, 
            $payment_method, 
            $created_by, 
            $admin_id, // Updated to admin_id
            $role, 
            $total_amount
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create order: " . $stmt->error);
        }
        
        $order_id = $stmt->insert_id;
        $stmt->close();

        // Add order items - FIXED: Check if 'product' column exists, otherwise use 'product_name'
        foreach ($items as $item) {
            // First, check what columns exist in order_items table
            $check_columns = $conn->query("SHOW COLUMNS FROM order_items LIKE 'product'");
            $column_name = ($check_columns && $check_columns->num_rows > 0) ? 'product' : 'product_name';
            
            if ($check_columns) $check_columns->close();
            
            // Insert into order_items - FIXED: Use dynamic column name
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, $column_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt === false) {
                throw new Exception("Failed to prepare order_items statement: " . $conn->error);
            }
            $stmt->bind_param("iisidd", $order_id, $item['product_id'], $item['product'], $item['quantity'], $item['unit_price'], $item['subtotal']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to add order item: " . $stmt->error);
            }
            $stmt->close();

            // Update stock
            $stmt = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE id = ?");
            if ($stmt === false) {
                throw new Exception("Failed to prepare stock update statement: " . $conn->error);
            }
            $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update stock for " . $item['product']);
            }
            $stmt->close();

            // Add to sales table
            $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_id, order_number, customer_name, payment_method) VALUES (NOW(), ?, ?, ?, 'physical', ?, ?, ?, ?)");
            if ($stmt === false) {
                throw new Exception("Failed to prepare sales statement: " . $conn->error);
            }
            $stmt->bind_param("sidiiss", $item['product'], $item['quantity'], $item['subtotal'], $order_id, $order_number, $customer_name, $payment_method);
            
            if (!$stmt->execute()) {
                // Log error but don't stop execution
                error_log("Failed to record sale for " . $item['product'] . ": " . $stmt->error);
            }
            $stmt->close();
        }

        $conn->commit();
        setFlash("Physical Order #$order_number created successfully! Total: ₱" . number_format($total_amount, 2));

    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error creating order: " . $e->getMessage(), 'error');
        error_log("Physical order error: " . $e->getMessage());
    }

    header("Location: physical_orders.php");
    exit;
}

/* ---------------- GET AVAILABLE PRODUCTS ---------------- */
$products = [];
$result = $conn->query("SELECT id, name, price, qty FROM stock WHERE qty > 0 ORDER BY name");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

/* ---------------- GET PHYSICAL ORDER STATISTICS ---------------- */
$physical_stats = ['total_orders' => 0, 'pending' => 0, 'completed' => 0, 'total_revenue' => 0];

// Calculate statistics from orders
$stats_query = "SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
    COALESCE(SUM(total_amount), 0) as total_revenue
FROM orders WHERE order_type = 'physical'";

$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $physical_stats = $stats_result->fetch_assoc();
    if (!$physical_stats) {
        $physical_stats = ['total_orders' => 0, 'pending' => 0, 'completed' => 0, 'total_revenue' => 0];
    }
}

/* ---------------- GET PHYSICAL ORDERS FOR DISPLAY ---------------- */
$orders = [];
// First, check what columns exist in order_items table
$check_columns = $conn->query("SHOW COLUMNS FROM order_items LIKE 'product'");
$product_column = ($check_columns && $check_columns->num_rows > 0) ? 'product' : 'product_name';
if ($check_columns) $check_columns->close();

// FIXED: Use dynamic column name based on what exists in the database
$orders_query = "SELECT 
    o.*,
    COALESCE(SUM(oi.subtotal), 0) as order_total,
    GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.$product_column) SEPARATOR ', ') as items_list
FROM orders o 
LEFT JOIN order_items oi ON o.id = oi.order_id 
WHERE o.order_type = 'physical' 
GROUP BY o.id 
ORDER BY o.created_at DESC 
LIMIT 50";

$orders_result = $conn->query($orders_query);
if ($orders_result && $orders_result->num_rows > 0) {
    while ($row = $orders_result->fetch_assoc()) {
        $orders[] = $row;
    }
} else {
    // If the above query fails, try a simpler approach
    $orders_query = "SELECT * FROM orders WHERE order_type = 'physical' ORDER BY created_at DESC LIMIT 50";
    $orders_result = $conn->query($orders_query);
    if ($orders_result && $orders_result->num_rows > 0) {
        while ($row = $orders_result->fetch_assoc()) {
            // Get items separately
            $order_id = $row['id'];
            // FIXED: Use dynamic column name
            $items_query = $conn->query("
                SELECT oi.quantity, oi.$product_column as product, oi.subtotal 
                FROM order_items oi 
                WHERE oi.order_id = $order_id
            ");
            
            $items_list = [];
            $order_total = 0;
            if ($items_query && $items_query->num_rows > 0) {
                while ($item = $items_query->fetch_assoc()) {
                    $items_list[] = $item['quantity'] . 'x ' . $item['product'];
                    $order_total += $item['subtotal'];
                }
                $row['items_list'] = implode(', ', $items_list);
            } else {
                $row['items_list'] = "No items recorded";
            }
            
            $row['order_total'] = $order_total;
            $orders[] = $row;
        }
    }
}

// Get current time for greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 13) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}

// Safely get username with fallback - UPDATED TO ADMIN SESSION
$username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'Admin';

/* -------------------- ROLE-BASED ACCESS CONTROL -------------------- */

// Function to check user role and permissions - UPDATED FOR ADMIN SESSION
function checkAccess($required_role = null) {
    // Use admin session
    if (session_name() !== 'admin_session') {
        session_name('admin_session');
        session_start();
    }
    
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    
    // Get user role from session or database
    if (!isset($_SESSION['admin_role'])) {
        global $conn;
        $admin_id = $_SESSION['admin_id'];
        $stmt = $conn->prepare("SELECT role, username FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user_data = $result->fetch_assoc();
            $_SESSION['admin_role'] = $user_data['role'];
            $_SESSION['admin_username'] = $user_data['username']; // Ensure username is set
        } else {
            header('Location: logout.php');
            exit;
        }
    }
    
    // Check if specific role is required
    if ($required_role && $_SESSION['admin_role'] !== $required_role) {
        if ($_SESSION['admin_role'] === 'photographer') {
            header('Location: appointment.php');
        } else {
            header('Location: access_denied.php');
        }
        exit;
    }
    
    return $_SESSION['admin_role'];
}

// Check access for current page and get current role
$current_role = checkAccess();

// Specific page restrictions - add this to each protected page
$current_page = basename($_SERVER['PHP_SELF']);

if ($current_role === 'photographer' && $current_page !== 'appointment.php' && $current_page !== 'logout.php') {
    header('Location: appointment.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Physical Orders - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #4361ee;
      --primary-dark: #3a56d4;
      --primary-light: #4cc9f0;
      --secondary: #7209b7;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #e63946;
      --info: #4895ef;
      --light: #f8f9fa;
      --dark: #212529;
      --gray: #6c757d;
      --gray-light: #adb5bd;
      --sidebar-width: 260px;
      --header-height: 70px;
      --card-radius: 16px;
      --transition: all 0.3s ease;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.12);
    }

    .dark-mode {
      --bg: #0f1419;
      --card-bg: #1a222d;
      --text: #f1f5f9;
      --text-muted: #94a3b8;
      --sidebar-bg: #0a0f14;
      --border: #2a3341;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.3);
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
      font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      background-color: var(--bg);
      color: var(--text);
      transition: var(--transition);
      line-height: 1.6;
      overflow-x: hidden;
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Styles */
    .sidebar {
      width: var(--sidebar-width);
      background: linear-gradient(180deg, var(--sidebar-bg) 0%, #151f2e 100%);
      color: white;
      height: 100vh;
      position: fixed;
      overflow-y: auto;
      transition: var(--transition);
      z-index: 1000;
      box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
    }

    .sidebar-header {
      padding: 24px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(0, 0, 0, 0.2);
    }

    .logo {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .sidebar-title {
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.5px;
    }

    .sidebar-menu {
      padding: 20px 0;
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 14px 20px;
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: var(--transition);
      gap: 14px;
      margin: 4px 12px;
      border-radius: 10px;
      position: relative;
      overflow: hidden;
    }

    .menu-item:before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transition: left 0.5s;
    }

    .menu-item:hover:before {
      left: 100%;
    }

    .menu-item:hover, .menu-item.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      transform: translateX(5px);
    }

    .menu-item.active {
      background: linear-gradient(90deg, rgba(67, 97, 238, 0.3) 0%, rgba(67, 97, 238, 0.1) 100%);
      border-left: 4px solid var(--primary);
    }

    .menu-item i {
      width: 20px;
      text-align: center;
      font-size: 18px;
    }

    .sidebar-footer {
      padding: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: auto;
    }

    .logout-btn {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      border: none;
      padding: 12px;
      width: 100%;
      border-radius: 10px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: var(--transition);
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.3);
    }

    .logout-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(230, 57, 70, 0.4);
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 25px;
      transition: var(--transition);
      background: var(--bg);
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 0 25px 0;
      margin-bottom: 30px;
      position: relative;
    }

    .header:after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--border), transparent);
    }

    .header-title h1 {
      font-size: 32px;
      font-weight: 800;
      margin-bottom: 8px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.5px;
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 16px;
      font-weight: 500;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .theme-toggle, .notification-btn, .user-menu, .menu-toggle {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--card-bg);
      color: var(--text);
      cursor: pointer;
      transition: var(--transition);
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }

    .theme-toggle:before, .notification-btn:before, .user-menu:before, .menu-toggle:before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(67, 97, 238, 0.1), transparent);
      transition: left 0.5s;
    }

    .theme-toggle:hover:before, .notification-btn:hover:before, .user-menu:hover:before, .menu-toggle:hover:before {
      left: 100%;
    }

    .theme-toggle:hover, .notification-btn:hover, .user-menu:hover, .menu-toggle:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }

    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      font-size: 11px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      box-shadow: 0 2px 8px rgba(230, 57, 70, 0.4);
    }

    .notification-wrapper {
      position: relative;
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 24px;
      margin-bottom: 40px;
    }

    .stat-card {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border-left: 4px solid var(--primary);
      cursor: pointer;
      position: relative;
      overflow: hidden;
    }

    .stat-card:before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.5s ease;
    }

    .stat-card:hover:before {
      transform: scaleX(1);
    }

    .stat-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-hover);
    }

    .stat-card.warning {
      border-left-color: var(--warning);
    }

    .stat-card.warning:before {
      background: linear-gradient(90deg, var(--warning) 0%, #fbbf24 100%);
    }

    .stat-card.danger {
      border-left-color: var(--danger);
    }

    .stat-card.danger:before {
      background: linear-gradient(90deg, var(--danger) 0%, #c53030 100%);
    }

    .stat-card.success {
      border-left-color: var(--success);
    }

    .stat-card.success:before {
      background: linear-gradient(90deg, var(--success) 0%, #34d399 100%);
    }

    .stat-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(67, 97, 238, 0.05) 100%);
      color: var(--primary);
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.15);
    }

    .stat-card.warning .stat-icon {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%);
      color: var(--warning);
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
    }

    .stat-card.danger .stat-icon {
      background: linear-gradient(135deg, rgba(230, 57, 70, 0.1) 0%, rgba(230, 57, 70, 0.05) 100%);
      color: var(--danger);
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.15);
    }

    .stat-card.success .stat-icon {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
    }

    .stat-value {
      font-size: 32px;
      font-weight: 800;
      margin-bottom: 8px;
      background: linear-gradient(90deg, var(--text) 0%, var(--text-muted) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .stat-label {
      color: var(--text-muted);
      font-size: 15px;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    /* Order Form Styles */
    .order-form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 40px;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
    }

    .order-form-container:before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.5s ease;
    }

    .order-form-container:hover:before {
      transform: scaleX(1);
    }

    .order-form-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 20px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text);
      font-size: 14px;
    }

    .form-control {
      width: 100%;
      padding: 12px 16px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
      box-shadow: 0 2px 6px var(--shadow);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    .order-items {
      margin: 25px 0;
    }

    .item-row {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1fr auto;
      gap: 15px;
      align-items: end;
      margin-bottom: 15px;
      padding: 20px;
      background: rgba(0, 0, 0, 0.02);
      border-radius: 12px;
      transition: var(--transition);
      border: 1px solid var(--border);
    }

    .dark-mode .item-row {
      background: rgba(255, 255, 255, 0.02);
    }

    .item-row:hover {
      background: rgba(67, 97, 238, 0.05);
      transform: translateY(-2px);
    }

    .payment-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(67, 97, 238, 0.1);
      color: var(--primary);
      border: 1px solid rgba(67, 97, 238, 0.2);
    }

    .btn {
      padding: 12px 20px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: var(--shadow);
      font-size: 14px;
    }

    .btn:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
    }

    .btn-danger:hover {
      background: linear-gradient(135deg, #c53030 0%, var(--danger) 100%);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
      color: white;
    }

    .btn-success:hover {
      background: linear-gradient(135deg, #34d399 0%, var(--success) 100%);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
      color: white;
    }

    .btn-warning:hover {
      background: linear-gradient(135deg, #fbbf24 0%, var(--warning) 100%);
    }

    .btn-sm {
      padding: 10px 16px;
      font-size: 13px;
    }

    .btn-lg {
      padding: 16px 28px;
      font-size: 16px;
    }

    .total-display {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        margin-top: 25px;
        box-shadow: var(--shadow);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .section-title {
        font-size: 20px;
        font-weight: 700;
        position: relative;
        padding-left: 12px;
    }

    .section-title:before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 4px;
        background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
        border-radius: 4px;
    }

    .stock-info {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 5px;
        font-weight: 500;
    }

    .out-of-stock {
        color: var(--danger);
    }

    .low-stock {
        color: var(--warning);
    }

    /* Orders Table */
    .orders-table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 40px;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
    }

    .orders-table-container:before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.5s ease;
    }

    .orders-table-container:hover:before {
      transform: scaleX(1);
    }

    .orders-table-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .table-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      transition: var(--transition);
    }

    .search-box {
      position: relative;
      width: 300px;
      transition: var(--transition);
    }

    .search-box input {
      width: 100%;
      padding: 12px 16px 12px 42px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
      box-shadow: var(--shadow);
    }

    .search-box input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    .search-box i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      transition: var(--transition);
    }

    .orders-table {
      width: 100%;
      border-collapse: collapse;
      transition: var(--transition);
      min-width: 1000px;
    }

    .orders-table th, .orders-table td {
      padding: 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      transition: var(--transition);
    }

    .orders-table th {
      color: var(--text-muted);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .orders-table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .orders-table tr {
      transition: var(--transition);
    }

    .orders-table tr:hover {
      background: rgba(67, 97, 238, 0.03);
    }

    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-completed {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-processing {
      background: rgba(67, 97, 238, 0.1);
      color: var(--primary);
      border: 1px solid rgba(67, 97, 238, 0.2);
    }

    /* Flash Message */
    .flash-message {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 30px;
      font-weight: 600;
      transition: var(--transition);
      animation: slideIn 0.5s ease-out;
      box-shadow: var(--shadow);
      border-left: 4px solid;
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
      color: var(--success);
      border-left-color: var(--success);
    }

    .flash-error {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
      border-left-color: var(--danger);
    }

    /* Footer */
    .footer {
      text-align: center;
      padding: 24px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 40px;
      font-weight: 500;
    }

    /* Welcome Section */
    .welcome-container {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 30px;
    }

    .welcome-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
      font-weight: 700;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .welcome-text h2 {
      font-size: 22px;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .welcome-text p {
      color: var(--text-muted);
      font-size: 15px;
    }

    /* Toggle Switch */
    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 54px;
      height: 28px;
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
      background: linear-gradient(135deg, var(--gray-light) 0%, var(--gray) 100%);
      transition: .4s;
      border-radius: 28px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 20px;
      width: 20px;
      left: 4px;
      bottom: 4px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }

    input:checked + .slider {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
    }

    input:checked + .slider:before {
      transform: translateX(26px);
    }

    /* Mobile Responsive */
    .menu-toggle {
      display: none;
    }

    @media (max-width: 992px) {
      .sidebar {
        transform: translateX(-100%);
        width: 280px;
      }
      
      .sidebar.active {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        padding: 20px;
      }
      
      .menu-toggle {
        display: flex;
      }
      
      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
      }
      
      .stat-card {
        padding: 20px;
      }
      
      .stat-value {
        font-size: 24px;
      }
      
      .header-title h1 {
        font-size: 26px;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }

      .item-row {
        grid-template-columns: 1fr;
        gap: 15px;
      }
    }

    @media (max-width: 768px) {
      .main-content {
        padding: 15px;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .welcome-container {
        flex-direction: column;
        text-align: center;
        gap: 10px;
      }
      
      .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      
      .header-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .orders-table-container {
        overflow-x: auto;
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

    @media (max-width: 480px) {
      .order-form-container, .orders-table-container {
        padding: 18px;
      }
      
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
    }

    /* Overlay for mobile sidebar */
    .sidebar-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      display: none;
    }

    .sidebar-overlay.active {
      display: block;
    }
  </style>
</head>
<body class="light-mode">
  <!-- Mobile Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">M</div>
        <div class="sidebar-title">Marcomedia POS</div>
    </div>
    <div class="sidebar-menu">
        <?php if ($current_role === 'admin'): ?>
            <!-- Admin sees all menu items -->
            <a href="index.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">Dashboard</span>
            </a>
            <a href="sales.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'sales.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i>
                <span class="menu-text">Sales & Orders</span>
            </a>
            <a href="stock.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'stock.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i>
                <span class="menu-text">Inventory</span>
            </a>
            <a href="physical_orders.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'physical_orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-store"></i>
                <span class="menu-text">Physical Orders</span>
            </a>
            <a href="appointment.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'appointment.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Appointments</span>
            </a>
            <a href="user_management.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'user_management.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-text">Staff Management</span>
            </a>
        <?php else: ?>
            <!-- Photographer sees only appointments -->
            <a href="appointment.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'appointment.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Appointments</span>
            </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-footer">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
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
          <h1>Physical Orders</h1>
          <p>Create and manage walk-in customer orders</p>
        </div>
        <div class="header-actions">
          <div class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
          </div>
          <label class="theme-toggle" for="theme-toggle">
            <i class="fas fa-moon"></i>
          </label>
        </div>
      </div>

      <!-- Welcome Section -->
      <div class="welcome-container">
        <div class="welcome-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div class="welcome-text">
          <h2><?php echo $greeting; ?>, <?php echo $username; ?>!</h2>
          <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
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
        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $physical_stats['total_orders'] ?? 0; ?></div>
              <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-shopping-bag"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $physical_stats['completed'] ?? 0; ?></div>
              <div class="stat-label">Completed Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-check-circle"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $physical_stats['pending'] ?? 0; ?></div>
              <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-clock"></i>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value">₱<?php echo number_format($physical_stats['total_revenue'] ?? 0, 2); ?></div>
              <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-money-bill-wave"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Physical Order Form -->
      <div class="order-form-container">
        <div class="section-header">
          <div class="section-title">Create New Physical Order</div>
        </div>

        <form method="POST" id="orderForm">
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Customer Name *</label>
              <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name" required value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''; ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Phone Number</label>
              <input type="tel" name="customer_phone" class="form-control" placeholder="Enter phone number" value="<?php echo isset($_POST['customer_phone']) ? htmlspecialchars($_POST['customer_phone']) : ''; ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Payment Method *</label>
              <select name="payment_method" class="form-control" required>
                <option value="cash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                <option value="card" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'card') ? 'selected' : ''; ?>>Credit/Debit Card</option>
                <option value="gcash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'gcash') ? 'selected' : ''; ?>>GCash</option>
                <option value="bank_transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
              </select>
            </div>
          </div>

          <!-- Order Items -->
          <div class="order-items">
            <div class="section-header">
              <div class="section-title">Order Items</div>
              <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">
                <i class="fas fa-plus"></i> Add Item
              </button>
            </div>

            <div id="itemsContainer">
              <!-- Item rows will be added here dynamically -->
            </div>

            <div class="total-display" id="totalAmount">
              Total: ₱0.00
            </div>
          </div>

          <div class="form-group" style="text-align: right; margin-top: 20px;">
            <button type="submit" name="create_physical_order" class="btn btn-primary btn-lg">
              <i class="fas fa-check"></i> Create Physical Order
            </button>
          </div>
        </form>
      </div>

      <!-- Recent Physical Orders -->
      <div class="orders-table-container">
        <div class="section-header">
          <div class="section-title">Recent Physical Orders</div>
        </div>
        
        <div class="table-actions">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search orders...">
          </div>
        </div>

        <table class="orders-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Customer</th>
              <th>Contact</th>
              <th>Payment</th>
              <th>Items</th>
              <th>Total</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($orders)): ?>
              <?php foreach ($orders as $order): ?>
                <tr>
                  <td><strong><?= $order['order_number'] ?: '#' . $order['id']; ?></strong></td>
                  <td><?= htmlspecialchars($order['customer_name']); ?></td>
                  <td><?= $order['customer_phone'] ?: 'N/A'; ?></td>
                  <td><span class="payment-badge"><?= ucfirst($order['payment_method']); ?></span></td>
                  <td title="<?= htmlspecialchars($order['items_list']); ?>">
                    <?php
                    $items = $order['items_list'];
                    echo strlen($items) > 50 ? htmlspecialchars(substr($items, 0, 50)) . '...' : htmlspecialchars($items);
                    ?>
                  </td>
                  <td><strong>₱<?= number_format($order['order_total'], 2); ?></strong></td>
                  <td>
                    <span class="status-badge status-<?= strtolower($order['status']); ?>">
                      <?= $order['status']; ?>
                    </span>
                  </td>
                  <td><?= date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 40px;">
                  <i class="fas fa-shopping-bag" style="font-size: 48px; color: var(--text-muted); margin-bottom: 15px; display: block;"></i>
                  No physical orders found.
                  <p style="color: var(--text-muted); margin-top: 10px;">Create your first physical order using the form above.</p>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved.</p>
      </div>
    </div>
  </div>

  <script>
// Products data from PHP
const products = <?php echo json_encode($products); ?>;

let itemCount = 0;

function addItemRow() {
    itemCount++;
    const container = document.getElementById('itemsContainer');
    
    const itemRow = document.createElement('div');
    itemRow.className = 'item-row';
    itemRow.innerHTML = `
        <div class="form-group">
            <label class="form-label">Product *</label>
            <select name="items[${itemCount}][product]" class="form-control product-select" onchange="updatePrice(this, ${itemCount})" required>
                <option value="">Select Product</option>
                ${products.map(p => {
                  const stockStatus = p.qty <= 0 ? ' (Out of Stock)' : p.qty <= 5 ? ' (Low Stock)' : '';
                  const disabled = p.qty <= 0 ? 'disabled' : '';
                  return `<option value="${p.name}" data-price="${p.price}" data-stock="${p.qty}" ${disabled}>${p.name} - ₱${p.price} (Stock: ${p.qty}${stockStatus})</option>`;
                }).join('')}
            </select>
            <div class="stock-info" id="stockInfo${itemCount}"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Quantity *</label>
            <input type="number" name="items[${itemCount}][quantity]" class="form-control quantity-input" min="1" value="1" onchange="updateSubtotal(${itemCount})" oninput="updateSubtotal(${itemCount})" required>
        </div>
        <div class="form-group">
            <label class="form-label">Unit Price</label>
            <input type="text" class="form-control price-display" readonly value="₱0.00">
        </div>
        <div class="form-group">
            <label class="form-label">Subtotal</label>
            <input type="text" class="form-control subtotal-display" readonly value="₱0.00">
        </div>
        <div class="form-group">
            <button type="button" class="btn btn-danger btn-sm" onclick="removeItemRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(itemRow);
    updateTotal();
    
    // Enable delete button if there are multiple rows
    const deleteButtons = document.querySelectorAll('.btn-danger');
    deleteButtons.forEach(btn => {
        btn.disabled = deleteButtons.length <= 1;
    });
}

function removeItemRow(button) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) {
        button.closest('.item-row').remove();
        updateTotal();
        
        // Update delete buttons state
        const deleteButtons = document.querySelectorAll('.btn-danger');
        deleteButtons.forEach(btn => {
            btn.disabled = deleteButtons.length <= 1;
        });
    }
}

function updatePrice(select, index) {
    const selectedOption = select.selectedOptions[0];
    const price = selectedOption?.dataset.price || 0;
    const stock = parseInt(selectedOption?.dataset.stock) || 0;
    
    const row = select.closest('.item-row');
    const quantityInput = row.querySelector('.quantity-input');
    const stockInfo = document.getElementById(`stockInfo${index}`);
    
    // Update stock info
    if (stock <= 0) {
        stockInfo.innerHTML = '<span class="out-of-stock">Out of Stock</span>';
        quantityInput.disabled = true;
        quantityInput.value = 0;
    } else if (stock <= 5) {
        stockInfo.innerHTML = `<span class="low-stock">Low Stock - Only ${stock} available</span>`;
        quantityInput.disabled = false;
        quantityInput.max = stock;
    } else {
        stockInfo.innerHTML = `<span>In Stock - ${stock} available</span>`;
        quantityInput.disabled = false;
        quantityInput.max = stock;
    }
    
    // Update price display
    row.querySelector('.price-display').value = `₱${parseFloat(price).toFixed(2)}`;
    
    // Update quantity if it exceeds stock
    if (parseInt(quantityInput.value) > stock) {
        quantityInput.value = stock > 0 ? 1 : 0;
    }
    
    updateSubtotal(index);
}

function updateSubtotal(index) {
    const row = document.querySelector(`[name="items[${index}][quantity]"]`)?.closest('.item-row');
    if (!row) return;
    
    const quantity = parseInt(row.querySelector('.quantity-input').value) || 0;
    const priceText = row.querySelector('.price-display').value;
    const price = parseFloat(priceText.replace('₱', '')) || 0;
    const subtotal = quantity * price;
    
    row.querySelector('.subtotal-display').value = `₱${subtotal.toFixed(2)}`;
    updateTotal();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.subtotal-display').forEach(input => {
        const value = parseFloat(input.value.replace('₱', '')) || 0;
        total += value;
    });
    
    document.getElementById('totalAmount').textContent = `Total: ₱${total.toFixed(2)}`;
}

// Initialize the first row when page loads
document.addEventListener('DOMContentLoaded', function() {
    addItemRow();
    
    // Mobile sidebar functionality
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
      });
      
      sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
      });
    }
    
    // Theme toggle functionality
    const themeToggle = document.getElementById('theme-toggle');
    const savedTheme = localStorage.getItem('theme');
    
    if (savedTheme === 'dark') {
        document.body.classList.remove('light-mode');
        document.body.classList.add('dark-mode');
        themeToggle.checked = true;
    } else {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
    }
    
    themeToggle.addEventListener('change', function() {
        if (this.checked) {
            document.body.classList.remove('light-mode');
            document.body.classList.add('dark-mode');
            localStorage.setItem('theme', 'dark');
        } else {
            document.body.classList.remove('dark-mode');
            document.body.classList.add('light-mode');
            localStorage.setItem('theme', 'light');
        }
    });
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.orders-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }

    // Update current date and time
    updateDateTime();
    setInterval(updateDateTime, 60000); // Update every minute
});

// Update date and time
function updateDateTime() {
  const now = new Date();
  const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
  const dateString = now.toLocaleDateString('en-US', options);
  document.getElementById('current-date').textContent = dateString;
}

// Form validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    let hasValidItems = false;
    let errorMessages = [];
    
    // Check customer name
    const customerName = document.querySelector('[name="customer_name"]').value.trim();
    if (!customerName) {
        errorMessages.push('Customer name is required');
    }
    
    // Check items
    document.querySelectorAll('.item-row').forEach(row => {
        const product = row.querySelector('.product-select').value;
        const quantity = parseInt(row.querySelector('.quantity-input').value) || 0;
        const stock = parseInt(row.querySelector('.product-select').selectedOptions[0]?.dataset.stock) || 0;
        
        if (product && quantity > 0) {
            hasValidItems = true;
            
            if (quantity > stock) {
                errorMessages.push(`Quantity for ${product} exceeds available stock (${stock})`);
            }
        }
    });
    
    if (!hasValidItems) {
        errorMessages.push('Please add at least one valid item to the order.');
    }
    
    if (errorMessages.length > 0) {
        e.preventDefault();
        alert('Please fix the following errors:\n\n' + errorMessages.join('\n'));
        return;
    }
});
  </script>
</body>
</html>