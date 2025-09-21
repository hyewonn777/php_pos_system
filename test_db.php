<?php
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Show all PHP errors while debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ---------------- API MODE ---------------- */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    $products = [];
    $res = $conn->query("SELECT id, category, name, qty, price, image_path FROM stock ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($products);
    exit;
}

/* ------------------ BULK DELETE ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (!empty($_POST['selected']) && is_array($_POST['selected'])) {
        $ids = array_map('intval', $_POST['selected']); // sanitize ints
        $in = implode(',', $ids);

        if ($in !== '') {
            // delete images
            $imgQ = $conn->query("SELECT image_path FROM stock WHERE id IN ($in)");
            if ($imgQ) {
                while ($img = $imgQ->fetch_assoc()) {
                    $p = $img['image_path'];
                    if (!empty($p)) {
                        $fp = __DIR__ . '/' . $p;
                        if (file_exists($fp)) @unlink($fp);
                    }
                }
            }

            // delete rows
            if ($conn->query("DELETE FROM stock WHERE id IN ($in)")) {
                $_SESSION['flash'] = count($ids) . " product(s) deleted successfully!";
            } else {
                $_SESSION['flash'] = "Error deleting: " . $conn->error;
            }
        } else {
            $_SESSION['flash'] = "Invalid selection.";
        }
    } else {
        $_SESSION['flash'] = "Please select at least one product.";
    }

    header("Location: stock.php");
    exit;
}

/* ------------------ UPDATE PRODUCT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $id = intval($_POST['edit_id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['flash'] = "Invalid product id.";
        header("Location: stock.php"); exit;
    }

    $category = trim($_POST['category'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $qty      = intval($_POST['qty'] ?? 0);
    $price    = floatval($_POST['price'] ?? 0);

    // Get current image path
    $currentImg = '';
    $sel = $conn->prepare("SELECT image_path FROM stock WHERE id = ?");
    if ($sel) {
        $sel->bind_param("i", $id);
        $sel->execute();
        $sel->bind_result($currentImg);
        $sel->fetch();
        $sel->close();
    }

    // Handle image upload (optional)
    $dbImagePath = $currentImg;
    if (isset($_FILES['image']) && isset($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadsDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

        $originalName = basename($_FILES['image']['name']);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeBase = time() . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $fileName = $safeBase . ($ext ? '.' . $ext : '');
        $targetFile = $uploadsDir . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            // delete old image if present
            if (!empty($currentImg) && file_exists(__DIR__ . '/' . $currentImg)) {
                @unlink(__DIR__ . '/' . $currentImg);
            }
            $dbImagePath = 'uploads/' . $fileName;
        }
    }

    // Update row with prepared statement
    $stmt = $conn->prepare("UPDATE stock SET category = ?, name = ?, qty = ?, price = ?, image_path = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ssidsi", $category, $name, $qty, $price, $dbImagePath, $id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = "Product updated successfully!";
        } else {
            $_SESSION['flash'] = "Update error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = "DB prepare failed: " . $conn->error;
    }

    header("Location: stock.php");
    exit;
}

/* ------------------ ADD PRODUCT ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $category = trim($_POST['category'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $qty      = intval($_POST['qty'] ?? 0);
    $price    = floatval($_POST['price'] ?? 0);
    $dbPath   = '';

    if (isset($_FILES['image']) && isset($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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

    $stmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssids", $category, $name, $qty, $price, $dbPath);
        $stmt->execute();
        $stmt->close();
    } else {
        $_SESSION['flash'] = "DB prepare failed: " . $conn->error;
        header("Location: stock.php"); exit;
    }

    $_SESSION['flash'] = "Product added successfully!";
    header("Location: stock.php");
    exit;
}

/* ------------------ IMPORT CSV (robust) ------------------ */
if (isset($_POST['import_csv'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['csv_file']['error'] ?? 'no_file';
        $_SESSION['flash'] = "CSV upload failed (error code: {$err}).";
        header("Location: stock.php"); exit;
    }

    $tmp = $_FILES['csv_file']['tmp_name'];
    $contents = file_get_contents($tmp);
    if ($contents === false || trim($contents) === '') {
        $_SESSION['flash'] = "CSV file appears empty.";
        header("Location: stock.php"); exit;
    }

    // remove BOM
    $contents = preg_replace('/^\x{FEFF}/u', '', $contents);

    $firstLine = strtok($contents, "\n");
    $delim = ',';
    if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) $delim = ';';
    if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) $delim = "\t";

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $contents);
    rewind($fh);

    $header = fgetcsv($fh, 0, $delim);
    if ($header === false) {
        $_SESSION['flash'] = "CSV header could not be parsed.";
        fclose($fh);
        header("Location: stock.php"); exit;
    }

    $map = [];
    foreach ($header as $k => $v) {
        $h = strtolower(trim($v));
        $h = preg_replace('/^\x{FEFF}/u', '', $h);
        $h = preg_replace('/[^\p{L}\p{N}\s_]/u', '', $h);
        $h = str_replace([' ', '-', '/'], '_', $h);
        if ($h === 'category') $map['category'] = $k;
        if (in_array($h, ['name','product','product_name'])) $map['name'] = $k;
        if (in_array($h, ['price','price_per_unit','priceperunit'])) $map['price'] = $k;
        if (in_array($h, ['qty','quantity','stock'])) $map['qty'] = $k;
        if (in_array($h, ['image','image_path','imagepath'])) $map['image'] = $k;
    }

    if (!isset($map['category']) || !isset($map['name']) || !isset($map['price'])) {
        $_SESSION['flash'] = "CSV header must contain Category, Name/Product and Price. Found: " . implode(', ', $header);
        fclose($fh);
        header("Location: stock.php"); exit;
    }

    $stmt = $conn->prepare("INSERT INTO stock (category, name, qty, price, image_path) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        $_SESSION['flash'] = "DB prepare failed: " . $conn->error;
        fclose($fh);
        header("Location: stock.php"); exit;
    }

    $inserted = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $allEmpty = true;
        foreach ($row as $c) { if (trim($c) !== '') { $allEmpty = false; break; } }
        if ($allEmpty) continue;

        $category = trim($row[$map['category']] ?? '');
        $name     = trim($row[$map['name']] ?? '');
        $priceRaw = $row[$map['price']] ?? '0';
        $price = floatval(preg_replace('/[^\d\.\-]/', '', $priceRaw));
        $qty  = isset($map['qty']) ? intval(preg_replace('/[^\d\-]/','', $row[$map['qty']] ?? '0')) : 0;
        $image = isset($map['image']) ? trim($row[$map['image']] ?? '') : '';

        if ($category === '' || $name === '' || $price <= 0) continue;

        $stmt->bind_param("ssids", $category, $name, $qty, $price, $image);
        if ($stmt->execute()) $inserted++;
    }
    $stmt->close();
    fclose($fh);

    $_SESSION['flash'] = "CSV import finished. Rows inserted: {$inserted}";
    header("Location: stock.php");
    exit;
}

