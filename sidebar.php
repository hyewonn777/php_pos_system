<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<div class="sidebar">
  <div class="brand-row">
    <h2>Admin Panel</h2>
    <button class="dark-toggle" id="darkToggle">Dark</button>
  </div>
  <ul>
    <li><a href="index.php">Dashboard</a></li>
    <li><a href="sales.php">Sales & Tracking</a></li>
    <li><a href="stock.php">Product / Stock</a></li>
    <li><a href="billing.php">Billing</a></li>
    <li><a href="calendar.php">Calendar</a></li>
    <li><a href="bookings.php">Bookings (Photo/Video)</a></li>
    <li><a href="orders.php">Orders (Printing)</a></li>
    <li><a href="logout.php">Logout</a></li>
  </ul>
</div>

<script>
  // Persisted dark mode
  (function(){
    const key='admin_dark_mode';
    const root=document.documentElement;
    const btn=document.getElementById('darkToggle');
    const saved=localStorage.getItem(key);
    if(saved==='1'){ root.classList.add('dark'); if(btn) btn.textContent='Light'; }
    if(btn){
      btn.addEventListener('click',()=>{
        root.classList.toggle('dark');
        const on=root.classList.contains('dark');
        localStorage.setItem(key, on ? '1' : '0');
        btn.textContent = on ? 'Light' : 'Dark';
      });
    }
  })();
</script>
