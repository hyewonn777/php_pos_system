<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use unique session name for admin
session_name('admin_session');
session_start();
require 'db.php';

/* ----------------- SESSION & FLASH MESSAGE ----------------- */
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}

// Enhanced session debugging (remove in production)
$username = 'Admin'; // Default fallback

// Check admin session variables for username
if (isset($_SESSION['admin_username']) && !empty($_SESSION['admin_username'])) {
    $username = $_SESSION['admin_username'];
} elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
    $username = $_SESSION['username'];
} elseif (isset($_SESSION['admin_id'])) {
    // If we have admin_id but no username, fetch from database
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT username, name FROM users WHERE id = ? AND role = 'admin'");
    if ($stmt) {
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $user_data = $result->fetch_assoc();
            if (!empty($user_data['username'])) {
                $username = $user_data['username'];
                $_SESSION['admin_username'] = $user_data['username']; // Set it for future use
            } elseif (!empty($user_data['name'])) {
                $username = $user_data['name'];
                $_SESSION['admin_username'] = $user_data['name'];
            }
        }
        $stmt->close();
    }
}

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

// Get current time for greeting
$hour = date('H');
if ($hour < 11) {
    $greeting = "Good morning";
} elseif ($hour < 13) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}

// Function to get live counts for auto-refresh
function getLiveCounts($conn) {
    $pendingOrders = getCount($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE status='Pending'", "cnt");
    $pendingAppointments = getCount($conn, "SELECT COUNT(*) AS cnt FROM appointments WHERE status='Pending'", "cnt");
    $todaySales = getCount($conn, "SELECT IFNULL(SUM(total), 0) as total FROM sales WHERE status='paid' AND DATE(created_at) = CURDATE()", "total");
    
    return [
        'pendingOrders' => $pendingOrders,
        'pendingAppointments' => $pendingAppointments,
        'todaySales' => $todaySales
    ];
}

function getCount($conn, $query, $field) {
    $res = $conn->query($query);
    if ($res && $row = $res->fetch_assoc()) {
        return $row[$field];
    }
    return 0;
}

/* ----------------- SUMMARY TABLE ----------------- */
$check = $conn->query("SELECT id FROM summary WHERE id=1");
if ($check->num_rows === 0) {
    $conn->query("INSERT INTO summary (id, total_sales, total_revenue) VALUES (1, 0, 0)");
}

/* ----------------- CREATE SALE ----------------- */
if (isset($_POST['add_sale'])) {
    $sale_date = $_POST['sale_date'];
    $product   = $_POST['product'];
    $quantity  = intval($_POST['quantity']);
    $total     = floatval($_POST['total']);

    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type) VALUES (?, ?, ?, ?, 'online')");
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
    setFlash("Sale added successfully!");
    header("Location: sales.php");
    exit;
}

/* ----------------- BATCH UPDATE SALES ----------------- */
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
    setFlash("Selected sales updated successfully!");
    header("Location: sales.php");
    exit;
}

/* ----------------- BATCH DELETE SALES ----------------- */
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
    setFlash("Selected sales deleted successfully!");
    header("Location: sales.php");
    exit;
}

