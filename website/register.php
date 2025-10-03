<?php
session_start();
require '../db.php';

$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');

    // Validation
    if (empty($fullname) || empty($email) || empty($username) || empty($password) || empty($confirm)) {
        $errors[] = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    } elseif ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    } else {
        // Check duplicate username or email
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $errors[] = "Username or Email already exists.";
        } else {
            // Insert new user (role=client by default)
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password_hash, role) VALUES (?, ?, ?, ?, 'customer')");
            $stmt->bind_param("ssss", $fullname, $email, $username, $hash);
            if ($stmt->execute()) {
                $success = "Account created successfully. <a href='login.php'>Login here</a>.";
            } else {
                $errors[] = "Error: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register - Client</title>
<style>
body { font-family: Arial, sans-serif; background:#f4f4f4; }
.container { width:400px; margin:50px auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.1);}
h2 { text-align:center; color:#10627b; }
input[type=text], input[type=password], input[type=email] { width:100%; padding:10px; margin:8px 0; border:1px solid #ccc; border-radius:4px; }
button { width:100%; padding:10px; background:#10627b; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:bold; }
button:hover { background:#2596be; }
.error { color:red; margin-bottom:10px; }
.success { color:green; margin-bottom:10px; }
</style>
</head>
<body>
<div class="container">
    <h2>Create Account</h2>
    <?php foreach($errors as $e): ?>
        <p class="error"><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
    <?php if(!empty($success)): ?>
        <p class="success"><?= $success ?></p>
    <?php endif; ?>
    <form method="POST">
        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit">Register</button>
    </form>
    <p style="text-align:center;">Already have an account? <a href="login.php">Login</a></p>
</div>
</body>
</html>
