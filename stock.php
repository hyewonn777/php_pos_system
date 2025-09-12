<?php
/* (replac existing file with this)

// make sure session is available for flash messages (auth.php may start it already)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}*/
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// === POST handling (do this before any HTML output) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Delete product (highest priority)
    if (isset($_POST['delete_id'])) {
        $delete_id = intval($_POST['delete_id']);

        // find image path (if any) so we can unlink it
        $stmt = $conn->prepare("SELECT image_path FROM stock WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->bind_result($imagePath);
            $stmt->fetch();
            $stmt->close();
        }

        // remove image file (if exists)
        if (!empty($imagePath)) {
            $fullPath = __DIR__ . '/' . $imagePath;
            if (file_exists($fullPath)) @unlink($fullPath);
        }

        // delete DB record
        $stmt = $conn->prepare("DELETE FROM stock WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            if ($stmt->execute()) {
                $_SESSION['flash'] = "Product deleted successfully!";
            } else {
                $_SESSION['flash'] = "Error deleting product: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['flash'] = "Delete prepare failed: " . $conn->error;
        }

        header("Location: stock.php");
        exit;
    }

    // 2) Add product (image upload)
    if (isset($_POST['add_product'])) {
    if (isset($_POST['category'], $_POST['name'], $_POST['price']) &&
        isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        
        $category = trim($_POST['category']);
        $name     = trim($_POST['name']);
        $price    = floatval($_POST['price']);

        $uploadsDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

        $originalName = basename($_FILES['image']['name']);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = time() . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $base);
        $fileName = $safeBase . ($ext ? '.' . $ext : '');
        $targetFile = $uploadsDir . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $dbPath = 'uploads/' . $fileName;
            $stmt = $conn->prepare("INSERT INTO stock (category, name, price, image_path, created_at) VALUES (?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("ssds", $category, $name, $price, $dbPath);
                if ($stmt->execute()) {
                    $_SESSION['flash'] = "Product added successfully!";
                } else {
                    $_SESSION['flash'] = "Error inserting product: " . $stmt->error;
                    if (file_exists($targetFile)) @unlink($targetFile);
                }
                $stmt->close();
            } else {
                $_SESSION['flash'] = "Insert prepare failed: " . $conn->error;
                if (file_exists($targetFile)) @unlink($targetFile);
            }
         } else {
        $_SESSION['flash'] = "Missing required fields or image upload error.";
        header("Location: stock.php");
        exit;
            }
        }
    }

    // 3) Import CSV (expects header row: Category,Name,Price,Image)
    if (isset($_POST['import_csv']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($tmp, 'r')) !== false) {
            // read header and map columns (resilient to column ordering / extra columns)
            $header = fgetcsv($handle);
            if ($header === false) {
                $_SESSION['flash'] = "CSV file appears empty.";
                fclose($handle);
                header("Location: stock.php"); exit;
            }
            // normalize header names
            $map = [];
            foreach ($header as $i => $h) $map[strtolower(trim($h))] = $i;

            // require at least category,name,price columns
            if (!isset($map['category']) || !isset($map['name']) || !isset($map['price'])) {
                $_SESSION['flash'] = "CSV header must contain: category, name, price (image optional).";
                fclose($handle);
                header("Location: stock.php"); exit;
            }

            $inserted = 0;
            // prepare statement once
            $stmt = $conn->prepare("INSERT INTO stock (category, name, price, image_path, created_at) VALUES (?, ?, ?, ?, NOW())");
            if (!$stmt) {
                $_SESSION['flash'] = "Prepare failed: " . $conn->error;
                fclose($handle);
                header("Location: stock.php"); exit;
            }

            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                // skip empty rows
                if (count($row) === 1 && trim($row[0]) === '') continue;

                $category = isset($map['category']) ? trim($row[$map['category']] ?? '') : '';
                $name     = isset($map['name']) ? trim($row[$map['name']] ?? '') : '';
                $price    = isset($map['price']) ? floatval($row[$map['price']] ?? 0) : 0.0;
                $image    = isset($map['image']) ? trim($row[$map['image']] ?? '') : '';

                if ($category === '' && $name === '') continue; // skip useless rows

                $stmt->bind_param("ssds", $category, $name, $price, $image);
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

    // EXPORT TO CSV
if (isset($_POST['export_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stock_export.csv');

    $output = fopen("php://output", "w");
    // Column headers
    fputcsv($output, ['ID', 'Product Name', 'Qty', 'Price', 'Created At']);

    $result = $conn->query("SELECT id, product_name, quantity, price, created_at FROM stock");
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

    // 4) Generate CSV Template
if (isset($_POST['generate_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stock_template.csv');
    $out = fopen('php://output', 'w');

    // header with ID + Created
    fputcsv($out, ['ID', 'Category', 'Name', 'Price', 'Image', 'Created']);

    // sample rows
    fputcsv($out, [1, 'Beverage', 'Coke', '20.00', 'uploads/sample.jpg', date('Y-m-d H:i:s')]);
    fputcsv($out, [2, 'Snack', 'Chips', '15.00', 'uploads/sample2.jpg', date('Y-m-d H:i:s')]);

    fclose($out);
    exit;
}


    // fallback
    $_SESSION['flash'] = "Invalid request. Please ensure all fields are filled and correct action selected.";
    header("Location: stock.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Stock / Product Management</title>
  <link rel="stylesheet" href="css/admin.css">
  <style>
    /* existing theme variables */
    :root {
      --bg: #f9f9f9;
      --text: #222;
      --card-bg: #fff;
      --sidebar-bg: #2c3e50;
      --sidebar-text: #ecf0f1;
    }
    .dark {
      --bg: #1e1e1e;
      --text: #f5f5f5;
      --card-bg: #2c2c2c;
      --sidebar-bg: #111;
      --sidebar-text: #bbb;
    }
    body {
      margin: 0; font-family: Arial, sans-serif;
      background: var(--bg); color: var(--text);
      display: flex; transition: all 0.3s ease;
    }
    .sidebar {
      width: 220px; background: var(--sidebar-bg); color: var(--sidebar-text);
      height: 100vh; padding: 20px; display: flex; flex-direction: column;
    }
    .sidebar h2 { text-align: center; margin-bottom: 20px; }
    .sidebar ul { list-style: none; padding: 0; flex: 1; }
    .sidebar ul li { margin: 15px 0; }
    .sidebar ul li a { color: var(--sidebar-text); text-decoration: none; }
    .sidebar ul li a:hover { text-decoration: underline; }
    .logout { text-align: center; margin-top: auto; }
    .logout button {
      width: 100%; padding: 8px; border: none; border-radius: 5px;
      background: #e74c3c; color: white; font-weight: bold; cursor: pointer;
    }
    .logout button:hover { background: #c0392b; }

    .content { flex: 1; padding: 20px; }
    .topbar { display: flex; justify-content: flex-end; margin-bottom: 20px; }
    .cards {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px; margin-top: 20px;
    }
    .card {
      background: var(--card-bg); padding: 20px; border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center; font-size: 18px; font-weight: bold;
      transition: transform 0.2s ease; cursor: pointer;
    }
    .card:hover { transform: translateY(-3px); }
    button.toggle-btn {
      cursor: pointer; padding: 8px 12px; border-radius: 5px;
      border: none; background: #3498db; color: white; font-weight: bold;
    }
    button.toggle-btn:hover { background: #2980b9; }

    /* Product form */
    #productForm {
      display: none;
      margin-top: 20px;
      padding: 20px;
      background: var(--card-bg);
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    #productForm input {
      display: block; margin: 10px 0; padding: 8px;
      width: 100%; border: 1px solid #ccc; border-radius: 5px;
    }
    #productForm button {
      padding: 10px 15px; background: #27ae60; color: white;
      border: none; border-radius: 5px; cursor: pointer;
    }
    #productForm button:hover { background: #219150; }

    table img { object-fit: cover; height: 50px; width: 50px; border-radius:4px; }
  </style>
</head>
<body>
  <div class="sidebar">
    <h2>Admin Panel</h2>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php">Sales & Tracking</a></li>
      <li><a href="stock.php">Product / Stock</a></li>
      <li><a href="appointment.php">Appointments / Booking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
    </ul>
    <div class="logout">
      <form action="logout.php" method="POST">
        <button type="submit">Logout</button>
      </form>
    </div>
  </div>

  <div class="content">
    <div class="topbar">
    <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
    <button class="toggle-btn" onclick="toggleTheme()">🌙 Toggle Dark Mode</button>
   </div>

    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h1>Product / Stock</h1>
      <div class="topbar" style="margin-top:20px;">
    </div>
    </div>
    <p>Manage your product inventory below.</p>
    <div class="cards">
      <div class="card" onclick="toggleForm()">📦 Add / Update Products</div>
      <div class="card">🔍 Search Products</div>
      <div class="card">📊 Total Stock:
        <?php
          $res = $conn->query("SELECT COUNT(*) as c FROM stock");
          $row = $res->fetch_assoc();
          echo intval($row['c']);
        ?>
      </div>
    </div>

    <!-- Add Product Form -->
    <form id="productForm" enctype="multipart/form-data" method="POST">
     <h3>Add Product</h3>
     <input type="text" name="category" placeholder="Category" required>
     <input type="text" name="name" placeholder="Product Name" required>
     <input type="number" step="0.01" name="price" placeholder="Price" required>
     <input type="file" name="image" accept="image/*" required>
     <button type="submit" name="add_product">Add Product</button>
    </form>

    <div style="display:flex; gap:15px; align-items:center; margin-bottom:20px;">

<!-- CSV Tools Section -->
<div style="margin:20px 0; padding:15px; background:var(--card-bg); border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.08);">

  <h3 style="margin-bottom:12px;">📑 CSV Tools</h3>

  <!-- Import / Export Form -->
  <form action="stock.php" method="POST" enctype="multipart/form-data" 
        style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">

    <!-- File Input -->
    <input type="file" name="csv_file" accept=".csv" 
           style="flex:1; min-width:200px; padding:6px; border:1px solid #ccc; border-radius:6px;">

    <!-- Import Button -->
    <button type="submit" name="import_csv" 
            style="padding:8px 14px; background:#3498db; color:#fff; border:none; border-radius:6px; cursor:pointer;">
      📥 Import
    </button>

    <!-- Export Button -->
    <button type="submit" name="export_csv" 
            style="padding:8px 14px; background:#2ecc71; color:#fff; border:none; border-radius:6px; cursor:pointer;">
      📤 Export
    </button>

    <!-- Template Button -->
    <button type="submit" name="generate_template" 
            style="padding:8px 14px; background:#9b59b6; color:#fff; border:none; border-radius:6px; cursor:pointer;">
      📄 Template
    </button>
  </form>

  <div style="margin-top:8px; font-size:13px; color:#666;">
    CSV columns required: <b>Category, Name, Price</b> (Image optional).
  </div>
</div>
  <div style="margin-top:8px; font-size:13px; color:#666;">
  </div>
</div>

    <!-- Stock Table -->
    <table border="1" cellpadding="10" cellspacing="0" style="margin-top:20px; width:100%;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Category</th>
          <th>Name</th>
          <th>Price</th>
          <th>Image</th>
          <th>Created</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="stockTable">
        <?php
        $res = $conn->query("SELECT * FROM stock ORDER BY created_at DESC");
        while($row = $res->fetch_assoc()):
        ?>
          <tr>
            <td><?= intval($row['id']) ?></td>
            <td><?= htmlspecialchars($row['category']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td>₱<?= number_format((float)$row['price'],2) ?></td>
            <td>
              <?php if (!empty($row['image_path']) && file_exists(__DIR__ . '/' . $row['image_path'])): ?>
                <img src="<?= htmlspecialchars($row['image_path']) ?>" alt="img">
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($row['created_at']) ?></td>
            <td>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this product?');">
                <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
                <button type="submit" style="background:#e74c3c; color:white; border:none; padding:6px 12px; border-radius:5px; cursor:pointer;">
                  🗑 Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>

  </div>

  <script>
    function toggleForm(){
      const f=document.getElementById("productForm");
      f.style.display=f.style.display==="block"?"none":"block";
    }
    function toggleTheme(){
      document.body.classList.toggle("dark");
      localStorage.setItem("theme",document.body.classList.contains("dark")?"dark":"light");
    }
    if(localStorage.getItem("theme")==="dark"){document.body.classList.add("dark");}
    
  </script>

  <script>
    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText =
    now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
      updateClock();
   </script>


  <?php
  // show flash message (if any) and then clear it
  if (!empty($_SESSION['flash'])) {
      $msg = addslashes($_SESSION['flash']);
      echo "<script>alert('{$msg}');</script>";
      unset($_SESSION['flash']);
  }
  ?>
</body>
</html>