/* ----------------- DELETE INDIVIDUAL SALE ----------------- */
if (isset($_POST['delete_sale']) && isset($_POST['sale_id'])) {
    $sale_id = intval($_POST['sale_id']);

    $conn->begin_transaction();
    try {
        // Get the sale details to update stock
        $stmt = $conn->prepare("SELECT product, quantity FROM sales WHERE id=?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $stmt->bind_result($product, $qty);
        $stmt->fetch();
        $stmt->close();

        // Update stock: add back the quantity
        $stmt = $conn->prepare("UPDATE stock SET qty = qty + ? WHERE name=?");
        $stmt->bind_param("is", $qty, $product);
        $stmt->execute();
        $stmt->close();

        // Delete the sale
        $stmt = $conn->prepare("DELETE FROM sales WHERE id=?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        setFlash("Sale deleted successfully!");
    } catch (Exception $e) {
        $conn->rollback();
        setFlash("Error deleting sale: " . $e->getMessage(), 'error');
    }
    header("Location: sales.php");
    exit;
}

/* ----------------- UPDATE INDIVIDUAL SALE ----------------- */
if (isset($_POST['update_sale']) && isset($_POST['sale_id'])) {
    $sale_id = intval($_POST['sale_id']);
    $new_qty = intval($_POST['quantity'] ?? 0);
    $new_total = floatval($_POST['total'] ?? 0);

    $stmt = $conn->prepare("SELECT product, quantity FROM sales WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $stmt->bind_result($product, $old_qty);
        $stmt->fetch();
        $stmt->close();

        $qty_diff = $new_qty - $old_qty;

        $stmt = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE name=?");
        if ($stmt) {
            $stmt->bind_param("is", $qty_diff, $product);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE sales SET quantity=?, total=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("idi", $new_qty, $new_total, $sale_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    setFlash("Sale updated successfully!");
    header("Location: sales.php");
    exit;
}

/* ----------------- ORDER ACTIONS ----------------- */
if (isset($_POST['action'])) {
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if ($_POST['action'] == 'confirm_order' && $order_id) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT order_number, customer_name, order_type FROM orders WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->bind_result($order_number, $customer_name, $order_type);
            $stmt->fetch();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE orders SET status='Confirmed' WHERE id=?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();

            if ($order_type == 'website' || $order_type == 'online') {
                $items_stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $items_stmt->bind_param("i", $order_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $product_stmt = $conn->prepare("SELECT name, price FROM stock WHERE id = ?");
                    $product_stmt->bind_param("i", $item['product_id']);
                    $product_stmt->execute();
                    $product_stmt->bind_result($product_name, $price);
                    $product_stmt->fetch();
                    $product_stmt->close();
                    
                    $subtotal = $price * $item['quantity'];
                    
                    $sales_stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_number) VALUES (NOW(), ?, ?, ?, ?, ?)");
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
        }
    }
    elseif ($_POST['action'] == 'receive_order' && $order_id) {
        $conn->begin_transaction();
        try {
            $order_stmt = $conn->prepare("SELECT order_number, order_type FROM orders WHERE id = ?");
            $order_stmt->bind_param("i", $order_id);
            $order_stmt->execute();
            $order_stmt->bind_result($order_number, $order_type);
            $order_stmt->fetch();
            $order_stmt->close();

            $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($items as $item) {
                $product_id = $item['product_id'];
                $qty = $item['quantity'];

                $stmt = $conn->prepare("UPDATE stock SET qty = GREATEST(qty - ?, 0) WHERE id=?");
                $stmt->bind_param("ii", $qty, $product_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT name, price FROM stock WHERE id=?");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $stmt->bind_result($product_name, $price);
                $stmt->fetch();
                $stmt->close();

                $total = $price * $qty;
                
                $check_column = $conn->query("SHOW COLUMNS FROM sales LIKE 'order_number'");
                if ($check_column->num_rows > 0) {
                    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, order_number, status) VALUES (NOW(), ?, ?, ?, ?, ?, 'received')");
                    $stmt->bind_param("sidds", $product_name, $qty, $total, $order_type, $order_number);
                } else {
                    $stmt = $conn->prepare("INSERT INTO sales (sale_date, product, quantity, total, order_type, status) VALUES (NOW(), ?, ?, ?, ?, 'received')");
                    $stmt->bind_param("sidd", $product_name, $qty, $total, $order_type);
                }
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("UPDATE orders SET status='Received' WHERE id=?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            setFlash("Order #$order_id marked as received and inventory updated!");
        } catch (Exception $e) {
            $conn->rollback();
            setFlash("Error receiving order: " . $e->getMessage(), 'error');
        }
    }
    header("Location: sales.php");
    exit;
}

/* ----------------- DELETE ORDERS ----------------- */
if (isset($_POST['delete_orders']) && !empty($_POST['order_ids'])) {
    $order_ids = $_POST['order_ids'];
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));

    $conn->begin_transaction();
    try {
        $delItems = $conn->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
        $delItems->bind_param($types, ...$order_ids);
        $delItems->execute();
        $delItems->close();

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
    header("Location: sales.php");
    exit;
}

/* ----------------- UPDATE ORDER ----------------- */
if (isset($_POST['update_order']) && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_email = $_POST['customer_email'] ?? '';
    $status = $_POST['status'] ?? 'Pending';

    // Check if order can be edited (only Pending or Confirmed status)
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->bind_result($current_status);
    $stmt->fetch();
    $stmt->close();

    if ($current_status === 'Received' || $current_status === 'Completed') {
        setFlash("Cannot edit order #$order_id - it has already been received/completed!", 'error');
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE orders SET customer_name = ?, status = ? WHERE id = ?");
            $stmt->bind_param("ssi", $customer_name, $status, $order_id);
            $stmt->execute();
            $stmt->close();

            // Update order items if provided
            if (isset($_POST['order_items'])) {
                foreach ($_POST['order_items'] as $item_id => $item_data) {
                    $quantity = intval($item_data['quantity'] ?? 0);
                    if ($quantity > 0) {
                        $stmt = $conn->prepare("UPDATE order_items SET quantity = ? WHERE id = ? AND order_id = ?");
                        $stmt->bind_param("iii", $quantity, $item_id, $order_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $conn->commit();
            setFlash("Order #$order_id updated successfully!");
        } catch (Exception $e) {
            $conn->rollback();
            setFlash("Error updating order: " . $e->getMessage(), 'error');
        }
    }
    header("Location: sales.php");
    exit;
}

/* ----------------- SALES DATA ----------------- */
// Modified query to get grouped sales data
$salesQuery = "
    SELECT s.id, s.product, s.quantity, s.total, s.sale_date, s.order_type, 
           s.customer_name, s.customer_phone, s.payment_method, s.order_id,
           COALESCE(NULL, CONCAT('SALE-', s.id, '-', DATE_FORMAT(s.sale_date, '%Y%m%d'))) as reference_id,
           'completed' as order_status
    FROM sales s 
    ORDER BY s.customer_name, s.sale_date DESC
";

$result = $conn->query($salesQuery);

// Check if query was successful
if ($result === false) {
    // If query fails, try an even simpler version
    error_log("Sales query failed: " . $conn->error);
    
    $salesQuery = "SELECT id, product, quantity, total, sale_date, order_type, customer_name, customer_phone, payment_method FROM sales ORDER BY customer_name, sale_date DESC";
    $result = $conn->query($salesQuery);
    
    if ($result === false) {
        // If even the simple query fails, log error and set empty array
        error_log("Simple sales query also failed: " . $conn->error);
        $sales = [];
    } else {
        $sales = [];
        while ($row = $result->fetch_assoc()) {
            // Add missing fields with default values
            $row['reference_id'] = 'SALE-' . $row['id'] . '-' . date('Ymd', strtotime($row['sale_date']));
            $row['order_status'] = 'completed';
            $row['order_id'] = null;
            $sales[] = $row;
        }
    }
} else {
    $sales = [];
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
}

// Group sales by customer and date for better reporting
$groupedSales = [];
foreach ($sales as $sale) {
    $dateKey = date('Y-m-d', strtotime($sale['sale_date']));
    $customerKey = $sale['customer_name'] ?: 'Unknown Customer';
    $key = $customerKey . '_' . $dateKey;
    
    if (!isset($groupedSales[$key])) {
        $groupedSales[$key] = [
            'customer_name' => $customerKey,
            'sale_date' => $sale['sale_date'],
            'order_type' => $sale['order_type'],
            'customer_phone' => $sale['customer_phone'],
            'payment_method' => $sale['payment_method'],
            'items' => [],
            'total_quantity' => 0,
            'total_amount' => 0
        ];
    }
    
    $groupedSales[$key]['items'][] = [
        'product' => $sale['product'],
        'quantity' => $sale['quantity'],
        'total' => $sale['total'],
        'id' => $sale['id']
    ];
    
    $groupedSales[$key]['total_quantity'] += $sale['quantity'];
    $groupedSales[$key]['total_amount'] += $sale['total'];
}

// Initialize variables to prevent undefined variable errors
$totalSales = 0;
$onlineSales = 0;
$physicalSales = 0;
$skuMargins = [];

// Process sales data only if we have sales
foreach ($sales as $row) {
    $totalSales += floatval($row['total']);
    
    if (isset($row['order_type']) && $row['order_type'] === 'physical') {
        $physicalSales += floatval($row['total']);
    } else {
        $onlineSales += floatval($row['total']);
    }

    $sku = $row['product'];
    $profit = $row['total'] * 0.1;
    if (!isset($skuMargins[$sku])) $skuMargins[$sku] = ['profit'=>0,'discount'=>0];
    $skuMargins[$sku]['profit'] += $profit;
}

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

    $data['profit_after_discount'] = $data['profit'] * (1 - $data['discount']/100) * 0.8;

    if ($data['profit_after_discount'] >= 5000) $data['level'] = "High";
    elseif ($data['profit_after_discount'] >= 1000) $data['level'] = "Medium";
    else $data['level'] = "Low";
}
unset($data);

$grossSales = $totalSales;
$globalDiscountPercent = 10;
$discountAmount = $grossSales * $globalDiscountPercent / 100;
$netSales = $grossSales - $discountAmount;
$totalProfitAfterDiscount = array_sum(array_column($skuMargins,'profit_after_discount'));

/* ----------------- ORDERS DATA ----------------- */
$pendingCount = 0;
$confirmedCount = 0;
$receivedCount = 0;
$cancelledCount = 0;

// Safely get order counts
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

$orders = [];
$orders_query = $conn->query("
    SELECT o.*, 
           COALESCE(o.customer_name, 'Unknown') as display_name,
           COALESCE(o.customer_email, 'No email') as customer_email,
           COALESCE(o.order_number, CONCAT('ORD-', o.id, '-', DATE_FORMAT(o.created_at, '%Y%m%d'))) as reference_id
    FROM orders o 
    ORDER BY o.created_at DESC
");

if ($orders_query === false) {
    // Fallback if the main query fails
    $orders_query = $conn->query("SELECT *, CONCAT('ORD-', id, '-', DATE_FORMAT(created_at, '%Y%m%d')) as reference_id, COALESCE(customer_name, 'Unknown') as display_name FROM orders ORDER BY created_at DESC");
}

if ($orders_query && $orders_query->num_rows > 0) {
    $orders = $orders_query->fetch_all(MYSQLI_ASSOC);
}

// Get order items for editing
$order_items = [];
foreach ($orders as $order) {
    $itemsRes = $conn->prepare("SELECT oi.id, oi.product_id, oi.quantity, s.name, s.price 
                                FROM order_items oi
                                JOIN stock s ON s.id = oi.product_id
                                WHERE oi.order_id = ?");
    if ($itemsRes) {
        $itemsRes->bind_param("i", $order['id']);
        if ($itemsRes->execute()) {
            $itemsResult = $itemsRes->get_result();
            $order_items[$order['id']] = $itemsResult->fetch_all(MYSQLI_ASSOC);
        }
        $itemsRes->close();
    }
}

/* ----------------- CHART DATA ----------------- */
$chartFilter = $_GET['chart_filter'] ?? 'month';
$orderTypeFilter = $_GET['order_type'] ?? 'all';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$monthlySales = [];
$chartLabels = [];

switch($chartFilter) {
    case 'day':
        $chartQuery = "SELECT DATE(sale_date) as period, SUM(total) as total FROM sales WHERE 1=1";
        break;
    case 'week':
        $chartQuery = "SELECT YEAR(sale_date) as year, WEEK(sale_date) as week, SUM(total) as total FROM sales WHERE 1=1";
        break;
    case 'month':
    default:
        $chartQuery = "SELECT YEAR(sale_date) as year, MONTH(sale_date) as month, SUM(total) as total FROM sales WHERE 1=1";
        break;
    case 'year':
        $chartQuery = "SELECT YEAR(sale_date) as year, SUM(total) as total FROM sales WHERE 1=1";
        break;
}

if ($orderTypeFilter !== 'all') {
    $chartQuery .= " AND order_type = '$orderTypeFilter'";
}
if ($startDate) $chartQuery .= " AND sale_date >= '$startDate'";
if ($endDate) $chartQuery .= " AND sale_date <= '$endDate 23:59:59'";

switch($chartFilter) {
    case 'day':
        $chartQuery .= " GROUP BY DATE(sale_date) ORDER BY period DESC LIMIT 30";
        $labelFormat = 'M j';
        break;
    case 'week':
        $chartQuery .= " GROUP BY YEAR(sale_date), WEEK(sale_date) ORDER BY year DESC, week DESC LIMIT 12";
        $labelFormat = 'Wk %d';
        break;
    case 'month':
        $chartQuery .= " GROUP BY YEAR(sale_date), MONTH(sale_date) ORDER BY year DESC, month DESC LIMIT 12";
        $labelFormat = 'M';
        break;
    case 'year':
        $chartQuery .= " GROUP BY YEAR(sale_date) ORDER BY year DESC LIMIT 5";
        $labelFormat = 'Y';
        break;
}

$result = $conn->query($chartQuery);
if ($result && $result->num_rows > 0) {
    $data = [];
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $data = array_reverse($data);
    
    foreach($data as $row) {
        if ($chartFilter == 'day') {
            $monthlySales[$row['period']] = $row['total'];
            $chartLabels[] = date($labelFormat, strtotime($row['period']));
        } elseif ($chartFilter == 'week') {
            $key = $row['year'] . '-' . $row['week'];
            $monthlySales[$key] = $row['total'];
            $chartLabels[] = sprintf($labelFormat, $row['week']);
        } elseif ($chartFilter == 'month') {
            $key = $row['year'] . '-' . $row['month'];
            $monthlySales[$key] = $row['total'];
            $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $chartLabels[] = $monthNames[$row['month']] . ' ' . $row['year'];
        } else {
            $monthlySales[$row['year']] = $row['total'];
            $chartLabels[] = $row['year'];
        }
    }
} else {
    $chartLabels = ['No Data'];
    $monthlySales = [0];
}

$monthlySalesData = array_values($monthlySales);

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
  <title>Sales & Orders - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* Toast Notification Styles from index.php */
    .toast-container {
        position: fixed;
        top: 100px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
    }

    .toast {
        background: var(--card-bg);
        border-radius: var(--card-radius);
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: var(--shadow);
        border-left: 6px solid;
        display: flex;
        align-items: flex-start;
        gap: 15px;
        transform: translateX(400px);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        max-width: 380px;
        position: relative;
        overflow: hidden;
    }

    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }

    .toast.hiding {
        transform: translateX(400px);
        opacity: 0;
    }

    .toast::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: currentColor;
        opacity: 0.3;
    }

    .toast-success {
        border-left-color: var(--success);
        color: var(--success);
    }

    .toast-error {
        border-left-color: var(--danger);
        color: var(--danger);
    }

    .toast-warning {
        border-left-color: var(--warning);
        color: var(--warning);
    }

    .toast-info {
        border-left-color: var(--primary);
        color: var(--primary);
    }

    .toast-icon {
        font-size: 1.5rem;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .toast-content {
        flex: 1;
        text-align: left;
    }

    .toast-title {
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 5px;
        color: var(--text);
    }

    .toast-message {
        color: var(--text);
        font-size: 0.95rem;
        line-height: 1.4;
    }

    .toast-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: var(--text-muted);
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: var(--transition);
        flex-shrink: 0;
    }

    .toast-close:hover {
        background: rgba(0, 0, 0, 0.1);
        color: var(--text);
    }

    .toast-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        background: currentColor;
        opacity: 0.5;
        width: 100%;
        transform: scaleX(1);
        transform-origin: left;
        transition: transform 5s linear;
    }

    .toast-progress.hiding {
        transform: scaleX(0);
    }

    /* Count update animation */
    .count-update {
        animation: countPulse 1s ease-in-out;
    }

    @keyframes countPulse {
        0% {
            transform: scale(1);
            color: var(--text);
        }
        50% {
            transform: scale(1.1);
            color: var(--primary);
        }
        100% {
            transform: scale(1);
            color: var(--text);
        }
    }

    /* Refresh button animation */
    .action-btn.refreshing .action-icon {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Last refresh indicator */
    .last-refresh {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: var(--card-bg);
        padding: 8px 12px;
        border-radius: 20px;
        font-size: 12px;
        color: var(--text-muted);
        box-shadow: var(--shadow);
        z-index: 1000;
    }

    /* Grouped sales rows */
    .grouped-sale-row {
        background: rgba(67, 97, 238, 0.05) !important;
        border-left: 4px solid var(--primary);
        font-weight: 600;
    }

    .grouped-sale-row:hover {
        background: rgba(67, 97, 238, 0.08) !important;
    }

    .grouped-items {
        background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .grouped-items {
        background: rgba(255, 255, 255, 0.03);
    }

    .grouped-item-row {
        border-left: 2px solid var(--border);
    }

    .group-toggle {
        cursor: pointer;
        transition: var(--transition);
    }

    .group-toggle:hover {
        color: var(--primary);
    }

    .group-indicator {
        display: inline-block;
        width: 16px;
        text-align: center;
        margin-right: 8px;
        transition: var(--transition);
    }

    .group-expanded .group-indicator {
        transform: rotate(90deg);
    }

    .grouped-details {
        display: none;
    }

    .group-expanded + .grouped-details {
        display: table-row-group;
    }

    /* Enhanced Action Buttons */
    .action-cell {
        display: flex;
        gap: 6px;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    .action-btn {
        padding: 6px 10px;
        font-size: 11px;
        min-width: 60px;
        justify-content: center;
        display: flex;
        align-items: center;
        gap: 4px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: var(--transition);
        font-weight: 600;
    }

    .action-btn.edit {
        background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        color: white;
    }

    .action-btn.edit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(245, 158, 11, 0.3);
    }

    .action-btn.delete {
        background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
        color: white;
    }

    .action-btn.delete:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(230, 57, 70, 0.3);
    }

    .action-btn.view {
        background: linear-gradient(135deg, var(--info) 0%, #3a7bd5 100%);
        color: white;
    }

    .action-btn.view:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(72, 149, 239, 0.3);
    }

    .action-btn.success {
        background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
        color: white;
    }

    .action-btn.success:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
    }

    /* Existing sales.php styles remain the same */
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

    /* Print Styles for Sales Report */
    @media print {
      body * {
        visibility: hidden;
      }
      #salesReportPrint, #salesReportPrint * {
        visibility: visible;
      }
      #salesReportPrint {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        color: black;
        padding: 20px;
        box-shadow: none;
      }
      .no-print {
        display: none !important;
      }
      .print-header {
        text-align: center;
        border-bottom: 2px solid #333;
        padding-bottom: 15px;
        margin-bottom: 20px;
      }
      .print-section {
        margin-bottom: 20px;
        page-break-inside: avoid;
      }
      .print-table {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0;
      }
      .print-table th, .print-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
      }
      .print-table th {
        background-color: #f5f5f5;
        font-weight: bold;
      }
      .print-totals {
        background-color: #f9f9f9;
        padding: 15px;
        border: 1px solid #ddd;
        margin-top: 20px;
      }
    }

    /* Enhanced Sales Report Modal */
    .sales-report-content {
      max-height: 70vh;
      overflow-y: auto;
      padding: 20px;
    }

    .report-header {
      text-align: center;
      border-bottom: 2px solid var(--primary);
      padding-bottom: 20px;
      margin-bottom: 25px;
    }

    .report-company {
      font-size: 24px;
      font-weight: 700;
      color: var(--primary);
      margin-bottom: 5px;
    }

    .report-title {
      font-size: 18px;
      color: var(--text);
      margin-bottom: 10px;
    }

    .report-meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 25px;
      padding: 15px;
      background: rgba(67, 97, 238, 0.05);
      border-radius: 10px;
    }

    .meta-item {
      display: flex;
      justify-content: space-between;
      padding: 5px 0;
    }

    .meta-label {
      font-weight: 600;
      color: var(--text-muted);
    }

    .meta-value {
      font-weight: 600;
    }

    .report-section {
      margin-bottom: 25px;
    }

    .section-title {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 15px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
      color: var(--primary);
    }

    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
    }

    .items-table th, .items-table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .items-table th {
      background: rgba(67, 97, 238, 0.1);
      font-weight: 600;
      color: var(--primary);
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin: 20px 0;
    }

    .summary-card {
      background: var(--card-bg);
      padding: 15px;
      border-radius: 8px;
      border-left: 4px solid var(--primary);
      box-shadow: var(--shadow);
    }

    .summary-card.success {
      border-left-color: var(--success);
    }

    .summary-card.warning {
      border-left-color: var(--warning);
    }

    .summary-card.info {
      border-left-color: var(--info);
    }

    .summary-value {
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .summary-label {
      font-size: 12px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .report-footer {
      margin-top: 30px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
      text-align: center;
      color: var(--text-muted);
      font-size: 12px;
    }

    .print-actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-top: 20px;
      padding: 15px;
      background: rgba(0, 0, 0, 0.02);
      border-radius: 8px;
    }

    .dark-mode .print-actions {
      background: rgba(255, 255, 255, 0.05);
    }

    /* Improved Customer Display */
    .customer-info {
        display: flex;
        flex-direction: column;
    }
    
    .customer-name {
        font-weight: 700;
        color: var(--text);
        margin-bottom: 2px;
    }
    
    .customer-details {
        font-size: 12px;
        color: var(--text-muted);
    }

    /* Improved Top Products Card */
    .top-products-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .top-product-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        background: rgba(0, 0, 0, 0.02);
        border-radius: 10px;
        transition: var(--transition);
    }
    
    .dark-mode .top-product-item {
        background: rgba(255, 255, 255, 0.05);
    }
    
    .top-product-item:hover {
        background: rgba(67, 97, 238, 0.05);
        transform: translateY(-2px);
    }
    
    .product-rank {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
    }
    
    .product-info {
        flex: 1;
    }
    
    .product-name {
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .product-stats {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: var(--text-muted);
    }
    
    .no-data {
        text-align: center;
        padding: 30px;
        color: var(--text-muted);
    }

    /* Fixed Order Type Badges - UPDATED */
    .order-type-physical {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning);
        border: 1px solid rgba(245, 158, 11, 0.2);
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }

    .order-type-website {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
        border: 1px solid rgba(16, 185, 129, 0.2);
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }

    .order-type-online {
        background: rgba(67, 97, 238, 0.1);
        color: var(--primary);
        border: 1px solid rgba(67, 97, 238, 0.2);
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }

    /* Tabs */
    .tabs {
      display: flex;
      margin-bottom: 30px;
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 8px;
      box-shadow: var(--shadow);
      border: 1px solid var(--border);
    }

    .tab {
      flex: 1;
      padding: 15px 20px;
      text-align: center;
      background: transparent;
      border: none;
      color: var(--text-muted);
      font-weight: 600;
      cursor: pointer;
      border-radius: 10px;
      transition: var(--transition);
    }

    .tab.active {
      background: var(--primary);
      color: white;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .tab:hover:not(.active) {
      background: rgba(67, 97, 238, 0.1);
      color: var(--primary);
    }

    .tab-content {
      display: none;
      animation: fadeIn 0.5s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .tab-content.active {
      display: block;
    }

    /* Flash Message */
    .flash-message {
      padding: 16px 24px;
      border-radius: 12px;
      margin-bottom: 24px;
      font-weight: 600;
      animation: slideIn 0.5s ease-out;
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
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

    /* Common Components */
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

    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 25px;
      transition: var(--transition);
      background: var(--bg);
    }

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

    .stat-card.success {
      border-left-color: var(--success);
    }

    .stat-card.info {
      border-left-color: var(--info);
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
    }

    .stat-card.success .stat-icon {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
    }

    .stat-card.info .stat-icon {
      background: linear-gradient(135deg, rgba(72, 149, 239, 0.1) 0%, rgba(72, 149, 239, 0.05) 100%);
      color: var(--info);
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

    /* Modified Content Grid - Sales Analytics takes more space */
    .content-grid {
      display: grid;
      grid-template-columns: 3fr 1fr;
      gap: 30px;
      margin-bottom: 40px;
    }

    @media (max-width: 1200px) {
      .content-grid {
        grid-template-columns: 1fr;
      }
    }

    .chart-container, .top-products {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
    }

    .chart-container {
      /* Sales Analytics takes 3fr space */
    }

    .top-products {
      /* Top Products takes 1fr space */
      max-height: 500px;
      overflow-y: auto;
    }

    .chart-container:hover, .top-products:hover {
      box-shadow: var(--shadow-hover);
      transform: translateY(-2px);
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

    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      overflow-x: auto;
      border: 1px solid var(--border);
    }

    .table-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 15px;
    }

    .search-box {
      position: relative;
      width: 300px;
    }

    .search-box input {
      width: 100%;
      padding: 12px 15px 12px 40px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
    }

    .search-box input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
      outline: none;
    }

    .search-box i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
    }

    .btn {
      padding: 12px 18px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(67, 97, 238, 0.3);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(230, 57, 70, 0.3);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
      color: white;
    }

    .btn-success:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1000px;
    }

    .data-table th, .data-table td {
      padding: 14px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .data-table th {
      color: var(--text-muted);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: rgba(0, 0, 0, 0.02);
      position: sticky;
      top: 0;
    }

    .dark-mode .data-table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .data-table tr:hover {
      background: rgba(67, 97, 238, 0.03);
    }

    .checkbox-cell {
      width: 40px;
      text-align: center;
    }

    .status-badge, .order-type-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.3px;
      display: inline-block;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-confirmed {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-received {
      background: rgba(59, 130, 246, 0.1);
      color: #3b82f6;
      border: 1px solid rgba(59, 130, 246, 0.2);
    }

    .status-cancelled {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .action-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }

    .footer {
      text-align: center;
      padding: 24px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 40px;
      font-weight: 500;
    }

    /* Chart Controls */
    .chart-controls {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
      flex-wrap: wrap;
      align-items: center;
    }

    .filter-group {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .filter-select, .date-input {
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
    }

    .filter-btn {
      padding: 8px 16px;
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .filter-btn:hover {
      background: var(--primary-dark);
    }

    /* Bigger Chart Space */
    .chart-placeholder {
      height: 450px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      margin-top: 15px;
      position: relative;
    }

    /* Form Inputs */
    .qty-input, .total-input {
      width: 80px;
      padding: 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      text-align: center;
      transition: var(--transition);
    }

    .qty-input:focus, .total-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
      outline: none;
    }

    .total-input {
      width: 100px;
    }

    /* Improved Bulk Action Buttons */
    .bulk-actions {
      display: flex;
      gap: 12px;
      align-items: center;
      padding: 15px;
      background: rgba(67, 97, 238, 0.05);
      border-radius: 10px;
      margin-bottom: 20px;
      border: 1px solid rgba(67, 97, 238, 0.1);
    }

    .bulk-actions .btn {
      padding: 10px 16px;
      font-size: 14px;
    }

    /* Reference ID Styling */
    .reference-id {
      font-family: 'Courier New', monospace;
      font-size: 12px;
      color: var(--text-muted);
      background: rgba(0, 0, 0, 0.05);
      padding: 4px 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
    }

    .dark-mode .reference-id {
      background: rgba(255, 255, 255, 0.05);
    }

    /* Modals */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }

    .modal-overlay.active {
      display: flex;
    }

    .modal {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 30px;
      box-shadow: var(--shadow-hover);
      max-width: 900px;
      width: 95%;
      max-height: 90vh;
      overflow-y: auto;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-title {
      font-size: 20px;
      font-weight: 700;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      color: var(--text-muted);
      cursor: pointer;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text);
    }

    .form-input {
      width: 100%;
      padding: 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
    }

    .form-input:focus {
      border-color: var(--primary);
      outline: none;
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    /* Sales Report Modal */
    .sales-report {
      margin-bottom: 20px;
    }

    .sales-report-item {
      display: flex;
      justify-content: space-between;
      padding: 15px;
      border-bottom: 1px solid var(--border);
    }

    .sales-report-total {
      display: flex;
      justify-content: space-between;
      padding: 20px;
      background: rgba(67, 97, 238, 0.05);
      border-radius: 8px;
      margin-top: 20px;
      font-weight: 700;
      font-size: 18px;
    }

    .sales-report-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--border);
    }

    .sales-report-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 20px;
    }

    .sales-report-detail-item {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
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
      
      .table-actions {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .search-box {
        width: 100%;
      }
      
      .chart-controls {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .bulk-actions {
        flex-direction: column;
        align-items: stretch;
      }
      
      .chart-placeholder {
        height: 350px;
      }
      
      .content-grid {
        grid-template-columns: 1fr;
      }
      
      .sales-report-details {
        grid-template-columns: 1fr;
      }
    }

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

    /* Clickable rows */
    .clickable-row {
      cursor: pointer;
      transition: var(--transition);
    }

    .clickable-row:hover {
      background: rgba(67, 97, 238, 0.08) !important;
    }

    /* Quick Actions from index.php */
    .quick-actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-bottom: 30px;
    }

    .action-btn-refresh {
      background: var(--card-bg);
      border: none;
      border-radius: 12px;
      padding: 18px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow);
      text-decoration: none;
      color: var(--text);
    }

    .action-btn-refresh:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .action-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .action-text {
      font-weight: 600;
      font-size: 14px;
      text-align: center;
    }
  </style>
