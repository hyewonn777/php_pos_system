<?php
/* stock.php - updated: keep style, remove delete-all, fix errors */
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // UPDATE (edit)
    if (isset($_POST['update_product']) && isset($_POST['edit_id'])) {
        $id = intval($_POST['edit_id']);
        $category = trim($_POST['category']);
        $name     = trim($_POST['name']);
        $qty      = intval($_POST['qty']);
        $price    = floatval($_POST['price']);

        // get current image_path
        $dbPath = '';
        $res = $conn->prepare("SELECT image_path FROM stock WHERE id = ?");
        if ($res) {
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($dbPathFromDb);
            $res->fetch();
            $res->close();
            if (!empty($dbPathFromDb)) $dbPath = $dbPathFromDb;
        }

        // optional image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadsDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

            $originalName = basename($_FILES['image']['name']);
            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $safeBase = time() . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $fileName = $safeBase . ($ext ? '.' . $ext : '');
            $targetFile = $uploadsDir . $fileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                // remove old image if exists
                if (!empty($dbPath) && file_exists(__DIR__ . '/' . $dbPath)) {
                    @unlink(__DIR__ . '/' . $dbPath);
                }
                $dbPath = 'uploads/' . $fileName;
            }
        }

        $stmt = $conn->prepare("UPDATE stock SET category=?, name=?, qty=?, price=?, image_path=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssidsi", $category, $name, $qty, $price, $dbPath, $id);
            if ($stmt->execute()) {
                $_SESSION['flash'] = "Product updated successfully!";
            } else {
                $_SESSION['flash'] = "Update error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['flash'] = "Update prepare failed: " . $conn->error;
        }

        header("Location: stock.php");
        exit;
    }

    // BULK DELETE
