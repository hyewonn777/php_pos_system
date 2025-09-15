<?php
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
            $_SESSION['flash'] = "Appointment deleted.";
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
            $_SESSION['flash'] = "Appointment updated.";
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
            $_SESSION['flash'] = "Appointment added.";
        }
        header("Location: appointment.php");
        exit;
    }

    $_SESSION['flash'] = "Invalid request.";
    header("Location: appointment.php");
    exit;
}

/* ------------------------
   Auto-Cancel Expired
------------------------ */
$conn->query("UPDATE appointments 
    SET status='Cancelled' 
    WHERE CONCAT(date,' ',end_time) < NOW() 
    AND status!='Cancelled'");

/* ------------------------
   Fetch Events for Calendar
------------------------ */
$events = [];
$res = $conn->query("SELECT * FROM appointments");
while ($row = $res->fetch_assoc()) {
    $color = "#3498db"; // default
    if ($row['status'] === "Vacant")   $color = "#2ecc71";
    if ($row['status'] === "Approved") $color = "#2ecc71";
    if ($row['status'] === "Cancelled") $color = "#e74c3c";
    if ($row['status'] === "Pending")  $color = "#f1c40f";

    $events[] = [
        "id"    => $row['id'],
        "title" => $row['customer'] . " (" . $row['service'] . ")",
        "start" => $row['date'] . "T" . $row['start_time'],
        "end"   => $row['date'] . "T" . $row['end_time'],
        "color" => $color
    ];
}
$events_json = json_encode($events);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Appointments / Booking</title>
  <link rel="stylesheet" href="css/admin.css">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
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
      font-family:Arial,sans-serif; 
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
      padding:0;flex:1;
    }

    .sidebar ul li {
      margin:15px 0;
    }

    .sidebar ul li a {
      color:var(--sidebar-text);
      text-decoration:none;
    }

    .sidebar ul li a:hover {
      text-decoration:underline;
    }

    .logout button {
      width: 100%; 
      padding: 8px; 
      border: none; 
      border-radius: 5px;
      background: #e74c3c; 
      color: white; 
      font-weight: bold; 
      cursor: pointer;
    }

    .logout button:hover { 
        background: #c0392b; 
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

    button, .btn {
      background: var(--brand-2);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 10px 14px;
      cursor: pointer;
      font-weight: 700;
    }

    .content {
      flex:1;
      padding:20px;
    }

    .topbar {
      display:flex; 
      justify-content:flex-end; 
      margin-bottom:20px;
    }

    .cards {
      display:grid; 
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); 
      gap:20px; 
      margin-top:20px;
    }

    .card {
      background:var(--card-bg); 
      padding:20px; 
      border-radius:10px; 
      box-shadow:0 4px 10px rgba(0,0,0,0.1);
      text-align:center; 
      font-size:18px;
      font-weight:bold; 
      transition:transform 0.2s ease; 
      cursor:pointer;
    }
    
    .card:hover {
      transform:translateY(-3px);
    }

    #searchBar {
      width:100%;
      padding:10px;
      margin:15px 0;
      border:1px solid #ccc;
      border-radius:6px;
    }

    #appointmentForm {
      display:none;
      margin-top:20px;
      padding:20px;
      background:var(--card-bg);
      border-radius:10px;
      box-shadow:0 4px 10px rgba(0,0,0,0.1);
    }
    
    #appointmentForm input,#appointmentForm select {
      display:block;
      margin:10px 0;
      padding:8px;
      width:100%;
      border:1px solid #ccc;
      border-radius:5px;
    }
    
    #appointmentForm button {
      padding:10px 15px;
      background:#27ae60;
      color:white;
      border:none;
      border-radius:5px;
      cursor:pointer;
    }
    
    #appointmentForm button:hover {
      background:#219150;
    }

    table {
      width:100%;
      margin-top:20px;
      border-collapse:collapse;
    }

    table,th,td {
      border:1px solid #ccc;
    }

    th,td {
      padding: 10px; 
      text-align: center;
    }

    #calendar { 
      max-width: 900px; 
      margin: 30px auto; 
      background: var(--card-bg); 
      padding: 15px; 
      border-radius: 
      10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
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
      box-shadow: 0 0 10px #00f, 0 0 20px #0ff;
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
    <h1>Appointments / Booking</h1>
    <p>Manage client bookings below.</p>

    <div class="cards">
      <div class="card" onclick="toggleForm()">➕ Add Appointment</div>
      <div class="card">📊 Total Appointments:
        <?php $res=$conn->query("SELECT COUNT(*) as c FROM appointments"); $row=$res->fetch_assoc(); echo intval($row['c']); ?>
      </div>
    </div>

    <!-- Search -->
    <input type="text" id="searchBar" placeholder="🔍 Search appointments...">

    <!-- Calendar -->
    <div id="calendar"></div>

    <!-- Add Appointment Form -->
    <form id="appointmentForm" method="POST">
      <h3>New Appointment</h3>
      <input type="text" name="customer" placeholder="Customer Name" required>
      <input type="date" name="date" required>
      <label>Start Time:</label>
      <input type="time" name="start_time" required>
      <label>End Time:</label>
      <input type="time" name="end_time" required>
      <input type="text" name="location" placeholder="Location" required>
      <select name="status" required>
        <option value="Pending">Pending</option>
        <option value="Approved">Approved</option>
      </select>
      <button type="submit">Add Appointment</button>
    </form>

    <!-- Appointments Table -->
    <table>
      <thead>
        <tr>
          <th>ID 🪪</th>
          <th>Customer 👥</th>
          <th>Date 📆</th>
          <th>Start 🟢</th>
          <th>Finish 🔴</th>
          <th>Location 📍</th>
          <th>Status 📖</th>
          <th>Created at 🕖</th>
          <th>Actions 🗳️</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $res=$conn->query("SELECT * FROM appointments ORDER BY created_at DESC");
        while($row=$res->fetch_assoc()):
        ?>
        <tr>
          <td><?= intval($row['id']) ?></td>
          <td><?= htmlspecialchars($row['customer']) ?></td>
          <td><?= htmlspecialchars($row['date']) ?></td>
          <td><?= htmlspecialchars($row['start_time']) ?></td>
          <td><?= htmlspecialchars($row['end_time']) ?></td>
          <td><?= htmlspecialchars($row['location']) ?></td>
          <td><?= htmlspecialchars($row['status']) ?></td>
          <td><?= htmlspecialchars($row['created_at']) ?></td>
          <td>
            <!-- Edit -->
            <form method="POST" style="display:inline;">
              <input type="hidden" name="edit_id" value="<?= intval($row['id']) ?>">
              <input type="text" name="customer" value="<?= htmlspecialchars($row['customer']) ?>" required>
              <input type="date" name="date" value="<?= htmlspecialchars($row['date']) ?>" required>
              <input type="time" name="start_time" value="<?= htmlspecialchars($row['start_time']) ?>" required>
              <input type="time" name="end_time" value="<?= htmlspecialchars($row['end_time']) ?>" required>
              <input type="text" name="location" value="<?= htmlspecialchars($row['location']) ?>" required>
              <select name="status" required>
                <option <?= $row['status']=="Pending"?"selected":"" ?> value="Pending">Pending</option>
                <option <?= $row['status']=="Approved"?"selected":"" ?> value="Approved">Approved</option>
                <option <?= $row['status']=="Cancelled"?"selected":"" ?> value="Cancelled">Cancelled</option>
              </select>
              <button type="submit">💾 Save</button>
            </form>
            <!-- Delete -->
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this appointment?');">
              <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
              <button type="submit">🗑️ Delete</button>
            </form>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <script>
    function toggleForm(){
      const f=document.getElementById("appointmentForm");
      f.style.display=f.style.display==="block"?"none":"block";
    }
    function toggleTheme(){
      document.body.classList.toggle("dark");
      localStorage.setItem("theme",document.body.classList.contains("dark")?"dark":"light");
    }
    if(localStorage.getItem("theme")==="dark"){document.body.classList.add("dark");}

    // Search Filter
    document.getElementById("searchBar").addEventListener("keyup", function(){
      let filter = this.value.toLowerCase();
      let rows = document.querySelectorAll("table tbody tr");
      rows.forEach(r=>{
        let text = r.innerText.toLowerCase();
        r.style.display = text.includes(filter) ? "" : "none";
      });
    });

    // FullCalendar init
    document.addEventListener('DOMContentLoaded', function() {
      var calendarEl = document.getElementById('calendar');
      var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        height: 600,
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'dayGridMonth,listWeek'
        },
        events: <?= $events_json ?>
      });
      calendar.render();
    });

    // Live Clock
    function updateClock() {
      const now = new Date();
      document.getElementById("clock").innerText =
        now.toLocaleDateString() + " " + now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();
  </script>

  <?php
  if (!empty($_SESSION['flash'])) {
      $msg = addslashes($_SESSION['flash']);
      echo "<script>alert('{$msg}');</script>";
      unset($_SESSION['flash']);
  }
  ?>
</body>
</html>
