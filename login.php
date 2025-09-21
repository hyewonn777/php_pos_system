<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && hash('sha256', $password) === $user['password_hash']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
        exit();
    } else {
        $error = "❌ Invalid username or password!";
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');

    :root{
      --accent1: #00f2fe;
      --accent2: #4facfe;
      --glass: rgba(255,255,255,0.08);
      --glass-strong: rgba(255,255,255,0.12);
      --text: #ffffff;

     /* Default: Dark mode */
      --bg: #000;
      --text: #ffffff;
      --glass: rgba(255, 255, 255, 0.08);
      --glass-strong: rgba(255, 255, 255, 0.12);
      --placeholder: rgba(255, 255, 255, 0.6);
      --error-bg: rgba(255,0,0,0.08);
      --error-text: #ffb3b3;
    }

    body.light {
    --bg: #f5f7fb;
    --text: #111;
    --glass: rgba(0, 0, 0, 0.05);
    --glass-strong: rgba(0, 0, 0, 0.1);
    --placeholder: rgba(0, 0, 0, 0.5);
    --error-bg: rgba(255,0,0,0.1);
    --error-text: #b30000;
  }



    html,body {
        height:100%;
        margin:0;
        font-family:'Poppins',system-ui,Segoe UI,Roboto,Arial;
    }

    /* update inputs */
  input[type="text"], input[type="password"] {
    width: 80%;
    height: 26px;
    padding: 10px 12px;
    border-radius: 10px;
    border: none;
    outline: none;
    background: var(--glass);
    color: var(--text);
    font-size: 15px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.02);
    transition: background 0.3s ease, color 0.3s ease;
  }
  input::placeholder { color: var(--placeholder); }

    body {
      display:flex;
      align-items:center;
      justify-content:center;
      background:#000;
      overflow:hidden;
    }

    /* background GIF/video-like */
    body::before {
      content:"";
      position:fixed;
      inset:0;
      background: url('assets/bg-loop.gif') center/cover no-repeat;
      filter: saturate(.9) contrast(.9);
      z-index:-2;
    }
    /* dark overlay for legibility */
    body::after {
      content:"";
      position:fixed;
      inset:0;
      background: linear-gradient(180deg, rgba(4,9,30,0.45), rgba(4,9,30,0.6));
      z-index:-1;
    }

    .login-wrap {
      width: 640px;
      max-width: 94%;
      display: grid;
      grid-template-columns: 1fr 420px;
      gap: 28px;
      align-items: center;
    }

    /* left promo / branding panel */
    .promo {
      padding: 28px;
      border-radius: 16px;
      background: linear-gradient(135deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.04);
      color: var(--text);
      box-shadow: 0 8px 30px rgba(0,0,0,0.6);
    }
    .promo h1{ margin:0 0 12px; font-size:28px; color:var(--accent1); text-shadow:0 0 10px rgba(0,242,254,0.15); }
    .promo p{ margin:0; color: #e6eef8dd; line-height:1.5; }

    .login-box{
      background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.03));
      padding: 26px;
      border-radius: 14px;
      width:100%;
      color:var(--text);
      box-shadow: 0 8px 30px rgba(4,9,30,0.6);
      border: 1px solid rgba(255,255,255,0.06);
      backdrop-filter: blur(8px);
    }

    .login-box h2{
      margin:0 0 18px;
      font-size:22px;
      color: var(--accent1);
      text-shadow: 0 0 8px rgba(0,242,254,0.12);
    }

    .error {
      background: rgba(255,0,0,0.08);
      color: #ffb3b3;
      padding:8px 12px;
      border-radius:8px;
      margin-bottom:12px;
      font-weight:600;
    }

    form { width:100%; }

    /* Symmetrical rows: label left, input right */
    .form-row{
      display:flex;
      align-items:center;
      gap:12px;
      margin-bottom:14px;
    }
    .form-row label{
      width:110px;  /* fixed label column */
      text-align:right;
      font-size:14px;
      color:#dbeafe;
      font-weight:600;
    }
    .form-row .input-wrap{
      flex:1;
    }

    input[type="text"], input[type="password"]{
      width:80%;
      height:26px;
      padding:10px 12px;
      border-radius:10px;
      border:none;
      outline:none;
      background: var(--glass);
      color:var(--text);
      font-size:15px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.02);
    }
    input::placeholder { color: rgba(255,255,255,0.6); }

    .actions{
      display:flex;
      gap:10px;
      align-items:center;
      margin-top:6px;
    }

    button.primary{
      background: linear-gradient(90deg,var(--accent1),var(--accent2));
      color:#001;
      border:none;
      padding:12px 16px;
      border-radius:10px;
      cursor:pointer;
      font-weight:700;
      box-shadow:0 8px 30px rgba(76,172,254,0.12);
      transition: transform .12s ease, box-shadow .12s ease;
    }
    button.primary:hover{ transform: translateY(-3px); box-shadow:0 14px 50px rgba(76,172,254,0.18); }

    .hint { font-size:13px; color:#bcd7ff; margin-left:auto; }

    /* responsive: stack label on top for narrow screens */
    @media (max-width:720px){
      .login-wrap{ grid-template-columns: 1fr; gap:16px; padding: 12px; }
      .form-row{ flex-direction: column; align-items:stretch; }
      .form-row label{ width:auto; text-align:left; margin-bottom:6px; }
      .promo{ display:none; }
      .login-box{ padding:20px; }
    }
    /* For logo */
    .logo {
        text-align: center;
        margin-bottom: 20px;
    }

    .logo img { 
        max-width: 120px;
        height: auto;
        display: block;
        margin: 0 auto;
    }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="promo" aria-hidden="true">
      <h1>Marcomedia</h1>
      <div class="logo">
        <img src="images/rsz_logo.png" alt="Logo">
    </div>
      <p>The best place to get customized! with 
      secure admin access — manage products, sales, appointments and users. This panel supports role-based access (coming soon).</p>
    </div>

    <div class="login-box" role="main" aria-labelledby="loginTitle">
      <h2 id="loginTitle">Admin Login</h2>

      <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>

      <form method="POST" autocomplete="off" novalidate>
        <div class="form-row">
          <label for="username">Username</label>
          <div class="input-wrap">
            <input id="username" name="username" type="text" placeholder="Enter your username" required autofocus>
          </div>
        </div>

        <div class="form-row">
          <label for="password">Password</label>
          <div class="input-wrap">
            <input id="password" name="password" type="password" placeholder="Enter your password" required>
          </div>
        </div>

        <div class="form-row" style="margin-top:8px;">
          <div style="width:110px;"></div>
          <div class="input-wrap" style="display:flex; gap:10px; align-items:center;">
            <button class="primary" type="submit">Login</button>
            <div class="hint">Need an account? Ask the admin.</div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    // small enhancement: focus username on load
    document.getElementById('username')?.focus();


  </script>
</body>
</html>