/* ------------------ EXPORT CSV ------------------ */
if (isset($_POST['export_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stock_export.csv');
    $output = fopen("php://output", "w");
    fputcsv($output, ['ID', 'Category', 'Name', 'Qty', 'Price', 'Image Path', 'Created At']);

    $result = $conn->query("SELECT id, category, name, qty, price, image_path, created_at FROM stock ORDER BY id ASC");
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'], $row['category'], $row['name'], $row['qty'],
            $row['price'], $row['image_path'], $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

/* ------------------ TEMPLATE ------------------ */
if (isset($_POST['generate_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stock_template.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Category','Product','Name','Price Per Unit','Quantity','Image']);
    fputcsv($out, ['Drinkware','Mug','Regular','150','100','uploads/mug.jpg']);
    fputcsv($out, ['Accessories & Small Items','Keychain','Keychain','50','100','uploads/keychain.jpg']);
    fputcsv($out, ['Apparel','Shirt','Tshirt','200','100','uploads/tshirt.jpg']);
    fclose($out);
    exit;
}

/* ------------------ READ ALL STOCK (for display) ------------------ */
$resAll = $conn->query("SELECT * FROM stock ORDER BY id ASC");
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

   /* Dark-mode friendly form controls */
input, select, textarea {
  background: var(--card-bg);
  color: var(--text);
  border: 1px solid var(--border, #555);
  border-radius: 6px;
  padding: 8px 10px;
  width: auto;
  transition: background .3s ease, color .3s ease, border .3s ease;
}

/* Placeholder styling */
input::placeholder,
textarea::placeholder {
  color: var(--text);
  opacity: 0.6;
}

/* Focus state */
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--accent, #3498db);
  box-shadow: 0 0 5px var(--accent, #3498db);
}

.hidden {
  display: none !important;
}
  </style>
</head>
<body>
  <div class="sidebar">
    <h2>Admin Panel</h2>
    <div class="logo-box"><img src="images/rsz_logo.png" alt="Logo"></div>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php">Sales Tracking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="stock.php">Inventory</a></li>
      <li><a href="appointment.php">Appointments</a></li>
      <li><a href="user_management.php">Account</a></li>
    </ul>
    <div style="margin-top:auto;">
      <form action="logout.php" method="POST"><button type="submit" style="width:100%; padding:8px; border-radius:6px; border:none; background:#e74c3c; color:#fff;">Logout</button></form>
    </div>
  </div>

  <div class="content">
    <div class="topbar" style="display:flex;justify-content:flex-end;margin-bottom:20px;">
      <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
      <button class="btn" onclick="toggleTheme()" style="background:#3498db;color:#fff">Toggle Dark Mode</button>
    </div>

    <h1>Product / Stock</h1>
    <p>Manage your product inventory below.</p>

        <div style="display:flex; gap:16px; margin-bottom:12px;">
      <div style="flex:1; display:flex; gap:12px; align-items:center;">
        <div class="card2" style="padding:12px; border-radius:8px; background:var(--card-bg); box-shadow:0 2px 6px rgba(0,0,0,0.04); font-weight:bold;">
          📊 Total Stock: <?php $r = $conn->query("SELECT COUNT(*) as c FROM stock"); $c = $r->fetch_assoc(); echo intval($c['c']); ?>
        </div>
      </div>
    </div>

    <div style="margin-bottom:16px;">
      <form id="productForm" enctype="multipart/form-data" method="POST" style="padding:12px; background:var(--card-bg); border-radius:8px;">
        <input type="text" name="category" placeholder="Category" required style="margin-right:8px;">
        <input type="text" name="name" placeholder="Product Name" required style="margin-right:8px;">
        <input type="number" name="qty" placeholder="0" min="0" value="0" required style="width:100px; margin-right:8px;">
        <input type="number" step="0.01" name="price" placeholder="Price" required style="width:120px; margin-right:8px;">
        <input type="file" name="image" accept="image/*" style="margin-right:8px;">
        <button type="submit" name="add_product" class="btn" style="background:#27ae60;color:#fff">Add Product</button>
      </form>
    </div>

    <div class="csv-tools" style="margin-bottom:12px; padding:12px; background:var(--card-bg); border-radius:8px;">
      <form action="stock.php" method="POST" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <input type="file" name="csv_file" accept=".csv" class="form-control">
        <button type="submit" name="import_csv" class="btn" style="background:#3498db; color:#fff;">📥 Import</button>
        <button type="submit" name="export_csv" class="btn" style="background:#2ecc71; color:#fff;">📤 Export</button>
        <button type="submit" name="generate_template" class="btn" style="background:#9b59b6; color:#fff;">📄 Template</button>
      </form>
      <div class="small">CSV File Only: <i>Category, Name, Quantity, Price, Image</i> <b><i>(NOTE: Quantity & Image are optional)</i></b></div>
    </div>

    <!-- bulk-delete button (separate form) -->
    <form id="bulkDeleteForm" method="POST" action="stock.php" style="display:inline-block;margin-bottom:8px;">
      <button type="submit" name="bulk_delete" class="btn" style="background:#e74c3c;color:#fff" onclick="return confirm('Delete selected?')">Delete Selected</button>
    </form>

    <table>
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>ID</th>
          <th>Category</th>
          <th>Image</th>
          <th>Product Name</th>
          <th>Price</th>
          <th>Qty</th>
          <th>Total</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $counter = 1;
        if ($resAll && $resAll->num_rows > 0):
            while ($row = $resAll->fetch_assoc()):
                $lowStock = ($row['qty'] < 5);
        ?>
        <tr <?= $lowStock ? 'style="background:#ffe6e6;"' : '' ?>>
          <td><input type="checkbox" name="selected[]" value="<?= $row['id'] ?>" form="bulkDeleteForm"></td>
          <td><?= $counter++ ?></td>
          <td><?= $row['id'] ?></td>
          <td><?= htmlspecialchars($row['category']) ?></td>
          <td><?php if (!empty($row['image_path'])): ?><img src="<?= htmlspecialchars($row['image_path']) ?>" class="thumb"><?php endif; ?></td>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td>₱<?= number_format((float)$row['price'],2) ?></td>
          <td><?= intval($row['qty']) ?> <?php if ($lowStock) echo '<span style="color:red;font-weight:bold">LOW STOCK</span>'; ?></td>
          <td>₱<?= number_format($row['price'] * $row['qty'],2) ?></td>
          <td>
            <button type="button" class="btn" onclick="toggleEditRow(<?= $row['id'] ?>)" style="background:#f1c40f">Edit</button>
          </td>
        </tr>

        <!-- inline edit row (hidden by default) -->
        <tr id="edit-row-<?= $row['id'] ?>" class="hidden edit-row">
          <td colspan="10">
            <form method="POST" enctype="multipart/form-data" action="stock.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <input type="hidden" name="edit_id" value="<?= $row['id'] ?>">
              <input name="category" value="<?= htmlspecialchars($row['category']) ?>" required>
              <input name="name" value="<?= htmlspecialchars($row['name']) ?>" required>
              <input type="number" name="qty" value="<?= intval($row['qty']) ?>" min="0" required style="width:100px;">
              <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($row['price']) ?>" required style="width:120px;">
              <input type="file" name="image" accept="image/*">
              <button type="submit" name="update_product" class="btn" style="background:#3498db;color:#fff">Save</button>
              <button type="button" class="btn" onclick="toggleEditRow(<?= $row['id'] ?>)">Cancel</button>
            </form>
          </td>
        </tr>

        <?php
            endwhile;
        else:
        ?>
        <tr>
          <td colspan="10" style="text-align:center;color:#999;">No products in stock.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div> <!-- content -->

<script>
  // Toggle edit row visibility by toggling the 'hidden' class
  function toggleEditRow(id) {
    const el = document.getElementById('edit-row-' + id);
    if (!el) return;
    el.classList.toggle('hidden');
    // optionally scroll into view when opening
    if (!el.classList.contains('hidden')) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  // Select/Deselect all checkboxes
  document.getElementById('selectAll').addEventListener('click', function(e) {
    document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = e.target.checked);
  });

  function toggleTheme() {
    document.body.classList.toggle("dark");
    localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
  }
  if (localStorage.getItem("theme") === "dark") {
    document.body.classList.add("dark");
  }

  function updateClock() {
    const now = new Date();
    const c = document.getElementById("clock");
    if (c) c.innerText = now.toLocaleDateString() + " " + now.toLocaleTimeString();
  }
  setInterval(updateClock, 1000); updateClock();
</script>
</body>
</html>
