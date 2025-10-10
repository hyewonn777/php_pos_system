<?php
session_start();
require 'db.php'; // mysqli connection

// Initialize dark mode from session or default to light
if (!isset($_SESSION['dark_mode'])) {
    $_SESSION['dark_mode'] = false;
}

// Toggle dark mode if requested
if (isset($_GET['toggle_dark_mode'])) {
    $_SESSION['dark_mode'] = !$_SESSION['dark_mode'];
    header('Location: ' . str_replace('?toggle_dark_mode=1', '', $_SERVER['REQUEST_URI']));
    exit;
}

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
        $res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='admin'");
        if ($res) {
            $row = $res->fetch_assoc();
            $isAdmin = $conn->query("SELECT role FROM users WHERE id=$id")->fetch_assoc()['role'] ?? '';
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

// Get user statistics
$totalUsers = count($users);
$totalAdmins = count($admins);
$totalPhotographers = count($photographers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
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
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Styles */
    .sidebar {
      width: var(--sidebar-width);
      background: var(--sidebar-bg);
      color: white;
      height: 100vh;
      position: fixed;
      overflow-y: auto;
      transition: var(--transition);
      z-index: 1000;
    }

    .sidebar-header {
      padding: 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 12px;
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
    }

    .sidebar-title {
      font-size: 18px;
      font-weight: 600;
    }

    .sidebar-menu {
      padding: 20px 0;
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 12px 20px;
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: var(--transition);
      gap: 12px;
    }

    .menu-item:hover, .menu-item.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border-left: 4px solid var(--primary);
    }

    .menu-item i {
      width: 20px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: auto;
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 20px;
      transition: var(--transition);
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 0 20px 0;
      border-bottom: 1px solid var(--border);
      margin-bottom: 25px;
    }

    .header-title h1 {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 16px;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 15px;
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
    }

    .theme-toggle:hover, .notification-btn:hover, .user-menu:hover {
      background: var(--primary);
      color: white;
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
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px var(--shadow);
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
    }

    .stat-label {
      color: var(--text-muted);
      font-size: 14px;
    }

    /* Forms */
    .form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text-muted);
      font-size: 14px;
    }

    .form-control {
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: 8px;
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

    /* Buttons */
    .btn {
      padding: 12px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--primary-dark);
    }

    .btn-success {
      background: var(--success);
      color: white;
    }

    .btn-success:hover {
      background: #3aa8d8;
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #c53030;
    }

    .btn-warning {
      background: var(--warning);
      color: white;
    }

    .btn-warning:hover {
      background: #d61a6e;
    }

    /* Tables */
    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
    }

    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .table {
      width: 100%;
      border-collapse: collapse;
    }

    .table th, .table td {
      padding: 14px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .table th {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 14px;
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .table tr:hover {
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .table tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    .action-cell {
      display: flex;
      gap: 8px;
    }

    .action-btn {
      padding: 6px 12px;
      border-radius: 6px;
      border: none;
      font-size: 12px;
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
    }

    .action-btn.primary {
      background: var(--primary);
      color: white;
    }

    .action-btn.danger {
      background: var(--danger);
      color: white;
    }

    .action-btn:hover {
      transform: translateY(-1px);
    }

    /* User Form */
    .user-form {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      align-items: end;
    }

    /* Flash Message */
    .flash-message {
      padding: 12px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-weight: 500;
      animation: slideIn 0.5s ease-out;
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
    }

    /* Responsive */
    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
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
      .form-grid {
        grid-template-columns: 1fr;
      }
      .table-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      .action-cell {
        flex-direction: column;
      }
    }

    /* Animations */
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

    .hidden {
      display: none;
    }
  </style>
</head>
<body class="<?php echo $_SESSION['dark_mode'] ? 'dark-mode' : 'light-mode'; ?>">
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
        <a href="physical_orders.php" class="menu-item">
          <i class="fas fa-store"></i>
          <span class="menu-text">Physical Orders</span>
        </a>
        <a href="appointment.php" class="menu-item">
          <i class="fas fa-calendar-alt"></i>
          <span class="menu-text">Appointments</span>
        </a>
        <a href="user_management.php" class="menu-item active">
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
          <h1>User Management</h1>
          <p>Manage admin and photographer accounts</p>
        </div>
        <div class="header-actions">
          <a href="?toggle_dark_mode=1" class="theme-toggle">
            <i class="fas fa-moon"></i>
          </a>
          <div class="user-menu">
            <i class="fas fa-user"></i>
          </div>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php if (!empty($error)): ?>
        <div class="flash-message flash-error">
          <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="flash-message flash-success">
          <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
      <?php endif; ?>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $totalUsers; ?></div>
              <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-users"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $totalAdmins; ?></div>
              <div class="stat-label">Admin Accounts</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-user-shield"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $totalPhotographers; ?></div>
              <div class="stat-label">Photographer Accounts</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-camera"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Admin Form -->
      <div class="form-container">
        <h3 style="margin-bottom: 20px; color: var(--text);">Add New Admin</h3>
        <form method="POST">
          <div class="form-grid">
            <div class="form-group">
              <label for="admin_fullname">Full Name</label>
              <input type="text" id="admin_fullname" name="fullname" class="form-control" placeholder="Enter full name" required>
            </div>
            <div class="form-group">
              <label for="admin_username">Username</label>
              <input type="text" id="admin_username" name="username" class="form-control" placeholder="Enter username" required>
            </div>
            <div class="form-group">
              <label for="admin_password">Password</label>
              <input type="password" id="admin_password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <div class="form-group">
              <input type="hidden" name="role" value="admin">
              <button type="submit" name="add_user" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add Admin
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Admin Accounts Table -->
      <div class="table-container">
        <div class="table-header">
          <h3 style="color: var(--text);">Admin Accounts</h3>
        </div>
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Username</th>
              <th>Role</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($admins)): ?>
              <tr>
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                  <i class="fas fa-user-shield" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                  No admin accounts found
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($admins as $admin): ?>
                <tr>
                  <td><?= $admin['id'] ?></td>
                  <td><?= htmlspecialchars($admin['fullname']) ?></td>
                  <td><?= htmlspecialchars($admin['username']) ?></td>
                  <td>
                    <span style="background: var(--success); color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                      <?= strtoupper($admin['role']) ?>
                    </span>
                  </td>
                  <td><?= date('M j, Y g:i A', strtotime($admin['created_at'])) ?></td>
                  <td class="action-cell">
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                      <input type="hidden" name="role" value="admin">
                      <button type="submit" name="delete_user" class="action-btn danger" onclick="return confirm('Are you sure you want to delete this admin account?')">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Add Photographer Form -->
      <div class="form-container">
        <h3 style="margin-bottom: 20px; color: var(--text);">Add New Photographer</h3>
        <form method="POST">
          <div class="form-grid">
            <div class="form-group">
              <label for="photo_fullname">Full Name</label>
              <input type="text" id="photo_fullname" name="fullname" class="form-control" placeholder="Enter full name" required>
            </div>
            <div class="form-group">
              <label for="photo_username">Username</label>
              <input type="text" id="photo_username" name="username" class="form-control" placeholder="Enter username" required>
            </div>
            <div class="form-group">
              <label for="photo_password">Password</label>
              <input type="password" id="photo_password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <div class="form-group">
              <input type="hidden" name="role" value="photographer">
              <button type="submit" name="add_user" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add Photographer
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Photographer Accounts Table -->
      <div class="table-container">
        <div class="table-header">
          <h3 style="color: var(--text);">Photographer Accounts</h3>
        </div>
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Username</th>
              <th>Role</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($photographers)): ?>
              <tr>
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                  <i class="fas fa-camera" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                  No photographer accounts found
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($photographers as $photographer): ?>
                <tr>
                  <td><?= $photographer['id'] ?></td>
                  <td><?= htmlspecialchars($photographer['fullname']) ?></td>
                  <td><?= htmlspecialchars($photographer['username']) ?></td>
                  <td>
                    <span style="background: var(--warning); color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                      <?= strtoupper($photographer['role']) ?>
                    </span>
                  </td>
                  <td><?= date('M j, Y g:i A', strtotime($photographer['created_at'])) ?></td>
                  <td class="action-cell">
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="id" value="<?= $photographer['id'] ?>">
                      <input type="hidden" name="role" value="photographer">
                      <button type="submit" name="delete_user" class="action-btn danger" onclick="return confirm('Are you sure you want to delete this photographer account?')">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
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
    // Add loading state to forms
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('submit', function(e) {
        const submitBtn = e.submitter;
        if (submitBtn && submitBtn.type === 'submit') {
          submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
          submitBtn.disabled = true;
        }
      });
    });

    // Update clock
    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText = now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();
  </script>
</body>
</html>