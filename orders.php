<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Orders</title>
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
      --bg: #1e1e1e;
      --text: #f5f5f5;
      --card-bg: #2c2c2c;
      --sidebar-bg: #111;
      --sidebar-text: #bbb;
    }

    body {
      margin: 0; 
      font-family: Arial, sans-serif;
      background: var(--bg); 
      color: var(--text);
      display: flex; 
      transition: all 0.3s ease;
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
      padding:20px; 
    }
    
    .topbar { 
      display:flex; 
      justify-content:flex-end; 
      margin-bottom:20px; 
    }

    .cards {
      display: grid; 
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px; 
      margin-top: 20px;
    }

    .card {
      background: var(--card-bg); 
      padding: 20px; 
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center; 
      font-size: 18px; 
      font-weight: bold;
      transition: transform 0.2s ease;
    }

    .card:hover { 
      transform: translateY(-3px); 
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

    button.toggle-btn:hover { 
        background: #2980b9; 
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
    <h1>Order Tracking</h1>
    <p>Track customer orders and delivery status here.</p>
    <table border="1" cellpadding="10">
      <tr><th>Order ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th></tr>
      <tr><td>1</td><td>Maria Clara</td><td>2x Product A</td><td>₱500.00</td><td>Shipped</td></tr>
    </table>
  </div>
    <script>
    function toggleTheme() {
      document.body.classList.toggle("dark");
      localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
    }
    if (localStorage.getItem("theme") === "dark") {
      document.body.classList.add("dark");
    }
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

</body>
</html>