</head>
<body class="light-mode">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Toast Notifications Container -->
  <div class="toast-container" id="toastContainer"></div>

  <!-- Edit Order Modal -->
  <div class="modal-overlay" id="editOrderModal">
    <div class="modal">
      <div class="modal-header">
        <h3 class="modal-title">Edit Order</h3>
        <button class="modal-close" id="closeModal">&times;</button>
      </div>
      <form id="editOrderForm" method="POST">
        <input type="hidden" name="order_id" id="editOrderId">
        <input type="hidden" name="update_order" value="1">
        
        <div class="form-group">
          <label class="form-label">Reference ID</label>
          <div class="reference-id" id="modalReferenceId"></div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Customer Name</label>
          <input type="text" name="customer_name" id="editCustomerName" class="form-input" required>
        </div>
        
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" id="editOrderStatus" class="form-input" required>
            <option value="Pending">Pending</option>
            <option value="Confirmed">Confirmed</option>
            <option value="Received" disabled>Received (Cannot Edit)</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="form-label">Order Items</label>
          <div id="orderItemsContainer"></div>
        </div>
        
        <div class="form-group">
          <button type="submit" class="btn btn-success" style="width: 100%;">
            <i class="fas fa-save"></i> Update Order
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Enhanced Sales Report Modal -->
  <div class="modal-overlay" id="salesReportModal">
    <div class="modal">
      <div class="modal-header">
        <h3 class="modal-title">Detailed Sales Report</h3>
        <button class="modal-close" id="closeReportModal">&times;</button>
      </div>
      
      <div class="sales-report-content">
        <div id="salesReportPrint">
          <!-- Report Header -->
          <div class="report-header">
            <div class="report-company">MARCOMEDIA STUDIO</div>
            <div class="report-title">DETAILED SALES REPORT</div>
            <div style="color: var(--text-muted); font-size: 14px;" id="reportGeneratedDate">
              Generated on: <?php echo date('F j, Y g:i A'); ?>
            </div>
          </div>

          <!-- Report Meta Information -->
          <div class="report-meta">
            <div>
              <div class="meta-item">
                <span class="meta-label">Reference ID:</span>
                <span class="meta-value" id="reportReferenceId"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Sale Date:</span>
                <span class="meta-value" id="reportSaleDate"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Order Type:</span>
                <span class="meta-value" id="reportOrderType"></span>
              </div>
            </div>
            <div>
              <div class="meta-item">
                <span class="meta-label">Status:</span>
                <span class="meta-value" id="reportStatus"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Payment Method:</span>
                <span class="meta-value" id="reportPaymentMethod"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Processed By:</span>
                <span class="meta-value"><?php echo $username; ?></span>
              </div>
            </div>
          </div>

          <!-- Customer Information -->
          <div class="report-section">
            <div class="section-title">CUSTOMER INFORMATION</div>
            <div class="report-meta">
              <div>
                <div class="meta-item">
                  <span class="meta-label">Customer Name:</span>
                  <span class="meta-value" id="reportCustomer"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Contact:</span>
                  <span class="meta-value" id="reportContact"></span>
                </div>
              </div>
              <div>
                <div class="meta-item">
                  <span class="meta-label">Order Reference:</span>
                  <span class="meta-value" id="reportOrderRef"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Transaction ID:</span>
                  <span class="meta-value" id="reportTransactionId"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Items Details -->
          <div class="report-section">
            <div class="section-title">ITEMS DETAILS</div>
            <table class="items-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Quantity</th>
                  <th>Unit Price</th>
                  <th>Subtotal</th>
                </tr>
              </thead>
              <tbody id="reportItems">
                <!-- Items will be populated here -->
              </tbody>
            </table>
          </div>

          <!-- Financial Summary -->
          <div class="report-section">
            <div class="section-title">FINANCIAL SUMMARY</div>
            <div class="summary-grid">
              <div class="summary-card">
                <div class="summary-value" id="reportSubtotal">₱0.00</div>
                <div class="summary-label">Subtotal</div>
              </div>
              <div class="summary-card warning">
                <div class="summary-value" id="reportDiscount">₱0.00</div>
                <div class="summary-label">Discount</div>
              </div>
              <div class="summary-card info">
                <div class="summary-value" id="reportTax">₱0.00</div>
                <div class="summary-label">Tax (12%)</div>
              </div>
              <div class="summary-card success">
                <div class="summary-value" id="reportGrandTotal">₱0.00</div>
                <div class="summary-label">Grand Total</div>
              </div>
            </div>
          </div>

          <!-- Additional Information -->
          <div class="report-section">
            <div class="section-title">ADDITIONAL INFORMATION</div>
            <div class="report-meta">
              <div>
                <div class="meta-item">
                  <span class="meta-label">Profit Margin:</span>
                  <span class="meta-value" id="reportProfitMargin">0%</span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Estimated Profit:</span>
                  <span class="meta-value" id="reportEstimatedProfit">₱0.00</span>
                </div>
              </div>
              <div>
                <div class="meta-item">
                  <span class="meta-label">Payment Status:</span>
                  <span class="meta-value" id="reportPaymentStatus">Paid</span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Delivery Status:</span>
                  <span class="meta-value" id="reportDeliveryStatus">Completed</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Report Footer -->
          <div class="report-footer">
            <p>This is an computer-generated report. No signature is required.</p>
            <p>Marcomedia Studio • contact@marcomedia.com • (123) 456-7890</p>
          </div>
        </div>

        <!-- Print Actions -->
        <div class="print-actions no-print">
          <button type="button" class="btn btn-primary" onclick="printSalesReport()">
            <i class="fas fa-print"></i> Print Report
          </button>
          <button type="button" class="btn btn-success" onclick="downloadSalesReport()">
            <i class="fas fa-download"></i> Download PDF
          </button>
          <button type="button" class="btn btn-secondary modal-close">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Purchase Order Report Modal -->
  <div class="modal-overlay" id="purchaseOrderModal">
    <div class="modal">
      <div class="modal-header">
        <h3 class="modal-title">Purchase Order Report</h3>
        <button class="modal-close" id="closePurchaseOrderModal">&times;</button>
      </div>
      
      <div class="sales-report-content">
        <div id="purchaseOrderReportPrint">
          <!-- Report Header -->
          <div class="report-header">
            <div class="report-company">MARCOMEDIA STUDIO</div>
            <div class="report-title">PURCHASE ORDER REPORT</div>
            <div style="color: var(--text-muted); font-size: 14px;" id="purchaseOrderGeneratedDate">
              Generated on: <?php echo date('F j, Y g:i A'); ?>
            </div>
          </div>

          <!-- Order Information -->
          <div class="report-meta">
            <div>
              <div class="meta-item">
                <span class="meta-label">Order Reference:</span>
                <span class="meta-value" id="poReferenceId"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Order Date:</span>
                <span class="meta-value" id="poOrderDate"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Order Type:</span>
                <span class="meta-value" id="poOrderType"></span>
              </div>
            </div>
            <div>
              <div class="meta-item">
                <span class="meta-label">Status:</span>
                <span class="meta-value" id="poStatus"></span>
              </div>
              <div class="meta-item">
                <span class="meta-label">Processed By:</span>
                <span class="meta-value"><?php echo $username; ?></span>
              </div>
            </div>
          </div>

          <!-- Customer Information -->
          <div class="report-section">
            <div class="section-title">CUSTOMER INFORMATION</div>
            <div class="report-meta">
              <div>
                <div class="meta-item">
                  <span class="meta-label">Customer Name:</span>
                  <span class="meta-value" id="poCustomer"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Email:</span>
                  <span class="meta-value" id="poEmail"></span>
                </div>
              </div>
              <div>
                <div class="meta-item">
                  <span class="meta-label">Phone:</span>
                  <span class="meta-value" id="poPhone"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Address:</span>
                  <span class="meta-value" id="poAddress"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Order Items -->
          <div class="report-section">
            <div class="section-title">ORDER ITEMS</div>
            <table class="items-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Quantity</th>
                  <th>Unit Price</th>
                  <th>Subtotal</th>
                </tr>
              </thead>
              <tbody id="poItems">
                <!-- Items will be populated here -->
              </tbody>
            </table>
          </div>

          <!-- Order Summary -->
          <div class="report-section">
            <div class="section-title">ORDER SUMMARY</div>
            <div class="summary-grid">
              <div class="summary-card">
                <div class="summary-value" id="poSubtotal">₱0.00</div>
                <div class="summary-label">Subtotal</div>
              </div>
              <div class="summary-card info">
                <div class="summary-value" id="poTax">₱0.00</div>
                <div class="summary-label">Tax (12%)</div>
              </div>
              <div class="summary-card warning">
                <div class="summary-value" id="poShipping">₱0.00</div>
                <div class="summary-label">Shipping</div>
              </div>
              <div class="summary-card success">
                <div class="summary-value" id="poGrandTotal">₱0.00</div>
                <div class="summary-label">Grand Total</div>
              </div>
            </div>
          </div>

          <!-- Additional Information -->
          <div class="report-section">
            <div class="section-title">ORDER TRACKING</div>
            <div class="report-meta">
              <div>
                <div class="meta-item">
                  <span class="meta-label">Order Created:</span>
                  <span class="meta-value" id="poCreated"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Last Updated:</span>
                  <span class="meta-value" id="poUpdated"></span>
                </div>
              </div>
              <div>
                <div class="meta-item">
                  <span class="meta-label">Estimated Delivery:</span>
                  <span class="meta-value" id="poDelivery"></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Tracking Number:</span>
                  <span class="meta-value" id="poTracking"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Report Footer -->
          <div class="report-footer">
            <p>This is an computer-generated report. No signature is required.</p>
            <p>Marcomedia Studio • contact@marcomedia.com • (123) 456-7890</p>
          </div>
        </div>

        <!-- Print Actions -->
        <div class="print-actions no-print">
          <button type="button" class="btn btn-primary" onclick="printPurchaseOrderReport()">
            <i class="fas fa-print"></i> Print Report
          </button>
          <button type="button" class="btn btn-success" onclick="downloadPurchaseOrderReport()">
            <i class="fas fa-download"></i> Download PDF
          </button>
          <button type="button" class="btn btn-secondary modal-close">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>

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
          <h1>Sales & Orders</h1>
          <p>Track revenue, sales performance, and manage orders</p>
        </div>
        <div class="header-actions">
          <div class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
          </div>
          <!-- REMOVED: Refresh button next to dark mode toggle -->
          <label class="theme-toggle" for="theme-toggle">
            <i class="fas fa-moon"></i>
          </label>
          <input type="checkbox" id="theme-toggle" style="display: none;">
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

      <!-- Quick Actions -->
      <div class="quick-actions">
        <a href="sales.php?chart_filter=day" class="action-btn-refresh">
          <div class="action-icon">
            <i class="fas fa-chart-line"></i>
          </div>
          <div class="action-text">Today's Sales</div>
        </a>
        <a href="stock.php" class="action-btn-refresh">
          <div class="action-icon" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="action-text">Manage Stock</div>
        </a>
        <a href="physical_orders.php" class="action-btn-refresh">
          <div class="action-icon" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
            <i class="fas fa-store"></i>
          </div>
          <div class="action-text">Physical Orders</div>
        </a>
        <button class="action-btn-refresh" onclick="manualRefresh()" id="refreshBtnMain">
          <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #a855f7 100%);">
            <i class="fas fa-sync-alt"></i>
          </div>
          <div class="action-text">Refresh Data</div>
        </button>
      </div>

      <!-- Flash Message -->
      <?php if(!empty($_SESSION['flash'])): ?>
        <div class="flash-message flash-<?php echo $_SESSION['flash']['type'] === 'error' ? 'error' : 'success'; ?>">
          <i class="fas fa-<?php echo $_SESSION['flash']['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- Tabs -->
      <div class="tabs">
        <button class="tab active" data-tab="sales">Sales Tracking</button>
        <button class="tab" data-tab="orders">Purchase Orders</button>
      </div>

      <!-- Sales Tab -->
      <div id="sales-tab" class="tab-content active">
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
          </div>

          <div class="stat-card info">
            <div class="stat-header">
              <div>
                <div class="stat-value">₱<?php echo number_format($onlineSales, 2); ?></div>
                <div class="stat-label">Online Sales</div>
              </div>
              <div class="stat-icon">
                <i class="fas fa-globe"></i>
              </div>
            </div>
          </div>

          <div class="stat-card success">
            <div class="stat-header">
              <div>
                <div class="stat-value">₱<?php echo number_format($physicalSales, 2); ?></div>
                <div class="stat-label">Physical Sales</div>
              </div>
              <div class="stat-icon">
                <i class="fas fa-store"></i>
              </div>
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
          </div>
        </div>

        <!-- Charts and Top Products - Modified Grid -->
        <div class="content-grid">
          <div class="chart-container">
            <div class="section-header">
              <div class="section-title">Sales Analytics</div>
            </div>
            
            <form method="GET" class="chart-controls" id="chartFilterForm">
              <div class="filter-group">
                <select name="chart_filter" class="filter-select" id="chartFilter">
                  <option value="day" <?= $chartFilter == 'day' ? 'selected' : '' ?>>Daily</option>
                  <option value="week" <?= $chartFilter == 'week' ? 'selected' : '' ?>>Weekly</option>
                  <option value="month" <?= $chartFilter == 'month' ? 'selected' : '' ?>>Monthly</option>
                  <option value="year" <?= $chartFilter == 'year' ? 'selected' : '' ?>>Yearly</option>
                </select>
                
                <select name="order_type" class="filter-select" id="orderTypeFilter">
                  <option value="all" <?= $orderTypeFilter == 'all' ? 'selected' : '' ?>>All Sales</option>
                  <option value="online" <?= $orderTypeFilter == 'online' ? 'selected' : '' ?>>Online Only</option>
                  <option value="physical" <?= $orderTypeFilter == 'physical' ? 'selected' : '' ?>>Physical Only</option>
                </select>
              </div>
              
              <div class="filter-group">
                <input type="date" name="start_date" class="date-input" id="startDate" value="<?= $startDate ?>">
                <span style="color: var(--text-muted);">to</span>
                <input type="date" name="end_date" class="date-input" id="endDate" value="<?= $endDate ?>">
              </div>
              
              <button type="submit" class="filter-btn">
                <i class="fas fa-filter"></i> Apply
              </button>
              
              <a href="sales.php" class="filter-btn" style="background: var(--gray); text-decoration: none;">
                <i class="fas fa-refresh"></i> Reset
              </a>
            </form>
            
            <div class="chart-placeholder">
              <canvas id="salesChart" width="400" height="300"></canvas>
            </div>
          </div>

          <div class="top-products">
            <div class="section-header">
              <div class="section-title">Top Products</div>
            </div>
            <div class="top-products-list">
              <?php if (!empty($topProducts)): ?>
                <?php foreach ($topProducts as $index => $product): ?>
                  <div class="top-product-item">
                    <div class="product-rank"><?= $index + 1 ?></div>
                    <div class="product-info">
                      <div class="product-name"><?= htmlspecialchars($product['product']) ?></div>
                      <div class="product-stats">
                        <span class="product-qty"><?= $product['total_qty'] ?> sold</span>
                        <span class="product-sales">₱<?= number_format($product['total_sales'], 2) ?></span>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="no-data">No product data available</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Sales Table with Grouped Items -->
        <div class="table-container">
          <div class="section-header">
            <div class="section-title">Sales Records</div>
          </div>
          
          <!-- Improved Bulk Actions -->
          <form method="POST" id="bulkForm">
            <div class="bulk-actions">
              <div style="display: flex; gap: 12px; align-items: center;">
                <span style="font-weight: 600; color: var(--text);">Bulk Actions:</span>
                <button type="submit" name="update_selected" class="btn btn-success" onclick="return confirm('Are you sure you want to update selected sales?');">
                  <i class="fas fa-save"></i> Update Selected
                </button>
                <button type="submit" name="delete_selected" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete selected sales?');">
                  <i class="fas fa-trash"></i> Delete Selected
                </button>
              </div>
              <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="salesSearch" placeholder="Search products, customers, date...">
              </div>
            </div>

            <table class="data-table">
              <thead>
                <tr>
                  <th class="checkbox-cell">
                    <input type="checkbox" id="selectAllSales">
                  </th>
                  <th>Reference</th>
                  <th>Customer</th>
                  <th>Order Type</th>
                  <th>Product</th>
                  <th>Quantity</th>
                  <th>Total</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $groupIndex = 0;
                foreach($groupedSales as $groupKey => $group): 
                  $groupIndex++;
                  $isEditable = true; // You can add logic to determine if group is editable
                ?>
                <!-- Group Header Row -->
                <tr class="grouped-sale-row clickable-row" onclick="toggleGroup(<?= $groupIndex ?>)" id="groupHeader<?= $groupIndex ?>">
                  <td class="checkbox-cell" onclick="event.stopPropagation();">
                    <input type="checkbox" name="selected_sales[]" value="<?= $groupKey ?>" class="group-checkbox">
                  </td>
                  <td colspan="2">
                    <div class="group-toggle">
                      <span class="group-indicator">▶</span>
                      <strong><?= htmlspecialchars($group['customer_name']) ?></strong>
                      <small style="color: var(--text-muted); margin-left: 10px;">
                        <?= date('M j, Y', strtotime($group['sale_date'])) ?>
                      </small>
                    </div>
                  </td>
                  <td>
                    <span class="order-type-badge order-type-<?= $group['order_type'] ?>">
                      <?= ucfirst($group['order_type']) ?>
                    </span>
                  </td>
                  <td>
                    <em><?= count($group['items']) ?> item(s)</em>
                  </td>
                  <td><strong><?= $group['total_quantity'] ?></strong></td>
                  <td><strong>₱<?= number_format($group['total_amount'], 2) ?></strong></td>
                  <td onclick="event.stopPropagation();">
                    <div class="action-cell">
                      <button type="button" class="action-btn view" onclick="event.stopPropagation(); showGroupedSalesReport('<?= $groupKey ?>', <?= htmlspecialchars(json_encode($group), ENT_QUOTES) ?>)">
                        <i class="fas fa-eye"></i> View
                      </button>
                    </div>
                  </td>
                </tr>
                
                <!-- Group Details Rows -->
                <tbody class="grouped-details" id="groupDetails<?= $groupIndex ?>">
                  <?php foreach($group['items'] as $item): ?>
                  <tr class="grouped-item-row">
                    <td class="checkbox-cell">
                      <input type="checkbox" name="selected_sales[]" value="<?= $item['id'] ?>">
                    </td>
                    <td>
                      <span class="reference-id">SALE-<?= $item['id'] ?></span>
                    </td>
                    <td></td> <!-- Empty customer cell since it's in group header -->
                    <td></td> <!-- Empty order type cell -->
                    <td><?= htmlspecialchars($item['product']) ?></td>
                    <td>
                      <input type="number" class="qty-input" name="quantity[<?= $item['id'] ?>]" 
                             value="<?= $item['quantity'] ?>" min="1" <?= $isEditable ? '' : 'disabled' ?>>
                    </td>
                    <td>
                      <input type="number" class="total-input" name="total[<?= $item['id'] ?>]" 
                             value="<?= $item['total'] ?>" min="0" step="0.01" <?= $isEditable ? '' : 'disabled' ?>>
                    </td>
                    <td onclick="event.stopPropagation();">
                      <div class="action-cell">
                        <?php if ($isEditable): ?>
                          <!-- Update form -->
                          <form method="POST" onsubmit="return confirm('Are you sure you want to update this sale?');" style="display: flex;">
                            <input type="hidden" name="sale_id" value="<?= $item['id'] ?>">
                            <input type="hidden" name="quantity" value="<?= $item['quantity'] ?>">
                            <input type="hidden" name="total" value="<?= $item['total'] ?>">
                            <button type="submit" name="update_sale" class="action-btn edit">
                              <i class="fas fa-edit"></i> Edit
                            </button>
                          </form>
                          <!-- Delete form -->
                          <form method="POST" onsubmit="return confirm('Are you sure you want to delete this sale?');" style="display: flex;">
                            <input type="hidden" name="sale_id" value="<?= $item['id'] ?>">
                            <button type="submit" name="delete_sale" class="action-btn delete">
                              <i class="fas fa-trash"></i> Delete
                            </button>
                          </form>
                        <?php else: ?>
                          <span style="color: var(--text-muted); font-size: 12px; padding: 8px 12px;">Completed</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <?php endforeach; ?>
                
                <?php if (empty($groupedSales)): ?>
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 40px;">
                      <i class="fas fa-inbox" style="font-size: 48px; color: var(--text-muted); margin-bottom: 15px; display: block;"></i>
                      <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No sales records found</div>
                      <div style="color: var(--text-muted);">Sales will appear here once they are created.</div>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </form>
        </div>
      </div>

      <!-- Orders Tab -->
      <div id="orders-tab" class="tab-content">
        <!-- Order Stats -->
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

          <div class="stat-card info" onclick="filterOrders('Received')">
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

        <!-- Orders Table -->
        <div class="table-container">
          <div class="section-header">
            <div class="section-title">Order Records</div>
          </div>
          
          <!-- Hidden form for individual actions -->
          <form method="POST" id="actionForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="order_id" id="formOrderId">
          </form>

          <form method="POST" id="bulkDeleteForm">
            <div class="bulk-actions">
              <div style="display: flex; gap: 12px; align-items: center;">
                <span style="font-weight: 600; color: var(--text);">Bulk Actions:</span>
                <button type="submit" name="delete_orders" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete selected orders?');">
                  <i class="fas fa-trash"></i> Delete Selected
                </button>
              </div>
              <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="ordersSearch" placeholder="Search orders by customer, product, or ID...">
              </div>
            </div>

            <table class="data-table">
              <thead>
                <tr>
                  <th class="checkbox-cell">
                    <input type="checkbox" id="selectAllOrders">
                  </th>
                  <th>Reference</th>
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
                        <span class="reference-id"><?= $order['reference_id'] ?></span>
                      </td>
                      <td>
                        <div class="customer-info">
                          <div class="customer-name"><?= htmlspecialchars($order['display_name']); ?></div>
                          <?php if (!empty($order['customer_email'])): ?>
                            <div class="customer-details"><?= htmlspecialchars($order['customer_email']); ?></div>
                          <?php endif; ?>
                        </div>
                        <div style="margin-top: 8px;">
                          <?php if ($order['order_type'] == 'physical'): ?>
                            <span class="order-type-badge order-type-physical">Physical Store</span>
                          <?php elseif ($order['order_type'] == 'website'): ?>
                            <span class="order-type-badge order-type-website">Website</span>
                          <?php else: ?>
                            <span class="order-type-badge order-type-online">Online</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <div style="max-width: 200px;">
                          <?= !empty($itemsList) ? implode("<br>", array_slice($itemsList, 0, 2)) : "No items"; ?>
                          <?php if (count($itemsList) > 2): ?>
                            <br><small style="color: var(--text-muted);">+<?= count($itemsList) - 2; ?> more items</small>
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
                        <small style="color: var(--text-muted);"><?= date('g:i A', strtotime($order['created_at'])); ?></small>
                      </td>
                      <td>
                        <div class="action-cell">
                          <?php if ($order['status'] === 'Pending'): ?>
                            <button type="button" class="action-btn edit" onclick="editOrder(<?= $order['id']; ?>)">
                              <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="action-btn success" onclick="confirmOrder(<?= $order['id']; ?>)">
                              <i class="fas fa-check"></i> Confirm
                            </button>
                            <button type="button" class="action-btn view" onclick="showPurchaseOrderReport(<?= $order['id']; ?>, <?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)">
                              <i class="fas fa-eye"></i> View
                            </button>
                          <?php elseif ($order['status'] === 'Confirmed'): ?>
                            <button type="button" class="action-btn edit" onclick="editOrder(<?= $order['id']; ?>)">
                              <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="action-btn success" onclick="receiveOrder(<?= $order['id']; ?>)">
                              <i class="fas fa-truck"></i> Receive
                            </button>
                            <button type="button" class="action-btn view" onclick="showPurchaseOrderReport(<?= $order['id']; ?>, <?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)">
                              <i class="fas fa-eye"></i> View
                            </button>
                          <?php elseif ($order['status'] === 'Received'): ?>
                            <button type="button" class="action-btn view" onclick="showPurchaseOrderReport(<?= $order['id']; ?>, <?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)">
                              <i class="fas fa-eye"></i> View
                            </button>
                            <span style="color: var(--success); font-weight: 600;">
                              <i class="fas fa-check-circle"></i> Received
                            </span>
                          <?php elseif ($order['status'] === 'Cancelled'): ?>
                            <button type="button" class="action-btn view" onclick="showPurchaseOrderReport(<?= $order['id']; ?>, <?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)">
                              <i class="fas fa-eye"></i> View
                            </button>
                            <span style="color: var(--danger); font-weight: 600;">
                              <i class="fas fa-times-circle"></i> Cancelled
                            </span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 40px;">
                      <i class="fas fa-inbox" style="font-size: 48px; color: var(--text-muted); margin-bottom: 15px; display: block;"></i>
                      <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No orders found</div>
                      <div style="color: var(--text-muted);">Orders created through checkout will appear here.</div>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </form>
        </div>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved. | Last updated: <span id="last-updated"><?php echo date('H:i:s'); ?></span></p>
      </div>

      <!-- Last Refresh Indicator -->
      <div class="last-refresh" id="lastRefresh">
        <i class="fas fa-clock"></i> Last refresh: <span id="lastRefreshTime"><?php echo date('H:i:s'); ?></span>
      </div>
    </div>
  </div>

  <script>
    // Toast Notification System (from index.php)
    function showToast(message, title = '', type = 'info', duration = 5000) {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };
        
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${icons[type] || icons.info}"></i>
            </div>
            <div class="toast-content">
                ${title ? `<div class="toast-title">${title}</div>` : ''}
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
            <div class="toast-progress"></div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.classList.add('show');
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.transition = `transform ${duration}ms linear`;
                progressBar.classList.add('hiding');
            }
        }, 100);
        
        // Auto remove after duration
        const autoRemove = setTimeout(() => {
            hideToast(toast);
        }, duration);
        
        // Pause on hover
        toast.addEventListener('mouseenter', () => {
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.transition = 'none';
            }
            clearTimeout(autoRemove);
        });
        
        toast.addEventListener('mouseleave', () => {
            const progressBar = toast.querySelector('.toast-progress');
            const remainingTime = duration - 100; // Approximate remaining time
            
            if (progressBar) {
                progressBar.style.transition = `transform ${remainingTime}ms linear`;
                progressBar.classList.add('hiding');
            }
            
            setTimeout(() => {
                if (toast.parentElement) {
                    hideToast(toast);
                }
            }, remainingTime);
        });
    }

    function hideToast(toast) {
        toast.classList.remove('show');
        toast.classList.add('hiding');
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 400);
    }

    // Quick toast functions
    const Toast = {
        success: (message, title = 'Success!') => showToast(message, title, 'success'),
        error: (message, title = 'Error!') => showToast(message, title, 'error'),
        warning: (message, title = 'Warning!') => showToast(message, title, 'warning'),
        info: (message, title = 'Info') => showToast(message, title, 'info')
    };

    // Auto-Refresh System for Live Data
    let refreshInterval;
    let isPageVisible = true;

    function startAutoRefresh() {
        // Refresh every 30 seconds
        refreshInterval = setInterval(() => {
            if (isPageVisible) {
                updateLiveCounts();
            }
        }, 30000); // 30 seconds

        // Also refresh when page becomes visible
        document.addEventListener('visibilitychange', function() {
            isPageVisible = !document.hidden;
            if (isPageVisible) {
                updateLiveCounts(); // Refresh immediately when returning to tab
            }
        });
    }

    function updateLiveCounts() {
        // In a real implementation, you would fetch from your server
        // For now, we'll simulate updates and refresh the page periodically
        setTimeout(() => {
            // Update last refresh time
            updateLastRefreshTime();
            
            // Show a subtle notification
            Toast.info('Data refreshed automatically', 'Auto-Refresh');
        }, 1000);
    }

    function updateLastRefreshTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        const lastUpdatedElement = document.getElementById('last-updated');
        const lastRefreshTimeElement = document.getElementById('lastRefreshTime');
        
        if (lastUpdatedElement) {
            lastUpdatedElement.textContent = timeString;
        }
        if (lastRefreshTimeElement) {
            lastRefreshTimeElement.textContent = timeString;
        }
    }

    // Enhanced manual refresh function
    function manualRefresh() {
        const refreshBtnMain = document.getElementById('refreshBtnMain');
        const icon = refreshBtnMain ? refreshBtnMain.querySelector('i') : null;
        
        if (icon) {
            // Add refreshing animation
            refreshBtnMain?.classList.add('refreshing');
            icon.className = 'fas fa-spinner';
        }
        
        Toast.info('Refreshing live data...', 'Auto-Refresh');
        
        // Simulate API call - in production, this would fetch from your server
        setTimeout(() => {
            window.location.reload();
        }, 1500);
        
        // Remove animation after 2 seconds
        setTimeout(() => {
            refreshBtnMain?.classList.remove('refreshing');
            if (icon) {
                icon.className = 'fas fa-sync-alt';
            }
        }, 2000);
    }

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

    // Tab functionality
    document.addEventListener('DOMContentLoaded', function() {
      const tabs = document.querySelectorAll('.tab');
      const tabContents = document.querySelectorAll('.tab-content');
      
      tabs.forEach(tab => {
        tab.addEventListener('click', function() {
          const targetTab = this.getAttribute('data-tab');
          
          // Update tabs
          tabs.forEach(t => t.classList.remove('active'));
          this.classList.add('active');
          
          // Update content
          tabContents.forEach(content => {
            content.classList.remove('active');
            if (content.id === targetTab + '-tab') {
              content.classList.add('active');
            }
          });
        });
      });

      // Initialize theme
      const savedTheme = localStorage.getItem('theme');
      const themeToggle = document.getElementById('theme-toggle');
      
      if (savedTheme === 'dark') {
        document.body.classList.remove('light-mode');
        document.body.classList.add('dark-mode');
        if (themeToggle) themeToggle.checked = true;
      }

      // Initialize chart with improved design
      initializeSalesChart({
        labels: <?php echo json_encode($chartLabels); ?>,
        data: <?php echo json_encode($monthlySalesData); ?>,
        filter: '<?php echo $chartFilter; ?>'
      });

      // Update current date
      updateDateTime();
      setInterval(updateDateTime, 60000);

      // Modal functionality
      const modals = document.querySelectorAll('.modal-overlay');
      const closeButtons = document.querySelectorAll('.modal-close');

      closeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
          modals.forEach(modal => modal.classList.remove('active'));
        });
      });

      modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
          if (e.target === modal) {
            modal.classList.remove('active');
          }
        });
      });

      // Start auto-refresh for live data
      startAutoRefresh();

      // Show any flash messages as toasts
      <?php if(!empty($_SESSION['flash'])): ?>
        setTimeout(() => {
            Toast.<?= $_SESSION['flash']['type'] === 'error' ? 'error' : 'success'; ?>('<?= addslashes($_SESSION['flash']['message']); ?>');
        }, 1000);
        <?php unset($_SESSION['flash']); ?>
      <?php endif; ?>
    });

    // Update date and time
    function updateDateTime() {
      const now = new Date();
      const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const dateElement = document.getElementById('current-date');
      if (dateElement) {
        dateElement.textContent = dateString;
      }
    }

    // Theme toggle
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
      themeToggle.addEventListener('change', function() {
        if (this.checked) {
          document.body.classList.remove('light-mode');
          document.body.classList.add('dark-mode');
          localStorage.setItem('theme', 'dark');
          Toast.info('Dark mode enabled', 'Theme');
        } else {
          document.body.classList.remove('dark-mode');
          document.body.classList.add('light-mode');
          localStorage.setItem('theme', 'light');
          Toast.info('Light mode enabled', 'Theme');
        }
        // Reinitialize chart with new theme
        initializeSalesChart({
          labels: <?php echo json_encode($chartLabels); ?>,
          data: <?php echo json_encode($monthlySalesData); ?>,
          filter: '<?php echo $chartFilter; ?>'
        });
      });
    }

    // Improved Sales Chart with better design and bigger space
    function initializeSalesChart(data) {
      const ctx = document.getElementById('salesChart').getContext('2d');
      
      // Destroy existing chart if it exists
      if (window.salesChartInstance) {
        window.salesChartInstance.destroy();
      }
      
      // Determine if dark mode is active
      const isDarkMode = document.body.classList.contains('dark-mode');
      
      // Chart colors based on theme
      const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
      const textColor = isDarkMode ? '#f1f5f9' : '#1e293b';
      const primaryColor = '#4361ee';
      const backgroundColor = isDarkMode 
        ? 'rgba(67, 97, 238, 0.3)' 
        : 'rgba(67, 97, 238, 0.2)';
      
      // Create gradient
      const gradient = ctx.createLinearGradient(0, 0, 0, 400);
      gradient.addColorStop(0, backgroundColor);
      gradient.addColorStop(1, 'rgba(67, 97, 238, 0.05)');
      
      // Create new chart with improved design
      window.salesChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [{
            label: 'Sales Revenue',
            data: data.data,
            backgroundColor: gradient,
            borderColor: primaryColor,
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: primaryColor,
            pointBorderColor: isDarkMode ? '#1a222d' : '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointHoverBackgroundColor: primaryColor,
            pointHoverBorderColor: '#ffffff',
            pointHoverBorderWidth: 3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              backgroundColor: isDarkMode ? '#1e293b' : '#ffffff',
              titleColor: textColor,
              bodyColor: textColor,
              borderColor: isDarkMode ? '#334155' : '#e2e8f0',
              borderWidth: 1,
              padding: 12,
              boxPadding: 6,
              usePointStyle: true,
              callbacks: {
                label: function(context) {
                  return `₱${context.raw.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                },
                title: function(tooltipItems) {
                  return data.filter === 'day' 
                    ? tooltipItems[0].label 
                    : `Period: ${tooltipItems[0].label}`;
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: gridColor,
                drawBorder: false,
                drawTicks: false
              },
              ticks: {
                color: textColor,
                padding: 10,
                callback: function(value) {
                  if (value >= 1000) {
                    return '₱' + (value / 1000).toFixed(0) + 'K';
                  }
                  return '₱' + value;
                }
              },
              border: {
                display: false
              }
            },
            x: {
              grid: {
                display: false,
                drawBorder: false
              },
              ticks: {
                color: textColor,
                padding: 10,
                maxRotation: 45
              },
              border: {
                display: false
              }
            }
          },
          interaction: {
            intersect: false,
            mode: 'index'
          },
          animations: {
            tension: {
              duration: 1000,
              easing: 'linear'
            }
          }
        }
      });
    }

    // Search functionality
    document.getElementById('salesSearch')?.addEventListener('keyup', function() {
      const filter = this.value.toLowerCase();
      document.querySelectorAll('#sales-tab .data-table tbody tr').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(filter) ? "" : "none";
      });
    });

    document.getElementById('ordersSearch')?.addEventListener('keyup', function() {
      const filter = this.value.toLowerCase();
      document.querySelectorAll('#orders-tab .data-table tbody tr').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(filter) ? "" : "none";
      });
    });

    // Select all checkboxes
    document.getElementById('selectAllSales')?.addEventListener('change', function() {
      document.querySelectorAll('#sales-tab input[name="selected_sales[]"]').forEach(cb => {
        cb.checked = this.checked;
      });
    });

    document.getElementById('selectAllOrders')?.addEventListener('change', function() {
      document.querySelectorAll('#orders-tab input[name="order_ids[]"]').forEach(cb => {
        cb.checked = this.checked;
      });
    });

    // Group toggle functionality
    function toggleGroup(groupIndex) {
      const groupHeader = document.getElementById('groupHeader' + groupIndex);
      const groupDetails = document.getElementById('groupDetails' + groupIndex);
      
      groupHeader.classList.toggle('group-expanded');
      groupDetails.style.display = groupDetails.style.display === 'table-row-group' ? 'none' : 'table-row-group';
    }

    // Order actions
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

    // Edit order functionality
    function editOrder(orderId) {
      const modal = document.getElementById('editOrderModal');
      const order = <?php echo json_encode($orders); ?>.find(o => o.id == orderId);
      
      if (order) {
        document.getElementById('editOrderId').value = order.id;
        document.getElementById('modalReferenceId').textContent = order.reference_id;
        document.getElementById('editCustomerName').value = order.display_name || order.customer_name || '';
        document.getElementById('editOrderStatus').value = order.status;
        
        // Populate order items
        const itemsContainer = document.getElementById('orderItemsContainer');
        itemsContainer.innerHTML = '';
        
        const orderItems = <?php echo json_encode($order_items); ?>[orderId] || [];
        orderItems.forEach(item => {
          const itemDiv = document.createElement('div');
          itemDiv.className = 'form-group';
          itemDiv.style.border = '1px solid var(--border)';
          itemDiv.style.padding = '15px';
          itemDiv.style.borderRadius = '8px';
          itemDiv.style.marginBottom = '10px';
          
          itemDiv.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
              <strong>${item.name}</strong>
              <span>₱${parseFloat(item.price).toFixed(2)} each</span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
              <label class="form-label" style="margin: 0;">Quantity:</label>
              <input type="number" name="order_items[${item.id}][quantity]" value="${item.quantity}" min="1" class="qty-input" style="width: 100px;">
            </div>
          `;
          
          itemsContainer.appendChild(itemDiv);
        });
        
        modal.classList.add('active');
      }
    }

    // Enhanced Sales Report functionality for grouped sales
    function showGroupedSalesReport(groupKey, groupData) {
      const modal = document.getElementById('salesReportModal');
      
      // Calculate financial data for the entire group
      const subtotal = groupData.total_amount || 0;
      const taxRate = 0.12; // 12% tax
      const taxAmount = subtotal * taxRate;
      const discountRate = 0.10; // 10% discount
      const discountAmount = subtotal * discountRate;
      const grandTotal = subtotal + taxAmount - discountAmount;
      const profitMargin = 0.25; // 25% profit margin
      const estimatedProfit = grandTotal * profitMargin;
      
      // Populate modal with grouped sale data
      document.getElementById('reportReferenceId').textContent = 'GROUP-' + groupKey;
      document.getElementById('reportSaleDate').textContent = new Date(groupData.sale_date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
      document.getElementById('reportOrderType').textContent = groupData.order_type ? 
        groupData.order_type.charAt(0).toUpperCase() + groupData.order_type.slice(1) : 'Online';
      document.getElementById('reportStatus').textContent = 'Completed';
      document.getElementById('reportPaymentMethod').textContent = groupData.payment_method || 'Cash';
      document.getElementById('reportCustomer').textContent = groupData.customer_name || 'Walk-in Customer';
      document.getElementById('reportContact').textContent = groupData.customer_phone || 'N/A';
      document.getElementById('reportOrderRef').textContent = 'GROUP-' + groupKey;
      document.getElementById('reportTransactionId').textContent = 'GROUP-' + groupKey;
      
      // Populate items table with all items in the group
      const itemsContainer = document.getElementById('reportItems');
      itemsContainer.innerHTML = '';
      
      groupData.items.forEach(item => {
        const unitPrice = item.quantity > 0 ? item.total / item.quantity : 0;
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${item.product}</td>
          <td>${item.quantity}</td>
          <td>₱${unitPrice.toFixed(2)}</td>
          <td>₱${parseFloat(item.total).toFixed(2)}</td>
        `;
        itemsContainer.appendChild(row);
      });
      
      // Populate financial summary
      document.getElementById('reportSubtotal').textContent = '₱' + subtotal.toFixed(2);
      document.getElementById('reportDiscount').textContent = '₱' + discountAmount.toFixed(2);
      document.getElementById('reportTax').textContent = '₱' + taxAmount.toFixed(2);
      document.getElementById('reportGrandTotal').textContent = '₱' + grandTotal.toFixed(2);
      document.getElementById('reportProfitMargin').textContent = (profitMargin * 100).toFixed(1) + '%';
      document.getElementById('reportEstimatedProfit').textContent = '₱' + estimatedProfit.toFixed(2);
      document.getElementById('reportPaymentStatus').textContent = 'Paid';
      document.getElementById('reportDeliveryStatus').textContent = 'Completed';
      
      modal.classList.add('active');
    }

    // Purchase Order Report functionality
    function showPurchaseOrderReport(orderId, orderData) {
      const modal = document.getElementById('purchaseOrderModal');
      
      // Calculate order totals
      let subtotal = 0;
      const items = <?php echo json_encode($order_items); ?>[orderId] || [];
      
      items.forEach(item => {
        subtotal += item.quantity * item.price;
      });
      
      const taxRate = 0.12; // 12% tax
      const taxAmount = subtotal * taxRate;
      const shipping = 50.00; // Fixed shipping cost
      const grandTotal = subtotal + taxAmount + shipping;
      
      // Populate modal with order data
      document.getElementById('poReferenceId').textContent = orderData.reference_id;
      document.getElementById('poOrderDate').textContent = new Date(orderData.created_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
      document.getElementById('poOrderType').textContent = orderData.order_type ? 
        orderData.order_type.charAt(0).toUpperCase() + orderData.order_type.slice(1) : 'Online';
      document.getElementById('poStatus').textContent = orderData.status;
      document.getElementById('poCustomer').textContent = orderData.display_name || 'Unknown Customer';
      document.getElementById('poEmail').textContent = orderData.customer_email || 'N/A';
      document.getElementById('poPhone').textContent = orderData.customer_phone || 'N/A';
      document.getElementById('poAddress').textContent = orderData.customer_address || 'N/A';
      
      // Populate order items
      const itemsContainer = document.getElementById('poItems');
      itemsContainer.innerHTML = '';
      
      items.forEach(item => {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${item.name}</td>
          <td>${item.quantity}</td>
          <td>₱${parseFloat(item.price).toFixed(2)}</td>
          <td>₱${(item.quantity * item.price).toFixed(2)}</td>
        `;
        itemsContainer.appendChild(row);
      });
      
      // Populate order summary
      document.getElementById('poSubtotal').textContent = '₱' + subtotal.toFixed(2);
      document.getElementById('poTax').textContent = '₱' + taxAmount.toFixed(2);
      document.getElementById('poShipping').textContent = '₱' + shipping.toFixed(2);
      document.getElementById('poGrandTotal').textContent = '₱' + grandTotal.toFixed(2);
      
      // Populate tracking information
      document.getElementById('poCreated').textContent = new Date(orderData.created_at).toLocaleDateString();
      document.getElementById('poUpdated').textContent = new Date(orderData.updated_at || orderData.created_at).toLocaleDateString();
      
      // Calculate estimated delivery (3 days from order date)
      const deliveryDate = new Date(orderData.created_at);
      deliveryDate.setDate(deliveryDate.getDate() + 3);
      document.getElementById('poDelivery').textContent = deliveryDate.toLocaleDateString();
      
      document.getElementById('poTracking').textContent = 'TRK-' + orderData.reference_id;
      
      modal.classList.add('active');
    }

    // Print sales report
    function printSalesReport() {
      const printContent = document.getElementById('salesReportPrint');
      const originalContents = document.body.innerHTML;
      
      document.body.innerHTML = printContent.innerHTML;
      window.print();
      document.body.innerHTML = originalContents;
      location.reload();
    }

    // Print purchase order report
    function printPurchaseOrderReport() {
      const printContent = document.getElementById('purchaseOrderReportPrint');
      const originalContents = document.body.innerHTML;
      
      document.body.innerHTML = printContent.innerHTML;
      window.print();
      document.body.innerHTML = originalContents;
      location.reload();
    }

    // Download sales report as PDF (simplified version)
    function downloadSalesReport() {
      alert('PDF download functionality would be implemented here. For now, please use the print feature and save as PDF.');
    }

    // Download purchase order report as PDF
    function downloadPurchaseOrderReport() {
      alert('PDF download functionality would be implemented here. For now, please use the print feature and save as PDF.');
    }

    function filterOrders(status) {
      document.querySelectorAll('#orders-tab .order-row').forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    // Auto-submit chart filter form when selections change
    document.getElementById('chartFilter')?.addEventListener('change', function() {
      document.getElementById('chartFilterForm').submit();
    });

    document.getElementById('orderTypeFilter')?.addEventListener('change', function() {
      document.getElementById('chartFilterForm').submit();
    });

    // Add loading state to forms
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('submit', function() {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
          submitBtn.disabled = true;
        }
      });
    });
  </script>
</body>
</html>