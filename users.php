<?php
require 'auth.php';
require 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ====== Handle POST actions: create, edit, delete ======
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CREATE
    if (isset($_POST['action']) && $_POST['action'] === 'create_user') {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] === 'Admin' ? 'Admin' : 'Cashier';

        // basic validation
        if ($fullname === '' || $username === '' || $email === '' || $password === '') {
            $_SESSION['flash'] = 'Please fill all fields.';
            header('Location: users.php'); exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = 'Invalid email.';
            header('Location: users.php'); exit;
        }

        // unique username/email
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $_SESSION['flash'] = 'Username or email already exists.';
            header('Location: users.php'); exit;
        }
        $stmt->close();

        // hash password
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (fullname, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $fullname, $username, $email, $hash, $role);
        if ($stmt->execute()) {
            $_SESSION['flash'] = 'User created.';
        } else {
            $_SESSION['flash'] = 'Error creating user: ' . $stmt->error;
        }
        $stmt->close();
        header('Location: users.php'); exit;
    }

    // EDIT
    if (isset($_POST['action']) && $_POST['action'] === 'edit_user' && !empty($_POST['edit_id'])) {
        $id = intval($_POST['edit_id']);
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] === 'Admin' ? 'Admin' : 'Cashier';
        $password = $_POST['password'] ?? '';

        if ($fullname === '' || $username === '' || $email === '') {
            $_SESSION['flash'] = 'Please fill required fields.';
            header('Location: users.php'); exit;
        }

        // check username/email conflicts (excluding this id)
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
        $stmt->bind_param("ssi", $username, $email, $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $_SESSION['flash'] = 'Username or email already taken.';
            header('Location: users.php'); exit;
        }
        $stmt->close();

        // if password provided, update hash
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, email=?, password_hash=?, role=? WHERE id=?");
            $stmt->bind_param("sssssi", $fullname, $username, $email, $hash, $role, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, email=?, role=? WHERE id=?");
            $stmt->bind_param("ssssi", $fullname, $username, $email, $role, $id);
        }
        if ($stmt->execute()) {
            $_SESSION['flash'] = 'User updated.';
        } else {
            $_SESSION['flash'] = 'Update failed: ' . $stmt->error;
        }
        $stmt->close();
        header('Location: users.php'); exit;
    }

    // DELETE
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user' && !empty($_POST['delete_id'])) {
        $id = intval($_POST['delete_id']);
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = 'User deleted.';
        } else {
            $_SESSION['flash'] = 'Delete failed: ' . $stmt->error;
        }
        $stmt->close();
        header('Location: users.php'); exit;
    }
}