if (isset($_POST['bulk_delete']) && !empty($_POST['selected'])) {
    $ids = array_map('intval', $_POST['selected']);
    $in  = implode(',', $ids);

    // remove images first
    $imgQ = $conn->query("SELECT image_path FROM stock WHERE id IN ($in)");
    while ($img = $imgQ->fetch_assoc()) {
        if (!empty($img['image_path']) && file_exists(__DIR__ . '/' . $img['image_path'])) {
            @unlink(__DIR__ . '/' . $img['image_path']);
        }
    }

    if ($conn->query("DELETE FROM stock WHERE id IN ($in)")) {
        $_SESSION['flash'] = "Selected products deleted successfully!";
    } else {
        $_SESSION['flash'] = "Error deleting selected: " . $conn->error;
    }

    header("Location: stock.php");
    exit;
}


    // ADD product
    if (isset($_POST['add_product'])) {
        if (isset($_POST['category'], $_POST['name'], $_POST['qty'], $_POST['price'])) {

            $category = trim($_POST['category']);
            $name     = trim($_POST['name']);
            $qty      = intval($_POST['qty']);
            $price    = floatval($_POST['price']);
            $dbPath   = '';

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadsDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

                $originalName = basename($_FILES['image']['name']);
                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $safeBase = time() . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                $fileName = $safeBase . ($ext ? '.' . $ext : '');
                $targetFile = $uploadsDir . $fileName;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $dbPath = 'uploads/' . $fileName;
                }
            }

            $stmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("ssids", $category, $name, $qty, $price, $dbPath);
                if ($stmt->execute()) {
                    $_SESSION['flash'] = "Product added successfully!";
                } else {
                    $_SESSION['flash'] = "Error inserting product: " . $stmt->error;
                    if (!empty($dbPath) && file_exists(__DIR__ . '/' . $dbPath)) @unlink(__DIR__ . '/' . $dbPath);
                }
                $stmt->close();
            } else {
                $_SESSION['flash'] = "Insert prepare failed: " . $conn->error;
            }
        } else {
            $_SESSION['flash'] = "Missing required fields for add.";
        }

        header("Location: stock.php");
        exit;
    }

    // IMPORT CSV
    if (isset($_POST['import_csv']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($tmp, 'r')) !== false) {
            $header = fgetcsv($handle);
            if ($header === false) {
                $_SESSION['flash'] = "CSV file appears empty.";
                fclose($handle);
                header("Location: stock.php"); exit;
            }

            // normalize header names -> map to indices (accept qty or quantity)
            $map = [];
            foreach ($header as $i => $h) {
                $hn = strtolower(trim($h));
                $map[$hn] = $i;
            }

            // require category and name and price (qty optional)
            if (!isset($map['category']) || !isset($map['name']) || !isset($map['price'])) {
                $_SESSION['flash'] = "CSV header must contain: category, name, price (qty/image optional).";
                fclose($handle);
                header("Location: stock.php"); exit;
            }

            $inserted = 0;
            $stmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                $_SESSION['flash'] = "Prepare failed: " . $conn->error;
                fclose($handle);
                header("Location: stock.php"); exit;
            }

            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                if (count($row) === 1 && trim($row[0]) === '') continue;

                $category = trim($row[$map['category']] ?? '');
                $name     = trim($row[$map['name']] ?? '');
                $price    = floatval($row[$map['price']] ?? 0);
                // qty: prefer 'qty' then 'quantity' header
                if (isset($map['qty'])) {
                    $qty = intval($row[$map['qty']] ?? 0);
                } elseif (isset($map['quantity'])) {
                    $qty = intval($row[$map['quantity']] ?? 0);
                } else {
                    $qty = 0;
                }
                $image    = '';
                if (isset($map['image'])) $image = trim($row[$map['image']] ?? '');

                if ($category === '' && $name === '') continue;

                $stmt->bind_param("ssids", $category, $name, $qty, $price, $image);
                if ($stmt->execute()) $inserted++;
            }
            $stmt->close();
            fclose($handle);
            $_SESSION['flash'] = "CSV import finished. Rows inserted: {$inserted}";
        } else {
            $_SESSION['flash'] = "Failed to open CSV file.";
        }
        header("Location: stock.php");
        exit;
    }

    // EXPORT CSV
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=stock_export.csv');
        $output = fopen("php://output", "w");
        fputcsv($output, ['ID', 'Category', 'Name', 'Qty', 'Price', 'Created At']);

        $result = $conn->query("SELECT id, category, name, qty, price, created_at FROM stock ORDER BY id ASC");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['category'],
                $row['name'],
                $row['qty'],
                $row['price'],
                $row['created_at']
            ]);
        }
        fclose($output);
        exit;
    }

    // GENERATE TEMPLATE
    if (isset($_POST['generate_template'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=stock_template.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Category', 'Name', 'Qty', 'Price', 'Image']);
        // sample rows
        fputcsv($out, ['Beverage', 'Coke', '10', '20.00', 'uploads/sample.jpg']);
        fputcsv($out, ['Snack', 'Chips', '15', '15.00', 'uploads/sample2.jpg']);
        fclose($out);
        exit;
    }
} // end POST

// READ ALL
$result = $conn->query("SELECT * FROM stock ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Stock / Product Management</title>
  <link rel="stylesheet" href="css/admin.css">
  <style>
    :root {
      --bg: #f9f9f9;
      --text: #222;
      --card-bg: #fff;
      --sidebar-bg: #2c3e50;
      --sidebar-text: #ecf0f1;
    }

    .dark { 
        --bg:#1e1e1e; 
        --text:#f5f5f5; 
        --card-bg:#2c2c2c; 
        --sidebar-bg:#111; 
        --sidebar-text:#bbb; 
    }
   
    body{ 
        margin:0; 
        font-family:Arial, sans-serif; 
        background:var(--bg); 
        color:var(--text); 
        display:flex; 
        transition:all .3s ease; 
        width: 100%
    }
    
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
    
    .content { 
        flex:1; padding:20px; }
    
    table { 
        border-collapse:collapse; 
        width:100%; 
        margin-top:16px; 
    }
    
    th, td { 
        border:1px solid #e3e3e3; 
        padding:10px; 
        text-align:left; 
        vertical-align:middle; 
    }
    
    th { 
        background:#00000000; 
    }
    
    img.thumb { 
        width:50px; 
        height:50px; 
        object-fit:cover; 
        border-radius:4px; 
    }
    
    .actions { 
        display:flex; 
        gap:8px; 
        align-items:center; 
    }
    
    .btn { 
        padding:6px 10px; 
        border-radius:6px; 
        border:none; 
        cursor:pointer; 
    }
    
    .btn.edit { 
        background:#3498db; 
        color:#fff; 
    }
    
    .btn.delete { 
      background:#e74c3c; 
      color:#fff; 
    }

      button.toggle-btn {
      cursor: pointer;
      padding: 8px 12px;
      border-radius: 5px;
      border: none;
      background: #3498db;
      color: white;
      font-weight: bold;
    }

    .edit-row { 
        background:#fbfbfb; 
    }

    .form-inline input[type="text"], .form-inline input[type="number"] { 
        padding:6px; margin-right:6px; 
    }
    
    .csv-tools { 
        margin-top:12px; 
        padding:12px; 
        background:var(--card-bg); 
        border-radius:8px; 
        box-shadow:0 2px 6px rgba(0,0,0,0.05); 
        display:flex; gap:12px; 
        align-items:center; 
        flex-wrap:wrap; 
    }
    
    .small { 
        font-size:13px; 
        color:#666; 
        margin-top:8px; 
    }
    
    .topbar {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 20px;
    }

     #stockTableForm table {
    width: 100% !important;
    table-layout: auto;
  }

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
      <li><a href="sales.php">Sales & Tracking</a></li>
      <li><a href="stock.php">Product / Stock</a></li>
      <li><a href="appointment.php">Appointments / Booking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="user_management.php">Account Management</a></li>
    </ul>
    <div style="margin-top:auto;">
      <form action="logout.php" method="POST"><button type="submit" style="width:100%; padding:8px; border-radius:6px; border:none; background:#e74c3c; color:#fff;">Logout</button></form>
    </div>
  </div>

  <div class="content">
  <div class="topbar">
    <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
    <button class="toggle-btn" onclick="toggleTheme()">🌙 Toggle Dark Mode</button>
   </div>
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h1>Product / Stock</h1>
      <div id="clock" style="font-weight:bold;"></div>
    </div>

    <p>Manage your product inventory below.</p>

    <div style="display:flex; gap:16px; margin-bottom:12px;">
      <div style="flex:1; display:flex; gap:12px; align-items:center;">
        <div class="card2" style="padding:12px; border-radius:8px; background:var(--card-bg); box-shadow:0 2px 6px rgba(0,0,0,0.04); font-weight:bold;">
          📊 Total Stock: <?php $r = $conn->query("SELECT COUNT(*) as c FROM stock"); $c = $r->fetch_assoc(); echo intval($c['c']); ?>
        </div>
      </div>
    </div>

<!-- Add Product + CSV + Bulk Delete -->
<div style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:8px;">

 <!-- Add Product + Bulk Delete -->
<form id="productForm" enctype="multipart/form-data" method="POST" 
      style="padding:12px; background:#fff; border:1px solid #e0e0e0; border-radius:8px; margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
  
  <input type="text" name="category" placeholder="Category" required class="form-control" style="flex:1;">
  <input type="text" name="name" placeholder="Product Name" required class="form-control" style="flex:1;">
  <input type="number" name="qty" placeholder="0" min="0" value="0" required class="form-control" style="width:100px;">
  <input type="number" step="0.01" name="price" placeholder="Price" required class="form-control" style="width:120px;">
  <input type="file" name="image" accept="image/*" class="form-control">

  <!-- Add Product -->
  <button type="submit" name="add_product" class="btn" style="background:#27ae60; color:#fff;">Add Product 📑➕</button>

  <!-- Bulk Delete -->
  <button type="submit" name="bulk_delete" form="stockTableForm" 
          onclick="return confirm('Are you sure you want to delete selected products?');" 
          class="btn" style="background:#e74c3c; color:#fff;">🗑 Delete Selected</button>
</form>

<!-- CSV Tools -->
<div class="csv-tools" style="padding:12px; background:#fff; border:1px solid #e0e0e0; border-radius:8px; margin-bottom:16px;">
  <form action="stock.php" method="POST" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <input type="file" name="csv_file" accept=".csv" class="form-control">
    <button type="submit" name="import_csv" class="btn" style="background:#3498db; color:#fff;">📥 Import</button>
    <button type="submit" name="export_csv" class="btn" style="background:#2ecc71; color:#fff;">📤 Export</button>
    <button type="submit" name="generate_template" class="btn" style="background:#9b59b6; color:#fff;">📄 Template</button>
  </form>
  <div class="small" style="margin-top:6px;">
    CSV File Only: <i>Category, Name, Quantity, Price, Image</i> 
    <b><i>(NOTE: Quantity & Image are optional)</i></b>
  </div>
</div>

<!-- Stock Table -->
<div class="container-fluid" style="width:100%"">
<form method="POST" id="stockTableForm">
  <table class="table table-striped table-bordered">
    <thead>
      <tr>
        <th><input type="checkbox" id="selectAll"></th>
        <th>ID</th>
        <th>Category</th>
        <th>Product Name</th>
        <th>Price</th>
        <th>Quantity</th>
        <th>Image</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $counter = 1;
      $result = $conn->query("SELECT * FROM stock ORDER BY id ASC");
      while ($row = $result->fetch_assoc()):
      ?>
      <tr>
        <td><input type="checkbox" name="selected[]" value="<?= $row['id'] ?>"></td>
        <td><?= $counter++; ?></td>
        <td><?= htmlspecialchars($row['category']) ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td>₱<?= number_format((float)$row['price'],2) ?></td>
        <td><?= intval($row['qty']) ?></td>
        <td>
          <?php if (!empty($row['image_path'])): ?>
            <img src="<?= htmlspecialchars($row['image_path']) ?>" width="50">
          <?php endif; ?>
        </td>
        <td>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-warning" type="button" onclick="toggleEdit(<?= $row['id'] ?>)">Edit</button>

            <!-- Per-row delete -->
            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <input type="hidden" name="delete_product" value="1">
              <!-- <button class="btn btn-sm btn-danger" type="submit">Delete</button> -->
            </form>
          </div>
        </td>
      </tr>

      <!-- hidden edit row -->
      <tr id="edit-row-<?= $row['id'] ?>" class="edit-row" style="display:none;">
        <td colspan="8">
          <form method="POST" enctype="multipart/form-data" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="edit_id" value="<?= $row['id'] ?>">
            <input type="text" name="category" value="<?= htmlspecialchars($row['category']) ?>" required>
            <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required>
            <input type="number" name="qty" value="<?= intval($row['qty']) ?>" min="0" required>
            <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($row['price']) ?>" required>
            <input type="file" name="image" accept="image/*">
            <button type="submit" name="update_product" class="btn btn-primary btn-sm">💾 Save</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?= $row['id'] ?>)">❌ Cancel</button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</form>

<script>
  // toggle edit rows
  function toggleEdit(id) {
    const el = document.getElementById('edit-row-' + id);
    if (!el) return;
    el.style.display = el.style.display === 'table-row' ? 'none' : 'table-row';
  }

  // select all checkboxes
  document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = this.checked);
  });
</script>



</body>
</html>
