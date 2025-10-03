<?php
session_start();
require '../db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        header("Location: index.php"); // or client/index.php if that's your homepage
        exit();

    } else {
        $error = "❌ Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login - Client</title>
<style>
body { font-family: Arial, sans-serif; background:#f4f4f4; }
.container { width:400px; margin:50px auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.1);}
h2 { text-align:center; color:#10627b; }
input[type=text], input[type=password] { width:100%; padding:10px; margin:8px 0; border:1px solid #ccc; border-radius:4px; }
button { width:100%; padding:10px; background:#10627b; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:bold; }
button:hover { background:#2596be; }
.error { color:red; margin-bottom:10px; }
</style>
</head>
<body>
<div class="container">
    <h2>Login</h2>
    <?php if(!empty($error)): ?>
        <p class="error"><?= $error ?></p>
    <?php endif; ?>
    <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    <p style="text-align:center;">Don’t have an account? <a href="register.php">Register</a></p>
</div>
</body>
</html>
