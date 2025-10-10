<?php
session_start();
require 'db.php';

/* ---------------- FLASH MESSAGE HELPER ---------------- */
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}

/* ---------------- CREATE PHYSICAL ORDER ---------------- */
if (isset($_POST['create_physical_order'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $payment_method = $_POST['payment_method'];
    $created_by = $_SESSION['user_name'] ?? 'Staff';

    // Generate shorter order number for physical orders
    $order_number = 'PO-' . date('YmdHi') . rand(10, 99); // Shorter format

    // Calculate total from items
    $total_amount = 0;
    $total_quantity = 0;
    $items = [];

    foreach ($_POST['items'] as $index => $item) {
        if (!empty($item['product']) && !empty($item['quantity']) && $item['quantity'] > 0) {
            $product = $item['product'];
            $quantity = intval($item['quantity']);
            $total_quantity += $quantity;
            
            // Get product price and stock
            $stmt = $conn->prepare("SELECT id, price, qty FROM stock WHERE name = ?");
            if ($stmt === false) {
                setFlash("Database error: " . $conn->error, 'error');
                header("Location: physical_orders.php");
                exit;
            }
            $stmt->bind_param("s", $product);
            $stmt->execute();
            $stmt->bind_result($product_id, $unit_price, $stock_qty);
            $stmt->fetch();
            $stmt->close();

            if ($unit_price && $stock_qty >= $quantity) {
                $subtotal = $unit_price * $quantity;
                $total_amount += $subtotal;
                
                $items[] = [
                    'product_id' => $product_id,
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'subtotal' => $subtotal
                ];
            } else {
                setFlash("Insufficient stock for $product or product not found. Available: $stock_qty, Requested: $quantity", 'error');
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
        // Get the current user's ID from session, or use a default value
        $user_id = $_SESSION['user_id'] ?? 1; // Default to user ID 1 if not set
        $role = $_SESSION['user_role'] ?? 'staff';
        
        // Create physical order - include user_id and role
        $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, quantity, order_type, payment_method, status, created_by, user_id, role) VALUES (?, ?, ?, ?, 'physical', ?, 'Completed', ?, ?, ?)");
        if ($stmt === false) {
            throw new Exception("Failed to prepare order statement: " . $conn->error);
        }
        
        // Count the parameters: 8 parameters total
        $stmt->bind_param("sssisiss", $order_number, $customer_name, $customer_phone, $total_quantity, $payment_method, $created_by, $user_id, $role);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create order: " . $stmt->error);
        }
        
        $order_id = $stmt->insert_id;
        $stmt->close();

        // Check if order_items has unit_price and subtotal columns
        $check_columns = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'");
        $has_unit_price = $check_columns->num_rows > 0;
        
        // Add order items to order_items table
        foreach ($items as $item) {
            if ($has_unit_price) {
                // If table has unit_price and subtotal columns
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                if ($stmt === false) {
                    throw new Exception("Failed to prepare order_items statement: " . $conn->error);
                }
                $stmt->bind_param("iiidd", $order_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);
            } else {
                // If table only has basic columns
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
                if ($stmt === false) {
                    throw new Exception("Failed to prepare order_items statement: " . $conn->error);
                }
                $stmt->bind_param("iii", $order_id, $item['product_id'], $item['quantity']);
            }
            
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

            // Add to sales table for reporting
            $check_column = $conn->query("SHOW COLUMNS FROM sales LIKE 'order_id'");
            if ($check_column->num_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_id, order_number, customer_name, payment_method) VALUES (NOW(), ?, ?, ?, 'physical', ?, ?, ?, ?)");
                if ($stmt === false) {
                    throw new Exception("Failed to prepare sales statement: " . $conn->error);
                }
                $stmt->bind_param("sidiiss", $item['product'], $item['quantity'], $item['subtotal'], $order_id, $order_number, $customer_name, $payment_method);
            } else {
                $check_column = $conn->query("SHOW COLUMNS FROM sales LIKE 'order_number'");
                if ($check_column->num_rows > 0) {
                    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_number, customer_name, payment_method) VALUES (NOW(), ?, ?, ?, 'physical', ?, ?, ?)");
                    if ($stmt === false) {
                        throw new Exception("Failed to prepare sales statement: " . $conn->error);
                    }
                    $stmt->bind_param("sidisss", $item['product'], $item['quantity'], $item['subtotal'], $order_number, $customer_name, $payment_method);
                } else {
                    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, customer_name, payment_method) VALUES (NOW(), ?, ?, ?, 'physical', ?, ?)");
                    if ($stmt === false) {
                        throw new Exception("Failed to prepare sales statement: " . $conn->error);
                    }
                    $stmt->bind_param("sidss", $item['product'], $item['quantity'], $item['subtotal'], $customer_name, $payment_method);
                }
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to record sale for " . $item['product'] . ": " . $stmt->error);
            }
            $stmt->close();
        }

        // Update summary table if it exists
        $check_summary = $conn->query("SHOW TABLES LIKE 'summary'");
        if ($check_summary->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE summary SET total_sales = total_sales + 1, total_revenue = total_revenue + ? WHERE id = 1");
            if ($stmt === false) {
                throw new Exception("Failed to prepare summary statement: " . $conn->error);
            }
            $stmt->bind_param("d", $total_amount);
            if (!$stmt->execute()) {
                error_log("Warning: Failed to update summary table: " . $stmt->error);
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
// Calculate statistics from orders
$stats_query = "SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed
FROM orders WHERE order_type = 'physical'";

$stats_result = $conn->query($stats_query);
$physical_stats = $stats_result ? $stats_result->fetch_assoc() : ['total_orders' => 0, 'pending' => 0, 'completed' => 0];

// Calculate total revenue - check if we have the subtotal column in order_items
$check_subtotal = $conn->query("SHOW COLUMNS FROM order_items LIKE 'subtotal'");
if ($check_subtotal->num_rows > 0) {
    $revenue_query = "SELECT SUM(oi.subtotal) as total_revenue 
                     FROM order_items oi 
                     JOIN orders o ON oi.order_id = o.id 
                     WHERE o.order_type = 'physical'";
} else {
    // If no subtotal column, calculate from stock prices
    $revenue_query = "SELECT SUM(s.price * oi.quantity) as total_revenue 
                     FROM order_items oi 
                     JOIN orders o ON oi.order_id = o.id 
                     JOIN stock s ON oi.product_id = s.id 
                     WHERE o.order_type = 'physical'";
}

$revenue_result = $conn->query($revenue_query);
$revenue_data = $revenue_result ? $revenue_result->fetch_assoc() : ['total_revenue' => 0];
$physical_stats['total_revenue'] = $revenue_data['total_revenue'] ?? 0;

// Get physical orders for display
$orders = [];
$orders_query = "SELECT o.* FROM orders o WHERE o.order_type = 'physical' ORDER BY o.created_at DESC LIMIT 50";

$orders_result = $conn->query($orders_query);
if ($orders_result && $orders_result->num_rows > 0) {
    while ($row = $orders_result->fetch_assoc()) {
        // Get items and calculate total for each order
        $order_id = $row['id'];
        
        // Check if we have subtotal column
        $check_subtotal = $conn->query("SHOW COLUMNS FROM order_items LIKE 'subtotal'");
        if ($check_subtotal->num_rows > 0) {
            $items_query = $conn->query("
                SELECT oi.quantity, s.name, oi.subtotal
                FROM order_items oi 
                JOIN stock s ON oi.product_id = s.id 
                WHERE oi.order_id = $order_id
            ");
        } else {
            $items_query = $conn->query("
                SELECT oi.quantity, s.name, (s.price * oi.quantity) as subtotal
                FROM order_items oi 
                JOIN stock s ON oi.product_id = s.id 
                WHERE oi.order_id = $order_id
            ");
        }
        
        $items_list = [];
        $order_total = 0;
        if ($items_query && $items_query->num_rows > 0) {
            while ($item = $items_query->fetch_assoc()) {
                $items_list[] = $item['quantity'] . 'x ' . $item['name'];
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Process Physical Orders - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* Your existing CSS styles remain exactly the same */
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

    /* Order Form Styles */
    .order-form-container {
        background: var(--card-bg);
        border-radius: var(--card-radius);
        padding: 24px;
        box-shadow: 0 4px 12px var(--shadow);
        margin-bottom: 30px;
        transition: var(--transition);
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--text);
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid var(--border);
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

    .order-items {
        margin: 20px 0;
    }

    .item-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr auto;
        gap: 10px;
        align-items: end;
        margin-bottom: 15px;
        padding: 15px;
        background: rgba(0, 0, 0, 0.02);
        border-radius: 6px;
        transition: var(--transition);
    }

    .dark-mode .item-row {
        background: rgba(255, 255, 255, 0.02);
    }

    .item-row:hover {
        background: rgba(67, 97, 238, 0.05);
    }

    .payment-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        background: rgba(67, 97, 238, 0.1);
        color: var(--primary);
    }

    .btn-sm {
        padding: 8px 12px;
        font-size: 12px;
    }

    /* Keep all your existing CSS styles... */
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
      overflow-x: auto;
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
      min-width: 1000px;
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

    .status-completed {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
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
      .form-grid {
        grid-template-columns: 1fr;
      }
      .item-row {
        grid-template-columns: 1fr;
        gap: 10px;
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

    .total-display {
        background: var(--primary);
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        text-align: center;
        font-size: 18px;
        font-weight: 600;
        margin-top: 20px;
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
        <a href="orders.php" class="menu-item">
          <i class="fas fa-clipboard-list"></i>
          <span class="menu-text">Purchase Orders</span>
        </a>
        <a href="stock.php" class="menu-item">
          <i class="fas fa-boxes"></i>
          <span class="menu-text">Inventory</span>
        </a>
        <a href="physical_orders.php" class="menu-item active">
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
          <h1>Process Physical Orders</h1>
          <p>Create and manage walk-in customer orders</p>
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
              <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name" required>
            </div>

            <div class="form-group">
              <label class="form-label">Phone Number</label>
              <input type="tel" name="customer_phone" class="form-control" placeholder="Enter phone number">
            </div>

            <div class="form-group">
              <label class="form-label">Payment Method *</label>
              <select name="payment_method" class="form-control" required>
                <option value="cash">Cash</option>
                <option value="card">Credit/Debit Card</option>
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank Transfer</option>
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
                  <td>
                    <?php
                    echo htmlspecialchars($order['items_list']);
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
                <td colspan="8" style="text-align: center;">
                  No physical orders found.
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
                ${products.map(p => `<option value="${p.name}" data-price="${p.price}" data-stock="${p.qty}">${p.name} - ₱${p.price} (Stock: ${p.qty})</option>`).join('')}
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Quantity *</label>
            <input type="number" name="items[${itemCount}][quantity]" class="form-control quantity-input" min="1" max="1000" value="1" onchange="updateSubtotal(${itemCount})" oninput="updateSubtotal(${itemCount})" required>
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
            <button type="button" class="btn btn-danger btn-sm" onclick="removeItemRow(this)" ${itemCount === 1 ? 'disabled' : ''}>
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(itemRow);
    updateTotal();
}

function removeItemRow(button) {
    if (document.querySelectorAll('.item-row').length > 1) {
        button.closest('.item-row').remove();
        updateTotal();
    }
}

function updatePrice(select, index) {
    const selectedOption = select.selectedOptions[0];
    const price = selectedOption?.dataset.price || 0;
    const stock = selectedOption?.dataset.stock || 0;
    
    const row = select.closest('.item-row');
    const quantityInput = row.querySelector('.quantity-input');
    
    // Update max quantity based on stock
    quantityInput.max = stock;
    
    // Update price display
    row.querySelector('.price-display').value = `₱${parseFloat(price).toFixed(2)}`;
    
    // Update quantity if it exceeds stock
    if (parseInt(quantityInput.value) > parseInt(stock)) {
        quantityInput.value = stock;
    }
    
    updateSubtotal(index);
}

function updateSubtotal(index) {
    const row = document.querySelector(`[name="items[${index}][quantity]"]`).closest('.item-row');
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
    
    // Theme toggle functionality
    const themeToggle = document.getElementById('theme-toggle');
    const savedTheme = localStorage.getItem('theme');
    
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.checked = true;
    } else {
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
});

// Form validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    let hasValidItems = false;
    
    document.querySelectorAll('.item-row').forEach(row => {
        const product = row.querySelector('.product-select').value;
        const quantity = row.querySelector('.quantity-input').value;
        
        if (product && quantity > 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        e.preventDefault();
        alert('Please add at least one item to the order.');
        return;
    }
});
  </script>
</body>
</html>