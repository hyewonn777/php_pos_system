<?php
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

/* ------------------------
   Handle Form Submissions
------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Delete appointment ---
    if (!empty($_POST['delete_id'])) {
        $delete_id = intval($_POST['delete_id']);
        $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment deleted successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Update appointment ---
    if (!empty($_POST['edit_id'])) {
        $id       = intval($_POST['edit_id']);
        $customer = trim($_POST['customer']);
        $date     = $_POST['date'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $location = trim($_POST['location']);
        $status   = $_POST['status'];

        $stmt = $conn->prepare("UPDATE appointments 
            SET customer=?, date=?, start_time=?, end_time=?, location=?, status=? 
            WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $customer, $date, $start, $end, $location, $status, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment updated successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Add appointment ---
    if (!empty($_POST['customer']) && !empty($_POST['date']) && !empty($_POST['start_time']) && !empty($_POST['end_time']) && !empty($_POST['status'])) {
        $customer = trim($_POST['customer']);
        $date     = $_POST['date'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $location = trim($_POST['location']);
        $status   = $_POST['status'];

        $stmt = $conn->prepare("INSERT INTO appointments 
            (customer, service, date, start_time, end_time, location, status, created_at) 
            VALUES (?, 'Photography', ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ssssss", $customer, $date, $start, $end, $location, $status);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment added successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    $_SESSION['flash'] = ['message' => "Invalid request. Please fill all required fields.", 'type' => 'error'];
    header("Location: appointment.php");
    exit;
}

/* ------------------------
   Auto-Cancel Expired
------------------------ */
// Cancel only Pending events if expired
$conn->query("UPDATE appointments 
    SET status='Cancelled' 
    WHERE CONCAT(date,' ',end_time) < NOW() 
    AND status='Pending'");

/* ------------------------
   Fetch Events for Calendar
------------------------ */
$events = [];
$res = $conn->query("SELECT * FROM appointments");
while ($row = $res->fetch_assoc()) {
    $color = "#4361ee"; // default - Marcomedia primary
    if ($row['status'] === "Vacant")   $color = "#4cc9f0"; // Marcomedia success
    if ($row['status'] === "Approved") $color = "#4cc9f0"; // Marcomedia success
    if ($row['status'] === "Cancelled") $color = "#e63946"; // Marcomedia danger
    if ($row['status'] === "Pending")  $color = "#f72585"; // Marcomedia warning

    $events[] = [
        "id"      => $row['id'],
        "title"   => $row['customer'] . " (" . $row['service'] . ")",
        "start"   => $row['date'] . "T" . $row['start_time'],
        "end"     => $row['date'] . "T" . $row['end_time'],
        "color"   => $color,
        "location"=> $row['location'],
        "status"  => $row['status']
    ];
}
$events_json = json_encode($events);  

// Get appointment statistics
$totalAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments")->fetch_assoc()['cnt'];
$pendingAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE status='Pending'")->fetch_assoc()['cnt'];
$approvedAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE status='Approved'")->fetch_assoc()['cnt'];
$todayAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE date = CURDATE()")->fetch_assoc()['cnt'];

$result = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Appointments & Booking - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
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
      display: none;
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

    /* Calendar */
    .calendar-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      box-shadow: 0 4px 12px var(--shadow);
      margin-bottom: 30px;
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

    .search-box {
      position: relative;
      width: 300px;
    }

    .search-box input {
      width: 100%;
      padding: 10px 15px 10px 40px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
    }

    .search-box i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
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

    /* Status Badges */
    .status-badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .status-pending {
      background: rgba(247, 37, 133, 0.1);
      color: var(--warning);
    }

    .status-approved {
      background: rgba(76, 201, 240, 0.1);
      color: var(--success);
    }

    .status-cancelled {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }

    .modal-content {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 24px;
      width: 400px;
      max-width: 90%;
      box-shadow: 0 10px 30px var(--shadow);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
    }

    .modal-body p {
      margin: 8px 0;
      color: var(--text);
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
      .search-box {
        width: 100%;
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
        <a href="appointment.php" class="menu-item active">
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
          <button type="submit" style="background: var(--danger); color: white; border: none; padding: 10px; width: 100%; border-radius: 6px; cursor: pointer; transition: var(--transition);">
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
          <h1>Appointments & Booking</h1>
          <p>Manage client bookings and appointments</p>
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

      <!-- Flash Message -->
      <?php if(!empty($_SESSION['flash'])): ?>
        <div class="flash-message flash-<?php echo $_SESSION['flash']['type'] === 'error' ? 'error' : 'success'; ?>">
          <i class="fas fa-<?php echo $_SESSION['flash']['type'] === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
          <?= $_SESSION['flash']['message']; unset($_SESSION['flash']); ?>
        </div>
      <?php endif; ?>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card" onclick="toggleForm()">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $totalAppointments; ?></div>
              <div class="stat-label">Total Appointments</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-calendar-check"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $pendingAppointments; ?></div>
              <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-clock"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $approvedAppointments; ?></div>
              <div class="stat-label">Approved</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-check-circle"></i>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $todayAppointments; ?></div>
              <div class="stat-label">Today's Appointments</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-calendar-day"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Appointment Form -->
      <div class="form-container" id="appointmentForm">
        <div class="table-header">
          <h3 style="color: var(--text);">Add New Appointment</h3>
          <button type="button" class="btn btn-warning" onclick="toggleForm()">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
        <form method="POST">
          <div class="form-grid">
            <div class="form-group">
              <label for="customer">Customer Name</label>
              <input type="text" id="customer" name="customer" class="form-control" placeholder="Enter customer name" required>
            </div>
            <div class="form-group">
              <label for="date">Date</label>
              <input type="date" id="date" name="date" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="start_time">Start Time</label>
              <input type="time" id="start_time" name="start_time" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="end_time">End Time</label>
              <input type="time" id="end_time" name="end_time" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="location">Location</label>
              <input type="text" id="location" name="location" class="form-control" placeholder="Enter location" required>
            </div>
            <div class="form-group">
              <label for="status">Status</label>
              <select id="status" name="status" class="form-control" required>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
              </select>
            </div>
            <div class="form-group">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Appointment
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Calendar -->
      <div class="calendar-container">
        <div class="table-header">
          <h3 style="color: var(--text);">Appointment Calendar</h3>
        </div>
        <div id="calendar"></div>
      </div>

      <!-- Appointments Table -->
      <div class="table-container">
        <div class="table-header">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search appointments...">
          </div>
          <h3 style="color: var(--text);">All Appointments</h3>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Start Time</th>
              <th>End Time</th>
              <th>Location</th>
              <th>Status</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC");
            if ($res && $res->num_rows > 0):
                while($row = $res->fetch_assoc()):
            ?>
            <tr>
              <td><?= intval($row['id']) ?></td>
              <td><?= htmlspecialchars($row['customer']) ?></td>
              <td><?= htmlspecialchars($row['date']) ?></td>
              <td><?= date("g:i A", strtotime($row['start_time'])) ?></td>
              <td><?= date("g:i A", strtotime($row['end_time'])) ?></td>
              <td><?= htmlspecialchars($row['location']) ?></td>
              <td>
                <span class="status-badge status-<?= strtolower($row['status']) ?>">
                  <?= htmlspecialchars($row['status']) ?>
                </span>
              </td>
              <td><?= date('M j, Y g:i A', strtotime($row['created_at'])) ?></td>
              <td class="action-cell">
                <form method="POST" style="display: inline;">
                  <input type="hidden" name="edit_id" value="<?= intval($row['id']) ?>">
                  <input type="hidden" name="customer" value="<?= htmlspecialchars($row['customer']) ?>">
                  <input type="hidden" name="date" value="<?= htmlspecialchars($row['date']) ?>">
                  <input type="hidden" name="start_time" value="<?= htmlspecialchars($row['start_time']) ?>">
                  <input type="hidden" name="end_time" value="<?= htmlspecialchars($row['end_time']) ?>">
                  <input type="hidden" name="location" value="<?= htmlspecialchars($row['location']) ?>">
                  <input type="hidden" name="status" value="Approved">
                  <button type="submit" class="action-btn primary" title="Approve Appointment">
                    <i class="fas fa-check"></i>
                  </button>
                </form>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this appointment?');">
                  <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
                  <button type="submit" class="action-btn danger" title="Delete Appointment">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php
                endwhile;
            else:
            ?>
            <tr>
              <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 40px;">
                <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                No appointments found
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

  <!-- Event Modal -->
  <div id="eventModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="modalTitle" style="color: var(--text);">Appointment Details</h3>
        <button class="btn btn-warning" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="modal-body">
        <p id="modalDate"></p>
        <p id="modalTime"></p>
        <p id="modalLocation"></p>
        <p id="modalStatus"></p>
      </div>
    </div>
  </div>

  <script>
    // Toggle Add Appointment Form
    function toggleForm() {
      const form = document.getElementById("appointmentForm");
      form.style.display = form.style.display === "none" ? "block" : "none";
    }

    // Search Filter
    document.getElementById("searchInput").addEventListener("keyup", function () {
      const filter = this.value.toLowerCase();
      const rows = document.querySelectorAll(".table tbody tr");
      rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? "" : "none";
      });
    });

    // FullCalendar Initialization
    document.addEventListener("DOMContentLoaded", function () {
      const calendarEl = document.getElementById("calendar");
      const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: "dayGridMonth",
        height: 600,
        headerToolbar: {
          left: "prev,next today",
          center: "title",
          right: "dayGridMonth,timeGridWeek,listWeek"
        },
        events: <?= $events_json ?>,
        eventClick: function (info) {
          info.jsEvent.preventDefault();

          // Populate modal with details
          document.getElementById("modalTitle").innerText = info.event.title;
          document.getElementById("modalDate").innerText =
            "Date: " + info.event.start.toLocaleDateString();
          document.getElementById("modalTime").innerText =
            "Time: " +
            info.event.start.toLocaleTimeString([], {
              hour: "numeric",
              minute: "2-digit",
              hour12: true
            }) +
            " - " +
            (info.event.end
              ? info.event.end.toLocaleTimeString([], {
                  hour: "numeric",
                  minute: "2-digit",
                  hour12: true
                })
              : "N/A");

          document.getElementById("modalLocation").innerText =
            "Location: " + (info.event.extendedProps.location || "N/A");
          document.getElementById("modalStatus").innerText =
            "Status: " + (info.event.extendedProps.status || "N/A");

          // Show modal
          document.getElementById("eventModal").style.display = "flex";
        }
      });
      calendar.render();
    });

    // Close Modal
    function closeModal() {
      document.getElementById("eventModal").style.display = "none";
    }

    // Close modal when clicking outside
    window.onclick = function (event) {
      const modal = document.getElementById("eventModal");
      if (event.target === modal) {
        modal.style.display = "none";
      }
    };

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
  </script>
</body>
</html>