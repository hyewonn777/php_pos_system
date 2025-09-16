<?php
session_start();
require 'db.php'; // mysqli connection

$error = '';

/* -------------------- CASHIER CRUD -------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ADD cashier
    if (isset($_POST['add_cashier'])) {
        $fullname = trim($_POST['fullname']);
        $username = trim($_POST['username']);
        $password = hash('sha256', trim($_POST['password'])); // SHA256

        $check = $conn->prepare("SELECT id FROM cashier WHERE username=?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "⚠️ Cashier username already exists!";
        } else {
            $stmt = $conn->prepare("INSERT INTO cashier (fullname, username, password_hash, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("sss", $fullname, $username, $password);
            $stmt->execute();
            $stmt->close();
            $success = "✅ Cashier added successfully!";
        }
        $check->close();
    }

    // UPDATE cashier
    if (isset($_POST['update_cashier'])) {
        $id = intval($_POST['id']);
        $fullname = trim($_POST['fullname']);
        $username = trim($_POST['username']);

        if (!empty($_POST['password'])) {
            $password = hash('sha256', trim($_POST['password']));
            $stmt = $conn->prepare("UPDATE cashier SET fullname=?, username=?, password_hash=? WHERE id=?");
            $stmt->bind_param("sssi", $fullname, $username, $password, $id);
        } else {
            $stmt = $conn->prepare("UPDATE cashier SET fullname=?, username=? WHERE id=?");
            $stmt->bind_param("ssi", $fullname, $username, $id);
        }
        $stmt->execute();
        $stmt->close();
        $success = "✅ Cashier updated successfully!";
    }

    // DELETE cashier
    if (isset($_POST['delete_cashier'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM cashier WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $success = "🗑️ Cashier deleted successfully!";
    }
}

// Always fetch latest cashier list
$cashiers = $conn->query("SELECT * FROM cashier ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

/* -------------------- ADMIN CRUD -------------------- */
/* ADD ADMIN */
if (isset($_POST['add_admin'])) {
    $username = trim($_POST['username'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    if ($username === '' || $password_raw === '') {
        $error = "Please fill required admin fields.";
    } else {
        $password = hash('sha256', $password_raw);

        $check = $conn->prepare("SELECT id FROM admin WHERE username = ?");
        if (!$check) {
            $error = "SQL Error (Check admin prepare): " . $conn->error;
        } else {
            $check->bind_param("s", $username);
            if (!$check->execute()) {
                $error = "SQL Error (Check admin execute): " . $check->error;
            } else {
                $check->store_result();
                if ($check->num_rows > 0) {
                    $error = "⚠️ Admin username already exists!";
                } else {
                    $stmt = $conn->prepare("INSERT INTO admin (username, password_hash, created_at) VALUES (?, ?, NOW())");
                    if (!$stmt) {
                        $error = "SQL Error (Insert admin prepare): " . $conn->error;
                    } else {
                        $stmt->bind_param("ss", $username, $password);
                        if (!$stmt->execute()) {
                            $error = "SQL Error (Insert admin execute): " . $stmt->error;
                        } else {
                            $stmt->close();
                            $check->close();
                            header("Location: user_management.php");
                            exit;
                        }
                        $stmt->close();
                    }
                }
            }
            $check->close();
        }
    }
}

/* UPDATE ADMIN */
if (isset($_POST['update_admin'])) {
    $id = intval($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    if ($id <= 0 || $username === '') {
        $error = "Missing fields for admin update.";
    } else {
        if (!empty($_POST['password'])) {
            $password = hash('sha256', trim($_POST['password']));
            $stmt = $conn->prepare("UPDATE admin SET username=?, password_hash=? WHERE id=?");
            if (!$stmt) {
                $error = "SQL Error (Update admin prepare): " . $conn->error;
            } else {
                $stmt->bind_param("ssi", $username, $password, $id);
            }
        } else {
            $stmt = $conn->prepare("UPDATE admin SET username=? WHERE id=?");
            if (!$stmt) {
                $error = "SQL Error (Update admin prepare): " . $conn->error;
            } else {
                $stmt->bind_param("si", $username, $id);
            }
        }
        if (isset($stmt) && $stmt) {
            if (!$stmt->execute()) {
                $error = "SQL Error (Update admin execute): " . $stmt->error;
            } else {
                $stmt->close();
                header("Location: user_management.php");
                exit;
            }
            $stmt->close();
        }
    }
}

/* DELETE ADMIN */
if (isset($_POST['delete_admin'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        $error = "Invalid admin id.";
    } else {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM admin");
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row['cnt'] <= 1) {
                $error = "⚠️ Cannot delete the last Admin account!";
            } else {
                $stmt = $conn->prepare("DELETE FROM admin WHERE id=?");
                if (!$stmt) {
                    $error = "SQL Error (Delete admin prepare): " . $conn->error;
                } else {
                    $stmt->bind_param("i", $id);
                    if (!$stmt->execute()) {
                        $error = "SQL Error (Delete admin execute): " . $stmt->error;
                    } else {
                        $stmt->close();
                        header("Location: user_management.php");
                        exit;
                    }
                    $stmt->close();
                }
            }
        } else {
            $error = "SQL Error (Count admins): " . $conn->error;
        }
    }
}

/* FETCH ALL ADMINS */
$admin = [];
$res2 = $conn->query("SELECT * FROM admin ORDER BY id ASC");
if ($res2) {
    $admin = $res2->fetch_all(MYSQLI_ASSOC);
    $res2->free();
} else {
    if ($error === '') $error = "Could not load admin users: " . $conn->error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Management</title>
  <link rel="stylesheet" href="css/admin.css?v=3">
  <style>
    
    :root {
      --bg:#f9f9f9; 
      --text:#222; 
      --card-bg:#fff; 
      --sidebar-bg:#2c3e50; 
      --sidebar-text:#ecf0f1; 
    }

    .dark { 
      --bg:#1e1e1e;
      --text:#f5f5f5; 
      --card-bg:#2c2c2c; 
      --sidebar-bg:#111; 
      --sidebar-text:#bbb; 
    }

    body { 
      margin:0; 
      font-family:Arial, sans-serif; 
      background:var(--bg); 
      color:var(--text); 
      display:flex; 
      transition:all .3s ease; 
    }

    .sidebar { 
      width:220px; 
      background:var(--sidebar-bg); 
      color:var(--sidebar-text); 
      height:100vh; 
      padding:20px; 
      display:flex; 
      flex-direction:column; 
    }
    
    .sidebar h2 { 
      text-align:center; 
      margin-bottom:20px; 
    }
    
    .sidebar ul { 
      list-style:none; 
      padding:0; flex:1; 
    }
    
    .sidebar ul li { 
      margin:15px 0; 
    }
    
    .sidebar ul li a { 
      color:var(--sidebar-text); 
      text-decoration:none; 
    }
    
    .content { 
      flex:1; 
      padding:20px; }
    
    .topbar { 
      display:flex; 
      justify-content:flex-end; 
      margin-bottom:20px; 
    }

    input, select, button { 
      padding:10px; 
      margin:5px; 
      border-radius:6px; 
      border:1px solid #ccc; 
    }

    table { 
      width:100%; 
      border-collapse:collapse; 
      margin-top:15px; 
    }

    th, td { 
      padding:12px; 
      border:1px solid #ddd; 
      text-align:left; 
    }

    th { 
      background:#1976d2; 
      color:#fff; 
    }

    .edit-row td { 
        background:#fafafa; 
    }

    /* Clock Glow */
    #clock {
      font-weight: bold;
      font-size: 16px;
      transition: 0.3s;
    }

    .dark #clock {
      color: #0ff;
      text-shadow: 0 0 10px #00f, 0 0 20px #0ff, 0 0 30px #0ff;
    }

    /* Dark Mode Toggle Glow */
    .toggle-btn {
      padding: 8px 14px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
      font-weight: bold;
      transition: 0.3s;
    }

    .dark .toggle-btn {
      background: #2c3e50;
      color: #0ff;
      box-shadow: 0 0 10px #00f, 0 0 20px #0ff, 0 0 30px #0ff;
    }

    button.toggle-btn:hover { 
      background: #2980b9;
    }

    /* Transparent password box */
    input[type="password"] {
      background: transparent;
      border: 1px solid #ccc;
      color: var(--text);
      outline: none;
    }

    /* Optional: make placeholder text softer */
    input[type="password"]::placeholder {
      color: #999;
    }

    /* Transparent password box */
    input[type="text"] {
      background: transparent;
      border: 1px solid #ccc;
      color: var(--text);
      outline: none;
    }

    /* Optional: make placeholder text softer */
    input[type="text"]::placeholder {
      color: #999;
    }


  </style>
</head>
<body>
    <!-- Sidebar -->
  <div class="sidebar">
    <h2>Admin Panel</h2>
    <div class="logo-box"><img src="images/rsz_logo.png" alt="Logo"></div>
    <ul>
      <li><a href="index.php">Dashboard</a></li>
      <li><a href="sales.php">Sales & Tracking</a></li>
      <li><a href="stock.php">Product / Stock</a></li>
      <li><a href="appointment.php">Appointments / Booking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="user_management.php">Account Management</a></li>
    </ul>
    <div class="logout">
      <form action="logout.php" method="POST"><button type="submit">Logout 🚪</button></form>
    </div>
  </div>

<div class="content">
    <div class="topbar">
      <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
      <button class="toggle-btn" onclick="toggleTheme()">🌙 Toggle Dark Mode</button>
    </div>

    <?php if (!empty($error)) echo "<p style='color:red;font-weight:bold;'>$error</p>"; ?>

    <!-- Admin Management -->
    <div class="section">
      <h2>👑 Admin Accounts</h2>
      <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="add_admin">➕ Add Admin</button>
      </form>
      <table>
        <tr><th>ID</th><th>Username</th><th>Created At</th><th>Action</th></tr>
        <?php foreach ($admin as $a): ?>
          <tr>
            <td><?= $a['id'] ?></td>
            <td><?= htmlspecialchars($a['username']) ?></td>
            <td><?= $a['created_at'] ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <input type="text" name="username" value="<?= htmlspecialchars($a['username']) ?>">
                <input type="password" name="password" placeholder="New Password (optional)">
                <button type="submit" name="update_admin">💾 Update</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <button type="submit" name="delete_admin" onclick="return confirm('Delete this admin?')">🗑️ Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <br>
    <br>
    <!-- Cashier Management -->
    <div class="section">
      <h2>💼 Cashier Accounts</h2>
      <form method="POST">
        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <!-- keep your original button name so UI unchanged -->
        <button type="submit" name="user_management">➕ Add Cashier</button>
      </form>
      <table>
        <tr><th>ID</th><th>Full Name</th><th>Username</th><th>Created At</th><th>Action</th></tr>
        <?php foreach ($cashiers as $c): ?>
          <tr>
            <td><?= $c['id'] ?></td>
            <td><?= htmlspecialchars($c['fullname']) ?></td>
            <td><?= htmlspecialchars($c['username']) ?></td>
            <td><?= $c['created_at'] ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <input type="text" name="fullname" value="<?= htmlspecialchars($c['fullname']) ?>">
                <input type="text" name="username" value="<?= htmlspecialchars($c['username']) ?>">
                <input type="password" name="password" placeholder="New Password (optional)">
                <button type="submit" name="update_cashier">💾 Update</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" name="delete_cashier" onclick="return confirm('Delete this cashier?')">🗑️ Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <script>
    function toggleTheme() {
      document.body.classList.toggle("dark");
      localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
    }
    if (localStorage.getItem("theme") === "dark") {
      document.body.classList.add("dark");
    }
    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText = now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000); updateClock();
  </script>

</body>
</html>