// fetch users
$users = [];
$res = $conn->query("SELECT id, fullname, username, email, role, created_at FROM users ORDER BY id DESC");
while ($r = $res->fetch_assoc()) $users[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Users - Admin</title>
  <link rel="stylesheet" href="css/admin.css">
  <style>
    /* small modal + table styles */
    .topbar-clock { font-size:14px; margin-right:12px; color:#999; }
    .users-wrap { margin-top: 10px; }
    table.users { width:100%; border-collapse: collapse; margin-top:16px; }
    table.users th, table.users td { padding:8px 10px; border:1px solid #ddd; text-align:left; }
    .btn { padding:8px 12px; border-radius:6px; cursor:pointer; border:none; }
    .btn-primary { background:#3498db; color:#fff; }
    .btn-danger { background:#e74c3c; color:#fff; }
    .btn-ghost { background:transparent; border:1px solid #ccc; color:#333; }
    /* modal */
    .modal { position:fixed; left:0; top:0; right:0; bottom:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); z-index:9999; }
    .modal .sheet { background:#fff; width:480px; border-radius:8px; padding:18px; box-shadow:0 8px 30px rgba(0,0,0,0.2); }
    .modal .sheet h3 { margin-top:0; }
    .form-row { margin-bottom:10px; }
    .form-row input, .form-row select { width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; }
    .modal-actions { display:flex; justify-content:space-between; gap:10px; margin-top:14px; }
    .generate-pass { margin-left:8px; }
    .small { font-size:13px; color:#666; }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; /* or paste your sidebar markup */ ?>

  <div class="content">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h1>Users</h1>
      <div style="display:flex; align-items:center;">
        <div class="topbar-clock" id="topClock"></div>
        <button class="btn btn-primary" onclick="openCreate()">Add User</button>
      </div>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div style="margin:10px 0; color:green;"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>

    <div class="users-wrap">
      <table class="users">
        <thead>
          <tr>
            <th>ID</th><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Created</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><?= intval($u['id']) ?></td>
              <td><?= htmlspecialchars($u['fullname']) ?></td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><?= htmlspecialchars($u['role']) ?></td>
              <td><?= htmlspecialchars($u['created_at']) ?></td>
              <td>
                <!-- Edit button toggles modal with prefilled fields -->
                <button class="btn btn-ghost" onclick='openEdit(<?= json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                <form style="display:inline" method="POST" onsubmit="return confirm('Delete this user?');">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="delete_id" value="<?= intval($u['id']) ?>">
                  <button class="btn btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Modal (create / edit) -->
  <div id="userModal" class="modal">
    <div class="sheet">
      <h3 id="modalTitle">Create User</h3>

      <form id="userForm" method="POST" onsubmit="return submitUserForm();">
        <input type="hidden" name="action" id="formAction" value="create_user">
        <input type="hidden" name="edit_id" id="edit_id" value="">

        <div class="form-row">
          <label class="small">Full Name</label>
          <input type="text" name="fullname" id="fullname" required>
        </div>

        <div class="form-row">
          <label class="small">Username</label>
          <input type="text" name="username" id="username" required>
        </div>

        <div class="form-row">
          <label class="small">Email</label>
          <input type="email" name="email" id="email" required>
        </div>

        <div class="form-row" style="display:flex; gap:8px; align-items:center;">
          <div style="flex:1;">
            <label class="small">Password</label>
            <input type="password" name="password" id="password">
          </div>
          <div style="display:flex; flex-direction:column; gap:6px;">
            <button type="button" class="btn btn-ghost generate-pass" onclick="generatePassword()">Generate</button>
            <label style="font-size:12px;"><input type="checkbox" id="showPass"> Show</label>
          </div>
        </div>

        <div class="form-row">
          <label class="small">Role</label>
          <select name="role" id="role">
            <option value="Cashier">Cashier</option>
            <option value="Admin">Admin</option>
          </select>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModal()">Dismiss</button>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>

<script>
  // ==== Clock: device-based realtime ====
  function startClock(elId='topClock'){
    function update(){
      const d = new Date();
      const s = d.toLocaleTimeString();
      document.getElementById(elId).textContent = s;
    }
    update(); setInterval(update, 1000);
  }
  startClock();

  // ==== modal helpers ====
  function openCreate(){
    document.getElementById('modalTitle').textContent = 'Create User';
    document.getElementById('formAction').value = 'create_user';
    document.getElementById('edit_id').value = '';
    document.getElementById('fullname').value = '';
    document.getElementById('username').value = '';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    document.getElementById('role').value = 'Cashier';
    document.getElementById('userModal').style.display = 'flex';
  }
  function openEdit(userJson){
    document.getElementById('modalTitle').textContent = 'Edit User';
    document.getElementById('formAction').value = 'edit_user';
    document.getElementById('edit_id').value = userJson.id;
    document.getElementById('fullname').value = userJson.fullname;
    document.getElementById('username').value = userJson.username;
    document.getElementById('email').value = userJson.email;
    document.getElementById('password').value = '';
    document.getElementById('role').value = userJson.role;
    document.getElementById('userModal').style.display = 'flex';
  }
  function closeModal(){
    document.getElementById('userModal').style.display = 'none';
  }

  // show password toggle
  document.getElementById('showPass').addEventListener('change', function(){
    const p = document.getElementById('password');
    p.type = this.checked ? 'text' : 'password';
  });

  // generate password (secure-ish)
  function generatePassword(length=12){
    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~`|}{[]:;?><,./-=";
    let pw = '';
    const cryptoObj = window.crypto || window.msCrypto;
    if (cryptoObj && cryptoObj.getRandomValues) {
      const values = new Uint32Array(length);
      cryptoObj.getRandomValues(values);
      for (let i=0;i<length;i++){
        pw += charset[values[i] % charset.length];
      }
    } else {
      for (let i=0;i<length;i++) pw += charset[Math.floor(Math.random()*charset.length)];
    }
    document.getElementById('password').value = pw;
  }

  // submit validation: if create and no password provided => require
  function submitUserForm(){
    const action = document.getElementById('formAction').value;
    const pw = document.getElementById('password').value;
    if (action === 'create_user' && pw.trim() === '') {
      alert('Please provide a password (or use Generate).');
      return false;
    }
    // leave to server for all other checks
    return true;
  }
</script>
</body>
</html>
