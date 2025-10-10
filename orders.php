<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Check if user is logged in FIRST
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require 'db.php';

// Safely get username with fallback
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';

// Check for order success message from checkout
if (isset($_SESSION['order_success'])) {
    setFlash($_SESSION['order_success'], 'success');
    unset($_SESSION['order_success']);
}

/* ---------------- FLASH MESSAGE HELPER ---------------- */
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}

/* ---------------- GET CUSTOMER FULLNAME ---------------- */
function getCustomerFullname($conn, $customer_name) {
    // Check if it's an email (website order)
    if (filter_var($customer_name, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT fullname FROM users WHERE email = ?");
        if ($stmt === false) {
            error_log("Prepare failed: " . $conn->error);
            return $customer_name;
        }
        $stmt->bind_param("s", $customer_name);
        $stmt->execute();
        $stmt->bind_result($fullname);
        if ($stmt->fetch()) {
            $stmt->close();
            return $fullname . " (" . $customer_name . ")";
        }
        $stmt->close();
    }
    return $customer_name;
}

/* ---------------- CONFIRM ORDER ---------------- */
if (isset($_POST['action']) && $_POST['action'] == 'confirm_order' && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    
    error_log("Confirm order called for ID: " . $order_id);
    
    $conn->begin_transaction();
    try {
        // First, get order details to check if it's from website or physical
        $stmt = $conn->prepare("SELECT order_number, customer_name, order_type FROM orders WHERE id = ?");
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->bind_result($order_number, $customer_name, $order_type);
        $stmt->fetch();
        $stmt->close();

        // Update order status to Confirmed
        $stmt = $conn->prepare("UPDATE orders SET status='Confirmed' WHERE id=?");
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();

        // If it's a website order and confirmed, push to sales tracking
        if ($order_type == 'website' || $order_type == 'online') {
            // Get order items
            $items_stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            if ($items_stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $items_stmt->bind_param("i", $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            while ($item = $items_result->fetch_assoc()) {
                // Get product details
                $product_stmt = $conn->prepare("SELECT name, price FROM stock WHERE id = ?");
                if ($product_stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $product_stmt->bind_param("i", $item['product_id']);
                $product_stmt->execute();
                $product_stmt->bind_result($product_name, $price);
                $product_stmt->fetch();
                $product_stmt->close();
                
                $subtotal = $price * $item['quantity'];
                
                // Insert into sales table
                $sales_stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_number) VALUES (NOW(), ?, ?, ?, ?, ?)");
                if ($sales_stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $sales_stmt->bind_param("sidds", $product_name, $item['quantity'], $subtotal, $order_type, $order_number);
                $sales_stmt->execute();
                $sales_stmt->close();
            }
            $items_stmt->close();
            
            setFlash("Order #$order_id confirmed and pushed to sales tracking!");
        } else {
            setFlash("Order #$order_id confirmed successfully!");
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error confirming order: " . $e->getMessage(), 'error');
        error_log("Confirm order error: " . $e->getMessage());
    }

    header("Location: orders.php");
    exit;
}

/* ---------------- RECEIVE ORDER ---------------- */
if (isset($_POST['action']) && $_POST['action'] == 'receive_order' && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);

    error_log("Receive order called for ID: " . $order_id);

    $conn->begin_transaction();
    try {
        // Get order details first
        $order_stmt = $conn->prepare("SELECT order_number, order_type FROM orders WHERE id = ?");
        if ($order_stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $order_stmt->bind_param("i", $order_id);
        $order_stmt->execute();
        $order_stmt->bind_result($order_number, $order_type);
        $order_stmt->fetch();
        $order_stmt->close();

        // Fetch order items
        $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
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
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ii", $qty, $product_id);
            $stmt->execute();
            $stmt->close();

            // Get product details for sales record
            $stmt = $conn->prepare("SELECT name, price FROM stock WHERE id=?");
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $stmt->bind_result($product_name, $price);
            $stmt->fetch();
            $stmt->close();

            $total = $price * $qty;
            
            // Insert sale record with order information
            $check_column = $conn->query("SHOW COLUMNS FROM sales LIKE 'order_number'");
            if ($check_column->num_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_number, status) VALUES (NOW(), ?, ?, ?, ?, ?, 'received')");
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("sidds", $product_name, $qty, $total, $order_type, $order_number);
            } else {
                $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, status) VALUES (NOW(), ?, ?, ?, ?, 'received')");
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("sidd", $product_name, $qty, $total, $order_type);
            }
            $stmt->execute();
            $stmt->close();
        }

        // Update order status to Received
        $stmt = $conn->prepare("UPDATE orders SET status='Received' WHERE id=?");
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        setFlash("Order #$order_id marked as received and inventory updated!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error receiving order: " . $e->getMessage(), 'error');
        error_log("Receive order error: " . $e->getMessage());
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
        if ($delItems === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $delItems->bind_param($types, ...$order_ids);
        $delItems->execute();
        $delItems->close();

        // Delete orders
        $delOrders = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        if ($delOrders === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $delOrders->bind_param($types, ...$order_ids);
        $delOrders->execute();
        $delOrders->close();

        $conn->commit();
        setFlash("Selected orders deleted successfully!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error deleting orders: " . $e->getMessage(), 'error');
        error_log("Delete orders error: " . $e->getMessage());
    }

    header("Location: orders.php");
    exit;
}

// Get order statistics with error handling
$pendingCount = 0;
$confirmedCount = 0;
$receivedCount = 0;
$cancelledCount = 0;

$pendingResult = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Pending'");
if ($pendingResult && $pendingResult->num_rows > 0) {
    $pendingCount = $pendingResult->fetch_assoc()['cnt'];
}

$confirmedResult = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Confirmed'");
if ($confirmedResult && $confirmedResult->num_rows > 0) {
    $confirmedCount = $confirmedResult->fetch_assoc()['cnt'];
}

$receivedResult = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Received'");
if ($receivedResult && $receivedResult->num_rows > 0) {
    $receivedCount = $receivedResult->fetch_assoc()['cnt'];
}

$cancelledResult = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Cancelled'");
if ($cancelledResult && $cancelledResult->num_rows > 0) {
    $cancelledCount = $cancelledResult->fetch_assoc()['cnt'];
}

// Get all orders for display - SIMPLIFIED QUERY THAT WORKS
$orders = [];
$orders_query = $conn->query("
    SELECT o.*, 
           COALESCE(u.fullname, o.customer_name) as display_name,
           COALESCE(u.email, o.customer_name) as customer_email
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.id 
    ORDER BY o.created_at DESC
");

if ($orders_query === false) {
    error_log("Orders query failed: " . $conn->error);
    // Try simpler query without join
    $orders_query = $conn->query("SELECT * FROM orders ORDER BY created_at DESC");
}

if ($orders_query && $orders_query->num_rows > 0) {
    $orders = $orders_query->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("No orders found or query failed");
}

// Get current time for greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 18) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Orders - Marcomedia POS</title>
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

    .stat-change {
      display: flex;
      align-items: center;
      font-size: 13px;
      margin-top: 12px;
      font-weight: 600;
    }

    .stat-change.positive {
      color: var(--success);
    }

    .stat-change.negative {
      color: var(--danger);
    }

    /* Orders Table - Fixed Alignment */
    .orders-table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
      overflow-x: auto;
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
      padding: 14px 20px 14px 48px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 15px;
      transition: var(--transition);
      box-shadow: var(--shadow);
      font-weight: 500;
    }

    .search-box input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 2px 15px rgba(67, 97, 238, 0.2);
      transform: translateY(-2px);
    }

    .search-box i {
      position: absolute;
      left: 20px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      transition: var(--transition);
      font-size: 16px;
    }

    .action-buttons {
      display: flex;
      gap: 12px;
      transition: var(--transition);
    }

    .btn {
      padding: 14px 20px;
      border-radius: 12px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: var(--shadow);
      font-size: 14px;
      letter-spacing: 0.3px;
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
      background: linear-gradient(135deg, var(--primary-dark) 0%, #2f44c2 100%);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
    }

    .btn-danger:hover {
      background: linear-gradient(135deg, #c53030 0%, #a02626 100%);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success) 0%, #0d9c6d 100%);
      color: white;
    }

    .btn-success:hover {
      background: linear-gradient(135deg, #0d9c6d 0%, #0a7a55 100%);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
      color: white;
    }

    .btn-warning:hover {
      background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    }

    /* Fixed Table Alignment */
    .orders-table {
      width: 100%;
      border-collapse: collapse;
      transition: var(--transition);
      min-width: 1000px;
      table-layout: fixed; /* Added for consistent column widths */
    }

    .orders-table th, .orders-table td {
      padding: 16px 20px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      transition: var(--transition);
      vertical-align: top; /* Ensure consistent vertical alignment */
    }

    .orders-table th {
      color: var(--text-muted);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: var(--card-bg);
      transition: var(--transition);
      position: sticky;
      top: 0;
      z-index: 10;
      border-bottom: 2px solid var(--border); /* Stronger border for header */
    }

    /* Define consistent column widths */
    .orders-table th:nth-child(1),
    .orders-table td:nth-child(1) {
      width: 50px; /* Checkbox column */
      text-align: center;
    }

    .orders-table th:nth-child(2),
    .orders-table td:nth-child(2) {
      width: 120px; /* Order ID column */
    }

    .orders-table th:nth-child(3),
    .orders-table td:nth-child(3) {
      width: 200px; /* Customer column */
    }

    .orders-table th:nth-child(4),
    .orders-table td:nth-child(4) {
      width: 250px; /* Items column */
    }

    .orders-table th:nth-child(5),
    .orders-table td:nth-child(5) {
      width: 100px; /* Total column */
    }

    .orders-table th:nth-child(6),
    .orders-table td:nth-child(6) {
      width: 120px; /* Status column */
    }

    .orders-table th:nth-child(7),
    .orders-table td:nth-child(7) {
      width: 150px; /* Date column */
    }

    .orders-table th:nth-child(8),
    .orders-table td:nth-child(8) {
      width: 200px; /* Actions column */
    }

    .checkbox-cell {
      text-align: center;
      transition: var(--transition);
    }

    /* Ensure content doesn't overflow in cells */
    .orders-table td {
      word-wrap: break-word;
      overflow-wrap: break-word;
    }

    .orders-table tr {
      transition: var(--transition);
    }

    .orders-table tr:hover {
      background: rgba(67, 97, 238, 0.03);
      transform: translateY(-1px);
    }

    .dark-mode .orders-table tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    .status-badge {
      padding: 8px 16px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.3px;
      transition: var(--transition);
      display: inline-block;
      white-space: nowrap;
    }

    .status-pending {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%);
      color: var(--warning);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-confirmed {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-received {
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%);
      color: #3b82f6;
      border: 1px solid rgba(59, 130, 246, 0.2);
    }

    .status-cancelled {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
      color: var(--danger);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .status-completed {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .action-cell {
      display: flex;
      gap: 8px;
      transition: var(--transition);
      flex-wrap: wrap;
      min-width: 200px;
    }

    .action-btn {
      padding: 8px 14px;
      border-radius: 8px;
      border: none;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow);
      white-space: nowrap;
      display: flex;
      align-items: center;
      gap: 6px;
      letter-spacing: 0.3px;
    }

    .action-btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-hover);
    }

    .action-btn.success {
      background: linear-gradient(135deg, var(--success) 0%, #0d9c6d 100%);
      color: white;
    }

    .action-btn.success:hover {
      background: linear-gradient(135deg, #0d9c6d 0%, #0a7a55 100%);
    }

    .action-btn.danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
    }

    .action-btn.danger:hover {
      background: linear-gradient(135deg, #c53030 0%, #a02626 100%);
    }

    .action-btn:disabled {
      background: var(--gray-light);
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    /* Flash Message */
    .flash-message {
      padding: 16px 24px;
      border-radius: 12px;
      margin-bottom: 24px;
      font-weight: 600;
      transition: var(--transition);
      animation: slideIn 0.5s ease-out;
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      gap: 12px;
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
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
      border-left: 4px solid var(--success);
    }

    .flash-error {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
      color: var(--danger);
      border-left: 4px solid var(--danger);
    }

    /* Section Header */
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }

    .section-title {
      font-size: 22px;
      font-weight: 700;
      position: relative;
      padding-left: 16px;
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

    /* Welcome message with date */
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

    /* Text styles */
    .text-muted {
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 500;
    }

    .order-type-badge {
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(67, 97, 238, 0.05) 100%);
      color: var(--primary);
      border: 1px solid rgba(67, 97, 238, 0.2);
    }

    .customer-email {
      font-size: 12px;
      color: var(--text-muted);
      display: block;
      margin-top: 4px;
      font-weight: 500;
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

      /* Reset fixed widths on mobile for better responsiveness */
      .orders-table {
        table-layout: auto;
      }
      
      .orders-table th:nth-child(1),
      .orders-table td:nth-child(1),
      .orders-table th:nth-child(2),
      .orders-table td:nth-child(2),
      .orders-table th:nth-child(3),
      .orders-table td:nth-child(3),
      .orders-table th:nth-child(4),
      .orders-table td:nth-child(4),
      .orders-table th:nth-child(5),
      .orders-table td:nth-child(5),
      .orders-table th:nth-child(6),
      .orders-table td:nth-child(6),
      .orders-table th:nth-child(7),
      .orders-table td:nth-child(7),
      .orders-table th:nth-child(8),
      .orders-table td:nth-child(8) {
        width: auto;
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
      
      .orders-table {
        display: block;
        overflow-x: auto;
      }
      
      .action-cell {
        flex-direction: column;
        gap: 6px;
      }
      
      .action-btn {
        width: 100%;
        justify-content: center;
      }
    }

    @media (max-width: 480px) {
      .orders-table-container {
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

    /* Checkbox styling */
    input[type="checkbox"] {
      width: 18px;
      height: 18px;
      border-radius: 4px;
      border: 2px solid var(--border);
      background: var(--card-bg);
      cursor: pointer;
      transition: var(--transition);
    }

    input[type="checkbox"]:checked {
      background: var(--primary);
      border-color: var(--primary);
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
<body class="light-mode">
  <!-- Mobile Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="container">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
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
        <a href="physical_orders.php" class="menu-item">
          <i class="fas fa-store"></i>
          <span class="menu-text">Physical Orders</span>
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
          <h1>Purchase Orders</h1>
          <p>Track and manage customer orders</p>
        </div>
        <div class="header-actions">
          <div class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
          </div>
          <div class="notification-wrapper">
            <div class="notification-btn">
              <i class="fas fa-bell"></i>
            </div>
            <span class="notification-badge"><?php echo $pendingCount; ?></span>
          </div>
          <label class="theme-toggle" for="theme-toggle">
            <i class="fas fa-moon"></i>
          </label>
          <input type="checkbox" id="theme-toggle" style="display: none;">
          <div class="user-menu">
            <i class="fas fa-user"></i>
          </div>
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
          <i class="fas fa-<?php echo $_SESSION['flash']['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- Debug Info (remove in production) -->
      <?php if(empty($orders)): ?>
        <div class="flash-message flash-error">
          <i class="fas fa-exclamation-triangle"></i>
          <strong>Debug Info:</strong> No orders found in database. 
          <?php 
          $test_query = $conn->query("SELECT COUNT(*) as total FROM orders");
          if ($test_query) {
              $count = $test_query->fetch_assoc()['total'];
              echo "Total orders in database: " . $count;
          } else {
              echo "Cannot connect to orders table: " . $conn->error;
          }
          ?>
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
          <div class="stat-change positive">
            <i class="fas fa-chart-line"></i> All order types
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
          <div class="stat-change negative">
            <i class="fas fa-exclamation-circle"></i> Needs attention
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
          <div class="stat-change positive">
            <i class="fas fa-arrow-up"></i> Ready for processing
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
          <div class="stat-change positive">
            <i class="fas fa-check"></i> Completed orders
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
              <input type="text" id="searchInput" placeholder="Search orders by customer, product, or ID...">
            </div>
            <div class="action-buttons">
              <button type="submit" name="delete_orders" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete selected orders? This action cannot be undone.');">
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
              <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                  <?php
                  $itemsList = [];
                  $total = 0;

                  // Get order items with error handling
                  $itemsRes = $conn->prepare("SELECT oi.quantity, s.name, s.price 
                                              FROM order_items oi
                                              JOIN stock s ON s.id = oi.product_id
                                              WHERE oi.order_id = ?");
                  if ($itemsRes) {
                      $itemsRes->bind_param("i", $order['id']);
                      if ($itemsRes->execute()) {
                          $itemsRes->bind_result($qty, $name, $price);
                          while ($itemsRes->fetch()) {
                              $itemsList[] = "{$qty}x {$name}";
                              $total += $qty * $price;
                          }
                      }
                      $itemsRes->close();
                  }
                  ?>
                  <tr class="order-row" data-status="<?= $order['status']; ?>">
                    <td class="checkbox-cell">
                      <input type="checkbox" name="order_ids[]" value="<?= $order['id']; ?>">
                    </td>
                    <td>
                      <strong>#<?= $order['id']; ?></strong>
                      <?php if (!empty($order['order_number'])): ?>
                        <br><small class="text-muted">Ref: <?= $order['order_number']; ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight: 600;"><?= htmlspecialchars($order['display_name']); ?></div>
                      <?php if (!empty($order['customer_email'])): ?>
                        <small class="customer-email"><?= htmlspecialchars($order['customer_email']); ?></small>
                      <?php endif; ?>
                      <div style="margin-top: 4px;">
                        <?php if ($order['order_type'] == 'physical'): ?>
                          <span class="order-type-badge">Physical Store</span>
                        <?php else: ?>
                          <span class="order-type-badge" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%); color: var(--success); border-color: rgba(16, 185, 129, 0.2);">Website</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div style="max-width: 200px;">
                        <?= !empty($itemsList) ? implode("<br>", array_slice($itemsList, 0, 2)) : "No items"; ?>
                        <?php if (count($itemsList) > 2): ?>
                          <br><small class="text-muted">+<?= count($itemsList) - 2; ?> more items</small>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td style="font-weight: 700; color: var(--primary);">₱<?= number_format($total, 2); ?></td>
                    <td>
                      <span class="status-badge status-<?= strtolower($order['status']); ?>">
                        <i class="fas fa-<?php 
                          switch($order['status']) {
                            case 'Pending': echo 'clock'; break;
                            case 'Confirmed': echo 'check-circle'; break;
                            case 'Received': echo 'truck'; break;
                            case 'Cancelled': echo 'times-circle'; break;
                            default: echo 'circle';
                          }
                        ?>"></i>
                        <?= $order['status']; ?>
                      </span>
                    </td>
                    <td>
                      <div style="font-weight: 600;"><?= date('M j, Y', strtotime($order['created_at'])); ?></div>
                      <small class="text-muted"><?= date('g:i A', strtotime($order['created_at'])); ?></small>
                    </td>
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
                        <span style="color: var(--success); font-weight: 600;">
                          <i class="fas fa-check-circle"></i> Received
                        </span>
                      <?php elseif ($order['status'] === 'Cancelled'): ?>
                        <span style="color: var(--danger); font-weight: 600;">
                          <i class="fas fa-times-circle"></i> Cancelled
                        </span>
                        <?php if (!empty($order['cancellation_note'])): ?>
                          <br><small class="text-muted"><?= htmlspecialchars($order['cancellation_note']); ?></small>
                        <?php endif; ?>
                      <?php elseif ($order['status'] === 'Completed'): ?>
                        <span style="color: var(--success); font-weight: 600;">
                          <i class="fas fa-check-circle"></i> Completed
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" style="text-align: center; padding: 40px;">
                    <i class="fas fa-inbox" style="font-size: 48px; color: var(--text-muted); margin-bottom: 15px; display: block;"></i>
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No orders found</div>
                    <div style="color: var(--text-muted);">Orders created through checkout will appear here.</div>
                    <?php if(isset($_SESSION['user_id'])): ?>
                      <div style="margin-top: 15px;">
                        <a href="physical_orders.php" class="btn btn-primary" style="text-decoration: none;">
                          <i class="fas fa-plus"></i> Create New Order
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
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
    // Mobile sidebar toggle
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

    // Enhanced Theme Toggle with Smooth Transitions
    const themeToggle = document.getElementById('theme-toggle');

    // Apply theme transition to specific elements only (better performance)
    function applyThemeTransition() {
      const transitionElements = document.querySelectorAll('body, .stat-card, .orders-table-container, .btn, .action-btn, .orders-table, .orders-table th, .orders-table td');
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
          document.body.classList.remove('light-mode');
          document.body.classList.add('dark-mode');
          if (themeToggle) themeToggle.checked = true;
        } else {
          document.body.classList.remove('dark-mode');
          document.body.classList.add('light-mode');
          if (themeToggle) themeToggle.checked = false;
        }
      }, 100);

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
        if (confirm('Confirm order #' + orderId + '?\n\nThis will push website orders to sales tracking.')) {
            document.getElementById('formAction').value = 'confirm_order';
            document.getElementById('formOrderId').value = orderId;
            document.getElementById('actionForm').submit();
        }
    }

    function receiveOrder(orderId) {
        if (confirm('Mark order #' + orderId + ' as received?\n\nThis will update inventory and sales records.')) {
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