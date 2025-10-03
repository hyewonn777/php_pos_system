<?php
session_start();
require '../db.php'; // adjust path if needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username === '' || $password === '') {
        echo "All fields are required!";
    } else {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'customer')");
        $stmt->bind_param("ss", $username, $passwordHash);

        if ($stmt->execute()) {
            echo "✅ Customer account created successfully!<br>";
            echo "<a href='login.php'>Go to Login</a>";
        } else {
            echo "❌ Error: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Customer Signup</title>
</head>
<body>
    <h1>Create Customer Account (Test)</h1>
    <form method="POST">
        <label>Username:</label><br>
        <input type="text" name="username" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Sign Up</button>
    </form>
</body>
</html>
