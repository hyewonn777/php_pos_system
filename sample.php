<?php
include 'db.php';

// Add Account
if (isset($_POST['create'])) {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $fullname = $_POST['fullname'];
    $role = $_POST['role'];

    $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $password, $fullname, $role);

    if ($stmt->execute()) {
        $msg = "✅ $role account created successfully!";
    } else {
        $msg = "❌ Error: " . $conn->error;
    }
}

// Update Admin Account
if (isset($_POST['update_admin'])) {
    $id = $_POST['id'];
    $username = $_POST['username'];
    $fullname = $_POST['fullname'];
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_BCRYPT) : null;

    if ($password) {
        $stmt = $conn->prepare("UPDATE users SET username=?, fullname=?, password=? WHERE id=? AND role='Admin'");
        $stmt->bind_param("sssi", $username, $fullname, $password, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username=?, fullname=? WHERE id=? AND role='Admin'");
        $stmt->bind_param("ssi", $username, $fullname, $id);
    }

    if ($stmt->execute()) {
        $msg = "✅ Admin account updated successfully!";
    } else {
        $msg = "❌ Error: " . $conn->error;
    }
}

// Delete Account
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];

    // Check role first
    $roleCheck = $conn->query("SELECT role FROM users WHERE id=$id")->fetch_assoc()['role'];

    if ($roleCheck == 'Admin') {
        $countAdmins = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='Admin'")->fetch_assoc()['total'];
        if ($countAdmins > 1) {
            $conn->query("DELETE FROM users WHERE id=$id");
            $msg = "🗑 Admin account deleted!";
        } else {
            $msg = "⚠ Cannot delete the last Admin account!";
        }
    } else {
        $conn->query("DELETE FROM users WHERE id=$id");
        $msg = "🗑 Cashier account deleted!";
    }
}

// Delete All Cashiers
if (isset($_POST['delete_cashiers'])) {
    $conn->query("DELETE FROM users WHERE role='Cashier'");
    $msg = "🗑 All cashier accounts deleted!";
}

// Fetch Accounts
$users = $conn->query("SELECT * FROM users ORDER BY role ASC, id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Account Management</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
    .container { max-width: 1000px; margin: auto; }
    h2 { margin-bottom: 15px; }
    .msg { margin-bottom: 15px; padding: 10px; background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; }
    .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 20px; }
    input, select, button { padding: 10px; margin: 5px; border-radius: 6px; border: 1px solid #ccc; }
    button { background: #1976d2; color: #fff; border: none; cursor: pointer; }
    button:hover { background: #1565c0; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
    th { background: #1976d2; color: #fff; }
    .delete-btn { background: #d32f2f; }
    .delete-btn:hover { background: #b71c1c; }
    .update-btn { background: #388e3c; }
    .update-btn:hover { background: #2e7d32; }
  </style>
</head>
<body>
<div class="container">
  <h2>👤 Account Management</h2>

  <?php if (!empty($msg)) echo "<div class='msg'>$msg</div>"; ?>

  <div class="card">
    <h3>Create New Account</h3>
    <form method="POST">
      <input type="text" name="fullname" placeholder="Full Name" required>
      <input type="text" name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <select name="role">
        <option value="Cashier">Cashier</option>
        <option value="Admin">Admin</option>
      </select>
      <button type="submit" name="create">Create Account</button>
    </form>
  </div>

  <div class="card">
    <h3>Existing Accounts</h3>
    <form method="POST">
      <button type="submit" name="delete_cashiers" class="delete-btn">🗑 Delete All Cashiers</button>
    </form>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Full Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while($row = $users->fetch_assoc()) { ?>
        <tr>
          <form method="POST">
            <td><?= $row['id'] ?></td>
            <td><input type="text" name="fullname" value="<?= $row['fullname'] ?>"></td>
            <td><input type="text" name="username" value="<?= $row['username'] ?>"></td>
            <td><?= $row['role'] ?></td>
            <td>
              <?php if ($row['role'] == 'Admin') { ?>
                <input type="password" name="password" placeholder="New Password (optional)">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <button type="submit" name="update_admin" class="update-btn">Update</button>
              <?php } ?>
              <a href="?delete=<?= $row['id'] ?>" class="delete-btn" style="padding:8px 12px; color:white; text-decoration:none;">Delete</a>
            </td>
          </form>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
