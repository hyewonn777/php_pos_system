    <?php
require 'auth.php';
require 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Delete appointment ---
    if (isset($_POST['delete_id'])) {
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
        $service  = trim($_POST['service']);
        $date     = $_POST['date'];
        $status   = $_POST['status'];

        $stmt = $conn->prepare("UPDATE appointments SET customer=?, service=?, `date`=?, status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssssi", $customer, $service, $date, $status, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = "Appointment updated.";
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Add appointment ---
    if (!empty($_POST['customer']) && !empty($_POST['service']) && !empty($_POST['date']) && !empty($_POST['status'])) {
        $customer = trim($_POST['customer']);
        $service  = trim($_POST['service']);
        $date     = $_POST['date'];
        $status   = $_POST['status'];

        $stmt = $conn->prepare("INSERT INTO appointments (customer, service, `date`, status, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ssss", $customer, $service, $date, $status);
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

// --- Fetch events for calendar ---
$events = [];
$res = $conn->query("SELECT * FROM appointments");
while ($row = $res->fetch_assoc()) {

    $color = "#3498db"; // default
    if ($row['status'] === "vacant")   $color = "#2ecc71"; // green
    if ($row['status'] === "occupied") $color = "#e74c3c"; // red
    if ($row['status'] === "pending")  $color = "#f1c40f"; // yellow

    $events[] = [
        "id"    => $row['id'],
        "title" => $row['customer'] . " (" . $row['service'] . ")",
        "start" => $row['date'],
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
    :root { --bg:#f9f9f9; --text:#222; --card-bg:#fff; --sidebar-bg:#2c3e50; --sidebar-text:#ecf0f1; }
    .dark { --bg:#1e1e1e; --text:#f5f5f5; --card-bg:#2c2c2c; --sidebar-bg:#111; --sidebar-text:#bbb; }
    body { margin:0; font-family:Arial,sans-serif; background:var(--bg); color:var(--text); display:flex; transition:all .3s ease; }
    .sidebar { width:220px; background:var(--sidebar-bg); color:var(--sidebar-text); height:100vh; padding:20px; display:flex; flex-direction:column; }
    .sidebar h2{text-align:center;margin-bottom:20px;}
    .sidebar ul{list-style:none;padding:0;flex:1;}
    .sidebar ul li{margin:15px 0;}
    .sidebar ul li a{color:var(--sidebar-text);text-decoration:none;}
    .sidebar ul li a:hover{text-decoration:underline;}
    .logout{text-align:center;margin-top:auto;}
    .logout button{width:100%;padding:8px;border:none;border-radius:5px;background:#e74c3c;color:white;font-weight:bold;cursor:pointer;}
    .logout button:hover{background:#c0392b;}
    .content{flex:1;padding:20px;}
    .topbar{display:flex;justify-content:flex-end;margin-bottom:20px;}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-top:20px;}
    .card{background:var(--card-bg);padding:20px;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);text-align:center;font-size:18px;font-weight:bold;transition:transform 0.2s ease;cursor:pointer;}
    .card:hover{transform:translateY(-3px);}
    button.toggle-btn{cursor:pointer;padding:8px 12px;border-radius:5px;border:none;background:#3498db;color:white;font-weight:bold;}
    button.toggle-btn:hover{background:#2980b9;}
    #appointmentForm{display:none;margin-top:20px;padding:20px;background:var(--card-bg);border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);}
    #appointmentForm input,#appointmentForm select{display:block;margin:10px 0;padding:8px;width:100%;border:1px solid #ccc;border-radius:5px;}
    #appointmentForm button{padding:10px 15px;background:#27ae60;color:white;border:none;border-radius:5px;cursor:pointer;}
    #appointmentForm button:hover{background:#219150;}
    table{width:100%;margin-top:20px;border-collapse:collapse;}
    table,th,td{border:1px solid #ccc;}
    th,td{padding:10px;text-align:center;}
    #calendar { max-width: 900px; margin: 30px auto; background: var(--card-bg); padding: 15px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
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
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <h1>Appointments / Booking</h1>

    </div>
    <p>Manage client bookings below.</p>

    <div class="cards">
      <div class="card" onclick="toggleForm()">➕ Add Appointment</div>
      <div class="card">📊 Total Appointments:
        <?php $res=$conn->query("SELECT COUNT(*) as c FROM appointments"); $row=$res->fetch_assoc(); echo intval($row['c']); ?>
      </div>
    </div>

    <!-- Calendar -->
    <div id="calendar"></div>

    <!-- Add Appointment Form -->
    <form id="appointmentForm" method="POST">
      <h3>New Appointment</h3>
      <input type="text" name="customer" placeholder="Customer Name" required>
      <select name="service" required>
        <option value="">-- Select Service --</option>
        <option value="Photography">Photography</option>
        <option value="Videography">Videography</option>
      </select>
      <input type="date" name="date" required>
      <select name="status" required>
        <option value="vacant">Vacant</option>
        <option value="occupied">Occupied</option>
        <option value="pending">Pending</option>
      </select>
      <button type="submit">Add Appointment</button>
    </form>

    <!-- Appointments Table -->
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Customer</th>
          <th>Service</th>
          <th>Date</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
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
          <td><?= htmlspecialchars($row['service']) ?></td>
          <td><?= htmlspecialchars($row['date']) ?></td>
          <td><?= htmlspecialchars($row['status']) ?></td>
          <td><?= htmlspecialchars($row['created_at']) ?></td>
          <td>
            <!-- Edit -->
            <form method="POST" style="display:inline;">
              <input type="hidden" name="edit_id" value="<?= intval($row['id']) ?>">
              <input type="text" name="customer" value="<?= htmlspecialchars($row['customer']) ?>" required>
              <select name="service" required>
                <option <?= $row['service']=="Photography"?"selected":"" ?> value="Photography">Photography</option>
                <option <?= $row['service']=="Videography"?"selected":"" ?> value="Videography">Videography</option>
              </select>
              <input type="date" name="date" value="<?= htmlspecialchars($row['date']) ?>" required>
              <select name="status" required>
                <option <?= $row['status']=="vacant"?"selected":"" ?> value="vacant">Vacant</option>
                <option <?= $row['status']=="occupied"?"selected":"" ?> value="occupied">Occupied</option>
                <option <?= $row['status']=="pending"?"selected":"" ?> value="pending">Pending</option>
              </select>
              <button type="submit" style="background:#27ae60;color:white;border:none;padding:6px 12px;border-radius:5px;cursor:pointer;">💾 Save</button>
            </form>
            <!-- Delete -->
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this appointment?');">
              <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
              <button type="submit" style="background:#e74c3c;color:white;border:none;padding:6px 12px;border-radius:5px;cursor:pointer;">🗑 Delete</button>
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
  if (!empty($_SESSION['flash'])) {
      $msg = addslashes($_SESSION['flash']);
      echo "<script>alert('{$msg}');</script>";
      unset($_SESSION['flash']);
  }
  ?>
</body>
</html>
