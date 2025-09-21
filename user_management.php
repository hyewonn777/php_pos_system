<?php
session_start();
require 'db.php'; // mysqli connection

$error = '';
$success = '';

/* -------------------- USER CRUD (Admin & Photographer) -------------------- */

/* ADD USER */
if (isset($_POST['add_user'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? ''); // "admin" or "photographer"

    if ($fullname === '' || $username === '' || $password_raw === '' || $role === '') {
        $error = "Please fill all required fields.";
    } else {
        $password = hash('sha256', $password_raw);

        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        if (!$check) {
            $error = "SQL Error (Check user prepare): " . $conn->error;
        } else {
            $check->bind_param("s", $username);
            if (!$check->execute()) {
                $error = "SQL Error (Check user execute): " . $check->error;
            } else {
                $check->store_result();
                if ($check->num_rows > 0) {
                    $error = " Username already exists!";
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (fullname, username, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())");
                    if (!$stmt) {
                        $error = "SQL Error (Insert user prepare): " . $conn->error;
                    } else {
                        $stmt->bind_param("ssss", $fullname, $username, $password, $role);
                        if (!$stmt->execute()) {
                            $error = "SQL Error (Insert user execute): " . $stmt->error;
                        } else {
                            $stmt->close();
                            $check->close();
                            $success = " $role added successfully!";
                        }
                    }
                }
            }
            $check->close();
        }
    }
}

/* UPDATE USER */
if (isset($_POST['update_user'])) {
    $id = intval($_POST['id'] ?? 0);
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');

    if ($id <= 0 || $fullname === '' || $username === '' || $role === '') {
        $error = "Missing fields for user update.";
    } else {
        if (!empty($_POST['password'])) {
            $password = hash('sha256', trim($_POST['password']));
            $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, password_hash=?, role=? WHERE id=?");
            $stmt->bind_param("ssssi", $fullname, $username, $password, $role, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, role=? WHERE id=?");
            $stmt->bind_param("sssi", $fullname, $username, $role, $id);
        }

        if ($stmt) {
            if (!$stmt->execute()) {
                $error = "SQL Error (Update user execute): " . $stmt->error;
            } else {
                $stmt->close();
                $success = " $role updated successfully!";
            }
        }
    }
}

/* DELETE USER */
if (isset($_POST['delete_user'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        $error = "Invalid user id.";
    } else {
        // Prevent deleting the last admin
        $res = $conn->query("SELECT COUNT(*) as cnt FROM user WHERE role='admin'");
        if ($res) {
            $row = $res->fetch_assoc();
            $isAdmin = $conn->query("SELECT role FROM user WHERE id=$id")->fetch_assoc()['role'] ?? '';
            if ($isAdmin === 'admin' && $row['cnt'] <= 1) {
                $error = "Cannot delete the last Admin account!";
            } else {
                $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
                if (!$stmt) {
                    $error = "SQL Error (Delete user prepare): " . $conn->error;
                } else {
                    $stmt->bind_param("i", $id);
                    if (!$stmt->execute()) {
                        $error = "SQL Error (Delete user execute): " . $stmt->error;
                    } else {
                        $stmt->close();
                        $success = "User deleted successfully!";
                    }
                }
            }
        }
    }
}

/* FETCH ALL USERS */
$users = [];
$admins = [];
$photographers = [];

$res = $conn->query("SELECT * FROM users ORDER BY id ASC");
if ($res) {
    $users = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
} else {
    if ($error === '') $error = "Could not load users: " . $conn->error;
}

// Ensure arrays exist even if query failed
if (is_array($users)) {
    $admins = array_filter($users, fn($u) => $u['role'] === 'admin');
    $photographers = array_filter($users, fn($u) => $u['role'] === 'photographer');
} else {
    $admins = [];
    $photographers = [];
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
      background:#333; 
      color:#ffffff; 
    }

    .edit-row td { 
        background:#fafafa; 
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
      <li><a href="sales.php">Sales Tracking</a></li>
      <li><a href="orders.php">Order Tracking</a></li>
      <li><a href="stock.php">Inventory</a></li>
      <li><a href="appointment.php">Appointments</a></li>
      <li><a href="user_management.php">Account</a></li>
    </ul>
    <div class="logout">
      <form action="logout.php" method="POST"><button type="submit">Logout</button></form>
    </div>
  </div>

<div class="content">
    <div class="topbar">
      <div id="clock" style="margin-right:auto; font-weight:bold; font-size:16px;"></div>
      <button class="toggle-btn" onclick="toggleTheme()">Toggle Dark Mode</button>
    </div>

    <?php if (!empty($error)) echo "<p style='color:red;font-weight:bold;'>$error</p>"; ?>

    <!-- Admin Management -->
    <div class="section">
      <h2>Admin Accounts</h2>
      <form method="POST">
        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="hidden" name="role" value="admin">
        <button type="submit" name="add_user">Add Admin</button>
      </form>
      <table>
        <tr><th>ID</th><th>Full Name</th><th>Username</th><th>Created At</th><th>Action</th></tr>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td><?= $a['id'] ?></td>
            <td><?= htmlspecialchars($a['fullname']) ?></td>
            <td><?= htmlspecialchars($a['username']) ?></td>
            <td><?= $a['created_at'] ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <input type="text" name="fullname" value="<?= htmlspecialchars($a['fullname']) ?>">
                <input type="text" name="username" value="<?= htmlspecialchars($a['username']) ?>">
                <input type="password" name="password" placeholder="New Password (optional)">
                <input type="hidden" name="role" value="admin">
                <button type="submit" name="update_user">Update</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <button type="submit" name="delete_user" onclick="return confirm('Delete this admin?')">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <!-- Photographer Management -->
    <div class="section">
      <h2>Photographer Accounts</h2>
      <form method="POST">
        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="hidden" name="role" value="photographer">
        <button type="submit" name="add_user">Add Photographer</button>
      </form>
      <table>
        <tr><th>ID</th><th>Full Name</th><th>Username</th><th>Created At</th><th>Action</th></tr>
        <?php foreach ($photographers as $p): ?>
          <tr>
            <td><?= $p['id'] ?></td>
            <td><?= htmlspecialchars($p['fullname']) ?></td>
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td><?= $p['created_at'] ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <input type="text" name="fullname" value="<?= htmlspecialchars($p['fullname']) ?>">
                <input type="text" name="username" value="<?= htmlspecialchars($p['username']) ?>">
                <input type="password" name="password" placeholder="New Password (optional)">
                <input type="hidden" name="role" value="photographer">
                <button type="submit" name="update_user">Update</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" name="delete_user" onclick="return confirm('Delete this photographer?')">Delete</button>
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
