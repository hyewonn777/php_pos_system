<?php
// Apply the patch - Admin session authentication
session_name('admin_session');
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Set current_role for any remaining references in the HTML
$current_role = 'admin'; // Since we're using admin session, all users are admins

require 'db.php';

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

/* ------------------ CSV EXPORT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    debug_log("CSV Export triggered");
    
    // Get all active products
    $result = $conn->query("SELECT * FROM stock WHERE status='active' ORDER BY id ASC");
    
    if ($result->num_rows > 0) {
        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventory_export_' . date('Y-m-d_H-i-s') . '.csv"');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // CSV headers
        fputcsv($output, ['ID', 'Category', 'Product Name', 'Quantity', 'Price', 'Image Path', 'Created At']);
        
        // Add data rows
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['category'],
                $row['name'],
                $row['qty'],
                $row['price'],
                $row['image_path'],
                $row['created_at']
            ]);
        }
        
        fclose($output);
        debug_log("CSV export completed successfully");
        exit;
    } else {
        $_SESSION['flash'] = ['message' => "No products found to export.", 'type' => 'warning'];
        debug_log("CSV export - no products found");
        header("Location: stock.php");
        exit;
    }
}

/* ------------------ CSV IMPORT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    debug_log("CSV Import triggered");
    
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileName = $_FILES['csv_file']['tmp_name'];
        
        if ($_FILES['csv_file']['size'] > 0) {
            $file = fopen($fileName, "r");
            
            // Skip BOM if exists
            $bom = fread($file, 3);
            if ($bom != "\xEF\xBB\xBF") {
                rewind($file);
            }
            
            // Read headers
            $headers = fgetcsv($file);
            
            $importCount = 0;
            $errorCount = 0;
            $errors = [];
            
            // Process each row
            while (($row = fgetcsv($file)) !== FALSE) {
                if (count($row) < 5) continue; // Skip invalid rows
                
                $id = isset($row[0]) ? trim($row[0]) : '';
                $category = isset($row[1]) ? trim($row[1]) : '';
                $name = isset($row[2]) ? trim($row[2]) : '';
                $qty = isset($row[3]) ? intval(trim($row[3])) : 0;
                $price = isset($row[4]) ? floatval(trim($row[4])) : 0;
                $image_path = isset($row[5]) ? trim($row[5]) : '';
                
                // Validate required fields
                if (empty($category) || empty($name) || $price <= 0) {
                    $errorCount++;
                    $errors[] = "Row " . ($importCount + $errorCount + 1) . ": Missing required fields";
                    continue;
                }
                
                // Check if product exists (by ID or name)
                $existingProduct = null;
                if (!empty($id)) {
                    $checkStmt = $conn->prepare("SELECT id FROM stock WHERE id = ? AND status='active'");
                    $checkStmt->bind_param("i", $id);
                    $checkStmt->execute();
                    $existingProduct = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();
                }
                
                if ($existingProduct) {
                    // Update existing product
                    $updateStmt = $conn->prepare("UPDATE stock SET category=?, name=?, qty=?, price=?, image_path=? WHERE id=?");
                    $updateStmt->bind_param("ssidsi", $category, $name, $qty, $price, $image_path, $id);
                    if ($updateStmt->execute()) {
                        $importCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Row " . ($importCount + $errorCount + 1) . ": " . $updateStmt->error;
                    }
                    $updateStmt->close();
                } else {
                    // Insert new product
                    $insertStmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path) VALUES (?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("ssids", $category, $name, $qty, $price, $image_path);
                    if ($insertStmt->execute()) {
                        $importCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Row " . ($importCount + $errorCount + 1) . ": " . $insertStmt->error;
                    }
                    $insertStmt->close();
                }
            }
            
            fclose($file);
            
            if ($importCount > 0) {
                $_SESSION['flash'] = [
                    'message' => "Successfully imported $importCount products." . ($errorCount > 0 ? " $errorCount errors occurred." : ""),
                    'type' => 'success'
                ];
                debug_log("CSV import completed: $importCount products imported, $errorCount errors");
            } else {
                $_SESSION['flash'] = [
                    'message' => "No products were imported. " . ($errorCount > 0 ? "Errors: " . implode(", ", array_slice($errors, 0, 3)) : ""),
                    'type' => 'error'
                ];
                debug_log("CSV import failed: No products imported");
            }
        } else {
            $_SESSION['flash'] = ['message' => "The uploaded file is empty.", 'type' => 'error'];
            debug_log("CSV import - empty file");
        }
    } else {
        $_SESSION['flash'] = ['message' => "Please select a valid CSV file to import.", 'type' => 'error'];
        debug_log("CSV import - no file selected");
    }
    
    header("Location: stock.php");
    exit;
}

/* ------------------ DOWNLOAD TEMPLATE ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_template'])) {
    debug_log("CSV Template download triggered");
    
    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_template.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // CSV headers with descriptions
    fputcsv($output, ['ID (Leave empty for new products)', 'Category *', 'Product Name *', 'Quantity', 'Price *', 'Image Path (Optional)', 'Created At (Auto-filled)']);
    
    // Add sample data
    fputcsv($output, ['', 'Electronics', 'Sample Product', '10', '99.99', 'uploads/sample.jpg', '']);
    fputcsv($output, ['', 'Clothing', 'T-Shirt', '25', '29.99', '', '']);
    
    fclose($output);
    debug_log("CSV template downloaded successfully");
    exit;
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

// Get username for welcome message - using admin session
$username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'Admin';
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 13) {
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
  <title>Inventory Management - Marcomedia POS</title>
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

    /* CSV Operations Card */
    .csv-card {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .csv-card:before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, var(--info) 0%, var(--primary-light) 100%);
    }

    .csv-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .csv-header {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 25px;
    }

    .csv-icon {
      width: 60px;
      height: 60px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      background: linear-gradient(135deg, rgba(72, 149, 239, 0.1) 0%, rgba(72, 149, 239, 0.05) 100%);
      color: var(--info);
      box-shadow: 0 4px 12px rgba(72, 149, 239, 0.15);
    }

    .csv-title {
      font-size: 22px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .csv-description {
      color: var(--text-muted);
      font-size: 14px;
    }

    .csv-actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 20px;
    }

    .csv-action-item {
      background: rgba(0, 0, 0, 0.02);
      border-radius: 12px;
      padding: 20px;
      border: 1px solid var(--border);
      transition: var(--transition);
    }

    .dark-mode .csv-action-item {
      background: rgba(255, 255, 255, 0.02);
    }

    .csv-action-item:hover {
      background: rgba(67, 97, 238, 0.03);
      transform: translateY(-3px);
    }

    .action-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 15px;
    }

    .action-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
    }

    .action-icon.export {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
    }

    .action-icon.import {
      background: linear-gradient(135deg, rgba(72, 149, 239, 0.1) 0%, rgba(72, 149, 239, 0.05) 100%);
      color: var(--info);
    }

    .action-icon.template {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%);
      color: var(--warning);
    }

    .action-icon.help {
      background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(139, 92, 246, 0.05) 100%);
      color: #8b5cf6;
    }

    .action-title {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .action-description {
      color: var(--text-muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .action-buttons {
      display: flex;
      gap: 10px;
      margin-top: 15px;
    }

    .csv-form {
      display: flex;
      gap: 10px;
      align-items: center;
      width: 100%;
    }

    /* Custom File Upload Styles */
    .file-upload-wrapper {
      position: relative;
      width: 100%;
    }

    .file-upload-input {
      position: absolute;
      left: 0;
      top: 0;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
      z-index: 2;
    }

    .file-upload-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 16px;
      background: linear-gradient(135deg, var(--info) 0%, #3b82f6 100%);
      color: white;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 4px 12px rgba(72, 149, 239, 0.3);
      width: 100%;
      text-align: center;
    }

    .file-upload-label:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(72, 149, 239, 0.4);
    }

    .file-name {
      margin-top: 8px;
      font-size: 12px;
      color: var(--text-muted);
      text-align: center;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .import-button {
      padding: 10px 16px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-family: inherit;
      font-size: 13px;
      background: linear-gradient(135deg, var(--success) 0%, #0d9c6d 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
      white-space: nowrap;
    }

    .import-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    }

    .import-button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* Form Container */
    .form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
      border: 1px solid var(--border);
    }

    .form-container:hover {
      box-shadow: var(--shadow-hover);
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

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text);
      font-size: 14px;
    }

    .form-control {
      padding: 14px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
      font-family: inherit;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    .btn {
      padding: 12px 18px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-family: inherit;
      font-size: 14px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(67, 97, 238, 0.4);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success) 0%, #0d9c6d 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-success:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.3);
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(230, 57, 70, 0.4);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
    }

    .btn-info {
      background: linear-gradient(135deg, var(--info) 0%, #3b82f6 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(72, 149, 239, 0.3);
    }

    .btn-info:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(72, 149, 239, 0.4);
    }

    .btn-sm {
      padding: 10px 16px;
      font-size: 13px;
      white-space: nowrap;
    }

    /* Table Container */
    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      overflow-x: auto;
      transition: var(--transition);
      border: 1px solid var(--border);
    }

    .table-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }

    .search-box {
      position: relative;
      width: 300px;
    }

    .search-box input {
      width: 100%;
      padding: 14px 16px 14px 44px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
      font-family: inherit;
    }

    .search-box input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
      outline: none;
    }

    .search-box i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
    }

    /* Table Styles */
    .table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1000px;
      transition: var(--transition);
    }

    .table th, .table td {
      padding: 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      transition: var(--transition);
    }

    .table th {
      color: var(--text-muted);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: rgba(0, 0, 0, 0.02);
      position: sticky;
      top: 0;
    }

    .dark-mode .table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .table tr {
      transition: var(--transition);
    }

    .table tr:hover {
      background: rgba(67, 97, 238, 0.03);
    }

    .checkbox-cell {
      width: 50px;
      text-align: center;
    }

    .product-image {
      width: 50px;
      height: 50px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid var(--border);
    }

    .low-stock {
      background: rgba(239, 68, 68, 0.05) !important;
    }

    .low-stock-badge {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      margin-left: 8px;
      box-shadow: 0 2px 6px rgba(230, 57, 70, 0.3);
    }

    .edit-row {
      background: rgba(67, 97, 238, 0.05);
    }

    .edit-form {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 16px;
      align-items: end;
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

    .flash-warning {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%);
      color: var(--warning);
      border-left: 4px solid var(--warning);
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

    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 2000;
      align-items: center;
      justify-content: center;
    }

    .modal.active {
      display: flex;
    }

    .modal-content {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 30px;
      box-shadow: var(--shadow-hover);
      max-width: 500px;
      width: 90%;
      max-height: 80vh;
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
      transition: var(--transition);
    }

    .modal-close:hover {
      color: var(--danger);
    }

    .modal-body {
      margin-bottom: 25px;
    }

    .modal-footer {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }

    .instructions {
      background: rgba(67, 97, 238, 0.05);
      border-radius: 10px;
      padding: 20px;
      margin-bottom: 20px;
    }

    .instructions h4 {
      margin-bottom: 10px;
      color: var(--primary);
    }

    .instructions ul {
      padding-left: 20px;
    }

    .instructions li {
      margin-bottom: 8px;
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
      
      .table-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      
      .search-box {
        width: 100%;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .csv-actions {
        grid-template-columns: 1fr;
      }
      
      .csv-form {
        flex-direction: column;
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
      
      .action-buttons {
        flex-direction: column;
      }
      
      .btn-sm {
        width: 100%;
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

    .hidden {
      display: none !important;
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
          <h1>Inventory Management</h1>
          <p>Manage your product inventory and stock levels</p>
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
        <div class="flash-message flash-<?= $_SESSION['flash']['type'] === 'error' ? 'error' : ($_SESSION['flash']['type'] === 'warning' ? 'warning' : 'success') ?>">
          <i class="fas fa-<?php echo $_SESSION['flash']['type'] === 'error' ? 'exclamation-triangle' : ($_SESSION['flash']['type'] === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- CSV Operations Card -->
      <div class="csv-card">
        <div class="csv-header">
          <div class="csv-icon">
            <i class="fas fa-file-csv"></i>
          </div>
          <div>
            <div class="csv-title">Data Management</div>
            <div class="csv-description">Export, import, and manage your inventory data using CSV files</div>
          </div>
        </div>
        
        <div class="csv-actions">
          <!-- Export Card -->
          <div class="csv-action-item">
            <div class="action-header">
              <div class="action-icon export">
                <i class="fas fa-file-export"></i>
              </div>
              <div>
                <div class="action-title">Export to CSV</div>
                <div class="action-description">Download your current inventory as a CSV file for backup or analysis</div>
              </div>
            </div>
            <div class="action-buttons">
              <form method="POST" action="stock.php" class="csv-form">
                <button type="submit" name="export_csv" class="btn btn-success btn-sm">
                  <i class="fas fa-download"></i> Export Data
                </button>
              </form>
            </div>
          </div>

          <!-- Import Card -->
          <div class="csv-action-item">
            <div class="action-header">
              <div class="action-icon import">
                <i class="fas fa-file-import"></i>
              </div>
              <div>
                <div class="action-title">Import from CSV</div>
                <div class="action-description">Upload a CSV file to add or update products in bulk</div>
              </div>
            </div>
            <div class="action-buttons">
              <form method="POST" action="stock.php" enctype="multipart/form-data" class="csv-form" id="importForm">
                <div class="file-upload-wrapper">
                  <input type="file" name="csv_file" accept=".csv" class="file-upload-input" id="csvFileInput" required>
                  <label for="csvFileInput" class="file-upload-label">
                    <i class="fas fa-file-upload"></i> Choose CSV File
                  </label>
                  <div class="file-name" id="fileName">No file chosen</div>
                </div>
                <button type="submit" name="import_csv" class="import-button" id="importButton">
                  <i class="fas fa-upload"></i> Import
                </button>
              </form>
            </div>
          </div>

          <!-- Template Card -->
          <div class="csv-action-item">
            <div class="action-header">
              <div class="action-icon template">
                <i class="fas fa-file-download"></i>
              </div>
              <div>
                <div class="action-title">Download Template</div>
                <div class="action-description">Get a pre-formatted CSV template to ensure proper import formatting</div>
              </div>
            </div>
            <div class="action-buttons">
              <form method="POST" action="stock.php" class="csv-form">
                <button type="submit" name="download_template" class="btn btn-warning btn-sm">
                  <i class="fas fa-file-code"></i> Get Template
                </button>
              </form>
            </div>
          </div>

          <!-- Help Card -->
          <div class="csv-action-item">
            <div class="action-header">
              <div class="action-icon help">
                <i class="fas fa-question-circle"></i>
              </div>
              <div>
                <div class="action-title">Import Instructions</div>
                <div class="action-description">Learn about CSV format requirements and best practices for imports</div>
              </div>
            </div>
            <div class="action-buttons">
              <button type="button" class="btn btn-primary btn-sm" onclick="showImportInstructions()">
                <i class="fas fa-info-circle"></i> View Guide
              </button>
            </div>
          </div>
        </div>
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
        <div class="section-header">
          <div class="section-title">Add New Product</div>
        </div>
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
                      <div style="width:50px;height:50px;background:var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);">
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
                            Current: <?= htmlspecialchars(basename($row['image_path'])) ?>
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

  <!-- Import Instructions Modal -->
  <div class="modal" id="importModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">CSV Import Instructions</h3>
        <button type="button" class="modal-close" onclick="hideImportInstructions()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="instructions">
          <h4>CSV Format Requirements:</h4>
          <ul>
            <li>File must be in CSV (Comma Separated Values) format</li>
            <li>Required columns: Category, Product Name, Price</li>
            <li>Optional columns: ID, Quantity, Image Path</li>
            <li>Price must be a positive number</li>
            <li>Quantity must be a whole number (default: 0)</li>
          </ul>
          
          <h4>Column Order:</h4>
          <ol>
            <li><strong>ID</strong> - Leave empty for new products, include to update existing</li>
            <li><strong>Category</strong> * - Product category (required)</li>
            <li><strong>Product Name</strong> * - Name of the product (required)</li>
            <li><strong>Quantity</strong> - Stock quantity (default: 0)</li>
            <li><strong>Price</strong> * - Product price (required, must be > 0)</li>
            <li><strong>Image Path</strong> - Path to product image (optional)</li>
            <li><strong>Created At</strong> - Auto-filled, leave empty</li>
          </ol>
          
          <p><strong>Tip:</strong> Download the template first to ensure proper formatting!</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="hideImportInstructions()">Got it!</button>
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
      const transitionElements = document.querySelectorAll('body, .stat-card, .form-container, .table-container, .btn, .form-control, .table, .table th, .table td');
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

      // Add import form validation
      setupImportForm();
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

    // Import Form Validation
    function setupImportForm() {
      const importForm = document.getElementById('importForm');
      const csvFileInput = document.getElementById('csvFileInput');
      const fileName = document.getElementById('fileName');
      const importButton = document.getElementById('importButton');
      
      if (csvFileInput && fileName) {
        csvFileInput.addEventListener('change', function() {
          if (this.files.length > 0) {
            fileName.textContent = this.files[0].name;
          } else {
            fileName.textContent = 'No file chosen';
          }
        });
      }
      
      if (importForm && csvFileInput && importButton) {
        importForm.addEventListener('submit', function(e) {
          if (!csvFileInput.value) {
            e.preventDefault();
            alert('Please select a CSV file to import.');
            return false;
          }
          
          const file = csvFileInput.files[0];
          const fileName = file.name.toLowerCase();
          
          if (!fileName.endsWith('.csv')) {
            e.preventDefault();
            alert('Please select a valid CSV file.');
            return false;
          }
          
          // Show loading state
          importButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
          importButton.disabled = true;
          
          return true;
        });
      }
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

    // Import Instructions Modal
    function showImportInstructions() {
      document.getElementById('importModal').classList.add('active');
    }

    function hideImportInstructions() {
      document.getElementById('importModal').classList.remove('active');
    }

    // Close modal when clicking outside
    document.getElementById('importModal').addEventListener('click', function(e) {
      if (e.target === this) {
        hideImportInstructions();
      }
    });

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
      console.log('Inventory page loaded successfully');
    });
  </script>
</body>
</html>