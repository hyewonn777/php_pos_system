<?php
// Set session name and start session
session_name('admin_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php'; // mysqli connection

// Enhanced role-based access control for user_management.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if user has admin role
if ($_SESSION['role'] !== 'admin') {
    // Redirect non-admin users based on their role
    if ($_SESSION['role'] === 'photographer') {
        $_SESSION['error'] = "Access denied. Photographers cannot access staff management.";
        header("Location: appointment.php");
    } else {
        $_SESSION['error'] = "Access denied. Admin privileges required.";
        header("Location: login.php");
    }
    exit();
}

// Define current_role for sidebar (FIXED: This was missing)
$current_role = $_SESSION['role'];

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Initialize dark mode from session or default to light
if (!isset($_SESSION['dark_mode'])) {
    $_SESSION['dark_mode'] = false;
}

// Toggle dark mode if requested
if (isset($_GET['toggle_dark_mode'])) {
    $_SESSION['dark_mode'] = !$_SESSION['dark_mode'];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$error = '';
$success = '';

/* -------------------- ADMIN-ONLY USER CRUD OPERATIONS -------------------- */

/* ADD USER */
if (isset($_POST['add_user'])) {
    // Double-check admin privilege
    if ($_SESSION['role'] !== 'admin') {
        $error = "Access denied. Admin privileges required to add users.";
    } else {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password_raw = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? '');

        // Validate inputs
        if (empty($fullname) || empty($username) || empty($password_raw) || empty($role)) {
            $error = "Please fill all required fields.";
        } elseif (strlen($password_raw) < 4) {
            $error = "Password must be at least 4 characters long.";
        } else {
            // Check if username already exists
            $check_sql = "SELECT id FROM users WHERE username = ?";
            $check_stmt = $conn->prepare($check_sql);
            
            if ($check_stmt) {
                $check_stmt->bind_param("s", $username);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $error = "Username already exists!";
                } else {
                    // Insert new user
                    $password_hash = hash('sha256', $password_raw);
                    $insert_sql = "INSERT INTO users (fullname, username, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("ssss", $fullname, $username, $password_hash, $role);
                        
                        if ($insert_stmt->execute()) {
                            $success = "✅ Staff member '$fullname' has been added successfully as $role!";
                            // Clear form
                            $_POST['fullname'] = $_POST['username'] = $_POST['password'] = $_POST['role'] = '';
                        } else {
                            $error = "Error adding user: " . $insert_stmt->error;
                        }
                        $insert_stmt->close();
                    } else {
                        $error = "Database error: " . $conn->error;
                    }
                }
                $check_stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}

/* UPDATE USER */
if (isset($_POST['update_user'])) {
    // Double-check admin privilege
    if ($_SESSION['role'] !== 'admin') {
        $error = "Access denied. Admin privileges required to update users.";
    } else {
        $id = intval($_POST['id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password_raw = trim($_POST['password'] ?? '');

        if ($id <= 0 || empty($fullname) || empty($username) || empty($role)) {
            $error = "Please fill all required fields.";
        } else {
            // Check if username exists for other users
            $check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            
            if ($check_stmt) {
                $check_stmt->bind_param("si", $username, $id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $error = "Username already exists for another user!";
                } else {
                    // Update user
                    $update_stmt = null;
                    if (!empty($password_raw)) {
                        if (strlen($password_raw) < 4) {
                            $error = "Password must be at least 4 characters long.";
                        } else {
                            $password_hash = hash('sha256', $password_raw);
                            $update_sql = "UPDATE users SET fullname=?, username=?, password_hash=?, role=? WHERE id=?";
                            $update_stmt = $conn->prepare($update_sql);
                            if ($update_stmt) {
                                $update_stmt->bind_param("ssssi", $fullname, $username, $password_hash, $role, $id);
                            }
                        }
                    } else {
                        $update_sql = "UPDATE users SET fullname=?, username=?, role=? WHERE id=?";
                        $update_stmt = $conn->prepare($update_sql);
                        if ($update_stmt) {
                            $update_stmt->bind_param("sssi", $fullname, $username, $role, $id);
                        }
                    }

                    if (isset($update_stmt) && $update_stmt) {
                        if ($update_stmt->execute()) {
                            if ($update_stmt->affected_rows > 0) {
                                $success = "✅ Staff member '$fullname' has been updated successfully!";
                            } else {
                                $error = "No changes were made to the user.";
                            }
                            $update_stmt->close();
                        } else {
                            $error = "Error updating user: " . $update_stmt->error;
                        }
                    } elseif (!isset($error)) {
                        $error = "Database error: " . $conn->error;
                    }
                }
                $check_stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}

/* DELETE USER */
if (isset($_POST['delete_user'])) {
    // Double-check admin privilege
    if ($_SESSION['role'] !== 'admin') {
        $error = "Access denied. Admin privileges required to delete users.";
    } else {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            $error = "Invalid user ID.";
        } else {
            // Prevent admin from deleting themselves
            if ($id == $_SESSION['user_id']) {
                $error = "❌ You cannot delete your own account!";
            } else {
                // Get user info for confirmation message
                $user_info_sql = "SELECT fullname, role FROM users WHERE id = ?";
                $user_info_stmt = $conn->prepare($user_info_sql);
                
                if ($user_info_stmt) {
                    $user_info_stmt->bind_param("i", $id);
                    $user_info_stmt->execute();
                    $user_info_result = $user_info_stmt->get_result();
                    
                    if ($user_info_result->num_rows > 0) {
                        $user_data = $user_info_result->fetch_assoc();
                        $user_fullname = $user_data['fullname'];
                        $user_role = $user_data['role'];
                        
                        // Prevent deleting the last admin
                        if ($user_role === 'admin') {
                            $admin_count_sql = "SELECT COUNT(*) as admin_count FROM users WHERE role='admin'";
                            $admin_count_result = $conn->query($admin_count_sql);
                            
                            if ($admin_count_result) {
                                $admin_data = $admin_count_result->fetch_assoc();
                                $admin_count = $admin_data['admin_count'];
                                
                                if ($admin_count <= 1) {
                                    $error = "❌ Cannot delete the last Admin account!";
                                } else {
                                    // Proceed with deletion
                                    $delete_sql = "DELETE FROM users WHERE id = ?";
                                    $delete_stmt = $conn->prepare($delete_sql);
                                    
                                    if ($delete_stmt) {
                                        $delete_stmt->bind_param("i", $id);
                                        if ($delete_stmt->execute()) {
                                            $success = "✅ Staff member '$user_fullname' has been deleted successfully!";
                                        } else {
                                            $error = "Error deleting user: " . $delete_stmt->error;
                                        }
                                        $delete_stmt->close();
                                    } else {
                                        $error = "Database error: " . $conn->error;
                                    }
                                }
                            } else {
                                $error = "Error checking admin accounts: " . $conn->error;
                            }
                        } else {
                            // Delete non-admin user
                            $delete_sql = "DELETE FROM users WHERE id = ?";
                            $delete_stmt = $conn->prepare($delete_sql);
                            
                            if ($delete_stmt) {
                                $delete_stmt->bind_param("i", $id);
                                if ($delete_stmt->execute()) {
                                    $success = "✅ Staff member '$user_fullname' has been deleted successfully!";
                                } else {
                                    $error = "Error deleting user: " . $delete_stmt->error;
                                }
                                $delete_stmt->close();
                            } else {
                                $error = "Database error: " . $conn->error;
                            }
                        }
                    } else {
                        $error = "User not found.";
                    }
                    $user_info_stmt->close();
                } else {
                    $error = "Database error: " . $conn->error;
                }
            }
        }
    }
}

/* FETCH USERS */
$users = [];
$admins = [];
$photographers = [];

// Only fetch users if admin
if ($_SESSION['role'] === 'admin') {
    $res = $conn->query("SELECT * FROM users WHERE role IN ('admin', 'photographer') ORDER BY id ASC");
    if ($res) {
        $users = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        
        // Categorize users
        foreach ($users as $user) {
            if ($user['role'] === 'admin') {
                $admins[] = $user;
            } elseif ($user['role'] === 'photographer') {
                $photographers[] = $user;
            }
        }
    } else {
        $error = "Could not load users: " . $conn->error;
    }
}

// Get user statistics
$totalUsers = count($users);
$totalAdmins = count($admins);
$totalPhotographers = count($photographers);

// Get current time for greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 18) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}

// Safely get username with fallback
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';

// Clear form data after successful submission to prevent resubmission
if ($success) {
    $_POST = [];
}

// Enhanced access logging for admin actions
function logAdminAction($conn, $admin_id, $action, $target_user = null) {
    $log_sql = "INSERT INTO admin_logs (admin_id, action, target_user, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $log_stmt->bind_param("issss", $admin_id, $action, $target_user, $ip_address, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

// Log page access
if ($_SESSION['role'] === 'admin') {
    logAdminAction($conn, $_SESSION['user_id'], 'ACCESS_USER_MANAGEMENT');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #4361ee;
      --primary-dark: #3a56d4;
      --primary-light: #4cc9f0;
      --secondary: #7209b7;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #e63946;
      --info: #4895ef;
      --light: #f8f9fa;
      --dark: #212529;
      --gray: #6c757d;
      --gray-light: #adb5bd;
      --sidebar-width: 260px;
      --header-height: 70px;
      --card-radius: 16px;
      --transition: all 0.3s ease;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.12);
    }

    .dark-mode {
      --bg: #0f1419;
      --card-bg: #1a222d;
      --text: #f1f5f9;
      --text-muted: #94a3b8;
      --sidebar-bg: #0a0f14;
      --border: #2a3341;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.3);
    }

    .light-mode {
      --bg: #f1f5f9;
      --card-bg: #ffffff;
      --text: #1e293b;
      --text-muted: #64748b;
      --sidebar-bg: #1e293b;
      --border: #e2e8f0;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      background-color: var(--bg);
      color: var(--text);
      transition: var(--transition);
      line-height: 1.6;
      overflow-x: hidden;
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Styles */
    .sidebar {
      width: var(--sidebar-width);
      background: linear-gradient(180deg, var(--sidebar-bg) 0%, #151f2e 100%);
      color: white;
      height: 100vh;
      position: fixed;
      overflow-y: auto;
      transition: var(--transition);
      z-index: 1000;
      box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
    }

    .sidebar-header {
      padding: 24px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(0, 0, 0, 0.2);
    }

    .logo {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .sidebar-title {
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.5px;
    }

    .sidebar-menu {
      padding: 20px 0;
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 14px 20px;
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: var(--transition);
      gap: 14px;
      margin: 4px 12px;
      border-radius: 10px;
      position: relative;
      overflow: hidden;
    }

    .menu-item:before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transition: left 0.5s;
    }

    .menu-item:hover:before {
      left: 100%;
    }

    .menu-item:hover, .menu-item.active {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      transform: translateX(5px);
    }

    .menu-item.active {
      background: linear-gradient(90deg, rgba(67, 97, 238, 0.3) 0%, rgba(67, 97, 238, 0.1) 100%);
      border-left: 4px solid var(--primary);
    }

    .menu-item i {
      width: 20px;
      text-align: center;
      font-size: 18px;
    }

    .sidebar-footer {
      padding: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: auto;
    }

    .logout-btn {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      border: none;
      padding: 12px;
      width: 100%;
      border-radius: 10px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: var(--transition);
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.3);
    }

    .logout-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(230, 57, 70, 0.4);
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 25px;
      transition: var(--transition);
      background: var(--bg);
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 0 25px 0;
      margin-bottom: 30px;
      position: relative;
    }

    .header:after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--border), transparent);
    }

    .header-title h1 {
      font-size: 32px;
      font-weight: 800;
      margin-bottom: 8px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.5px;
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 16px;
      font-weight: 500;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .theme-toggle, .notification-btn, .user-menu, .menu-toggle {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--card-bg);
      color: var(--text);
      cursor: pointer;
      transition: var(--transition);
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }

    .theme-toggle:before, .notification-btn:before, .user-menu:before, .menu-toggle:before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(67, 97, 238, 0.1), transparent);
      transition: left 0.5s;
    }

    .theme-toggle:hover:before, .notification-btn:hover:before, .user-menu:hover:before, .menu-toggle:hover:before {
      left: 100%;
    }

    .theme-toggle:hover, .notification-btn:hover, .user-menu:hover, .menu-toggle:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 24px;
      margin-bottom: 40px;
    }

    .stat-card {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border-left: 4px solid var(--primary);
      cursor: pointer;
      position: relative;
      overflow: hidden;
    }

    .stat-card:before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.5s ease;
    }

    .stat-card:hover:before {
      transform: scaleX(1);
    }

    .stat-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-hover);
    }

    .stat-card.warning {
      border-left-color: var(--warning);
    }

    .stat-card.warning:before {
      background: linear-gradient(90deg, var(--warning) 0%, #fbbf24 100%);
    }

    .stat-card.danger {
      border-left-color: var(--danger);
    }

    .stat-card.danger:before {
      background: linear-gradient(90deg, var(--danger) 0%, #c53030 100%);
    }

    .stat-card.success {
      border-left-color: var(--success);
    }

    .stat-card.success:before {
      background: linear-gradient(90deg, var(--success) 0%, #34d399 100%);
    }

    .stat-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(67, 97, 238, 0.05) 100%);
      color: var(--primary);
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.15);
    }

    .stat-card.warning .stat-icon {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%);
      color: var(--warning);
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
    }

    .stat-card.danger .stat-icon {
      background: linear-gradient(135deg, rgba(230, 57, 70, 0.1) 0%, rgba(230, 57, 70, 0.05) 100%);
      color: var(--danger);
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.15);
    }

    .stat-card.success .stat-icon {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: var(--success);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
    }

    .stat-value {
      font-size: 32px;
      font-weight: 800;
      margin-bottom: 8px;
      background: linear-gradient(90deg, var(--text) 0%, var(--text-muted) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .stat-label {
      color: var(--text-muted);
      font-size: 15px;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    /* Forms */
    .form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
    }

    .form-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }

    .section-title {
      font-size: 20px;
      font-weight: 700;
      position: relative;
      padding-left: 12px;
    }

    .section-title:before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      height: 100%;
      width: 4px;
      background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
      border-radius: 4px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text-muted);
      font-size: 14px;
    }

    .form-control {
      padding: 14px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--card-bg);
      color: var(--text);
      font-size: 15px;
      transition: var(--transition);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    /* Buttons */
    .btn {
      padding: 14px 24px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 15px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(67, 97, 238, 0.4);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success) 0%, #0da271 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-success:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(230, 57, 70, 0.3);
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(230, 57, 70, 0.4);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
    }

    /* Tables */
    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
      transition: var(--transition);
    }

    .table-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .table {
      width: 100%;
      border-collapse: collapse;
    }

    .table th, .table td {
      padding: 16px 20px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    .table th {
      color: var(--text-muted);
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: rgba(0, 0, 0, 0.02);
    }

    .dark-mode .table th {
      background: rgba(255, 255, 255, 0.02);
    }

    .table tr {
      transition: var(--transition);
    }

    .table tr:hover {
      background: rgba(67, 97, 238, 0.03);
    }

    .action-cell {
      display: flex;
      gap: 8px;
    }

    .action-btn {
      padding: 8px 16px;
      border-radius: 8px;
      border: none;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .action-btn.primary {
      background: rgba(67, 97, 238, 0.1);
      color: var(--primary);
      border: 1px solid rgba(67, 97, 238, 0.2);
    }

    .action-btn.success {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .action-btn.danger {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
      border: 1px solid rgba(230, 57, 70, 0.2);
    }

    .action-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .action-btn.primary:hover {
      background: var(--primary);
      color: white;
    }

    .action-btn.success:hover {
      background: var(--success);
      color: white;
    }

    .action-btn.danger:hover {
      background: var(--danger);
      color: white;
    }

    /* Role Badges */
    .role-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    .role-admin {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .role-photographer {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    /* Flash Message */
    .flash-message {
      padding: 16px 24px;
      border-radius: 12px;
      margin-bottom: 25px;
      font-weight: 600;
      animation: slideIn 0.5s ease-out;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: var(--shadow);
    }

    .flash-success {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border-left: 4px solid var(--success);
    }

    .flash-error {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
      border-left: 4px solid var(--danger);
    }

    /* Welcome Section */
    .welcome-container {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 30px;
    }

    .welcome-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
      font-weight: 700;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .welcome-text h2 {
      font-size: 22px;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .welcome-text p {
      color: var(--text-muted);
      font-size: 15px;
    }

    /* Enhanced Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(5px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      animation: fadeIn 0.3s ease-out;
      padding: 20px;
      overflow: auto;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal.active {
      display: flex;
    }

    .modal-content {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
      width: 100%;
      max-width: 550px;
      max-height: calc(100vh - 40px);
      display: flex;
      flex-direction: column;
      position: relative;
      animation: slideUp 0.4s ease-out;
      border: 1px solid var(--border);
      overflow: hidden;
    }

    @keyframes slideUp {
      from { 
        opacity: 0;
        transform: translateY(30px);
      }
      to { 
        opacity: 1;
        transform: translateY(0);
      }
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 28px 32px 20px;
      background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(76, 201, 240, 0.05) 100%);
      border-bottom: 1px solid var(--border);
      position: relative;
      flex-shrink: 0;
    }

    .modal-header:after {
      content: '';
      position: absolute;
      bottom: -1px;
      left: 0;
      width: 100%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--primary), transparent);
    }

    .modal-title {
      font-size: 24px;
      font-weight: 700;
      background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .modal-title i {
      font-size: 22px;
    }

    .close-modal {
      background: rgba(230, 57, 70, 0.1);
      border: none;
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--danger);
      cursor: pointer;
      transition: var(--transition);
      font-size: 20px;
    }

    .close-modal:hover {
      background: var(--danger);
      color: white;
      transform: rotate(90deg);
    }

    .modal-body {
      padding: 32px;
      overflow-y: auto;
      flex: 1;
    }

    .form-grid-modal {
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
    }

    .form-group-modal {
      display: flex;
      flex-direction: column;
    }

    .form-group-modal label {
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--text-muted);
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .form-group-modal label i {
      color: var(--primary);
      width: 16px;
    }

    .input-with-icon {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-with-icon .form-control {
      padding-right: 50px;
      width: 100%;
    }

    .input-icon {
      position: absolute;
      right: 16px;
      color: var(--text-muted);
      cursor: pointer;
      transition: var(--transition);
      background: none;
      border: none;
      font-size: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 24px;
      height: 24px;
    }

    .input-icon:hover {
      color: var(--primary);
    }

    .password-strength {
      margin-top: 8px;
      height: 4px;
      border-radius: 2px;
      background: var(--border);
      overflow: hidden;
      position: relative;
    }

    .password-strength-bar {
      height: 100%;
      width: 0%;
      border-radius: 2px;
      transition: width 0.3s ease;
    }

    .password-strength.weak .password-strength-bar {
      background: var(--danger);
      width: 33%;
    }

    .password-strength.medium .password-strength-bar {
      background: var(--warning);
      width: 66%;
    }

    .password-strength.strong .password-strength-bar {
      background: var(--success);
      width: 100%;
    }

    .password-hint {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .password-hint i {
      font-size: 10px;
    }

    .role-selector {
      display: flex;
      gap: 12px;
      margin-top: 8px;
    }

    .role-option {
      flex: 1;
      text-align: center;
      padding: 16px 12px;
      border: 2px solid var(--border);
      border-radius: 10px;
      cursor: pointer;
      transition: var(--transition);
      background: var(--card-bg);
    }

    .role-option:hover {
      border-color: var(--primary-light);
      transform: translateY(-2px);
    }

    .role-option.selected {
      border-color: var(--primary);
      background: rgba(67, 97, 238, 0.05);
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.1);
    }

    .role-icon {
      font-size: 24px;
      margin-bottom: 8px;
      color: var(--text-muted);
    }

    .role-option.selected .role-icon {
      color: var(--primary);
    }

    .role-name {
      font-weight: 600;
      font-size: 14px;
      color: var(--text);
    }

    .role-option.selected .role-name {
      color: var(--primary);
    }

    .role-description {
      font-size: 11px;
      color: var(--text-muted);
      margin-top: 4px;
    }

    .modal-footer {
      padding: 20px 32px 28px;
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      border-top: 1px solid var(--border);
      background: rgba(0, 0, 0, 0.02);
      flex-shrink: 0;
    }

    .dark-mode .modal-footer {
      background: rgba(255, 255, 255, 0.02);
    }

    .user-avatar-preview {
      display: flex;
      justify-content: center;
      margin-bottom: 20px;
    }

    .avatar-large {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 32px;
      font-weight: 700;
      box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
    }

    .form-section {
      margin-bottom: 24px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
    }

    .form-section:last-child {
      border-bottom: none;
      margin-bottom: 0;
      padding-bottom: 0;
    }

    .section-label {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 16px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .section-label i {
      color: var(--primary);
    }

    /* Footer */
    .footer {
      text-align: center;
      padding: 24px;
      color: var(--text-muted);
      font-size: 14px;
      border-top: 1px solid var(--border);
      margin-top: 40px;
      font-weight: 500;
    }

    /* Mobile Responsive */
    .menu-toggle {
      display: none;
    }

    @media (max-width: 992px) {
      .sidebar {
        transform: translateX(-100%);
        width: 280px;
      }
      
      .sidebar.active {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        padding: 20px;
      }
      
      .menu-toggle {
        display: flex;
      }
      
      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
      }
      
      .stat-card {
        padding: 20px;
      }
      
      .stat-value {
        font-size: 24px;
      }
      
      .header-title h1 {
        font-size: 26px;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .modal-content {
        width: 95%;
        margin: 20px;
      }
      
      .modal-header,
      .modal-body,
      .modal-footer {
        padding: 20px;
      }
      
      .role-selector {
        flex-direction: column;
      }
      
      .modal-footer {
        flex-direction: column-reverse;
      }
      
      .modal-footer .btn {
        width: 100%;
        justify-content: center;
      }
    }

    @media (max-width: 768px) {
      .main-content {
        padding: 15px;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .welcome-container {
        flex-direction: column;
        text-align: center;
        gap: 10px;
      }
      
      .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      
      .header-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .table-container {
        overflow-x: auto;
      }
      
      .table {
        min-width: 600px;
      }
      
      .action-cell {
        flex-direction: column;
      }
      
      .modal-content {
        width: 95%;
        max-height: calc(100vh - 20px);
      }
      
      .modal-body {
        padding: 20px;
      }
    }

    /* Overlay for mobile sidebar */
    .sidebar-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      display: none;
    }

    .sidebar-overlay.active {
      display: block;
    }

    /* Animations */
    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Form Validation Styles */
    .form-control:invalid {
      border-color: var(--danger);
    }

    .required::after {
      content: " *";
      color: var(--danger);
    }

    /* Toast Notification */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 16px 24px;
      border-radius: 12px;
      color: white;
      font-weight: 600;
      z-index: 10000;
      animation: slideInRight 0.5s ease-out;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      display: flex;
      align-items: center;
      gap: 10px;
      max-width: 400px;
    }

    .toast-success {
      background: linear-gradient(135deg, var(--success) 0%, #0da271 100%);
    }

    .toast-error {
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
    }

    @keyframes slideInRight {
      from {
        opacity: 0;
        transform: translateX(100%);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    @keyframes slideOutRight {
      from {
        opacity: 1;
        transform: translateX(0);
      }
      to {
        opacity: 0;
        transform: translateX(100%);
      }
    }
  </style>
</head>
<body class="<?php echo $_SESSION['dark_mode'] ? 'dark-mode' : 'light-mode'; ?>">
 <!-- Mobile Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">M</div>
        <div class="sidebar-title">Marcomedia POS</div>
    </div>
    <div class="sidebar-menu">
        <?php if ($current_role === 'admin'): ?>
            <!-- Admin sees all menu items -->
            <a href="index.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">Dashboard</span>
            </a>
            <a href="sales.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'sales.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i>
                <span class="menu-text">Sales & Orders</span>
            </a>
            <a href="stock.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'stock.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i>
                <span class="menu-text">Inventory</span>
            </a>
            <a href="physical_orders.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'physical_orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-store"></i>
                <span class="menu-text">Physical Orders</span>
            </a>
            <a href="appointment.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'appointment.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Appointments</span>
            </a>
            <a href="user_management.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'user_management.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-text">Staff Management</span>
            </a>
        <?php else: ?>
            <!-- Photographer sees only appointments -->
            <a href="appointment.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'appointment.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span class="menu-text">Appointments</span>
            </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-footer">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Header -->
    <div class="header">
      <div class="header-title">
        <h1>Staff Management</h1>
        <p>Manage admin and photographer accounts</p>
      </div>
      <div class="header-actions">
        <div class="menu-toggle" id="menuToggle">
          <i class="fas fa-bars"></i>
        </div>
        <a href="?toggle_dark_mode=1" class="theme-toggle">
          <i class="fas fa-moon"></i>
        </a>
      </div>
    </div>

    <!-- Welcome Section -->
    <div class="welcome-container">
      <div class="welcome-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
      <div class="welcome-text">
        <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($username); ?>!</h2>
        <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
      </div>
    </div>

    <!-- Flash Messages -->
    <?php if (!empty($error)): ?>
      <div class="flash-message flash-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="flash-message flash-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
      </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-value"><?php echo $totalUsers; ?></div>
            <div class="stat-label">Total Staff</div>
          </div>
          <div class="stat-icon">
            <i class="fas fa-users"></i>
          </div>
        </div>
      </div>

      <div class="stat-card success">
        <div class="stat-header">
          <div>
            <div class="stat-value"><?php echo $totalAdmins; ?></div>
            <div class="stat-label">Admin Accounts</div>
          </div>
          <div class="stat-icon">
            <i class="fas fa-user-shield"></i>
          </div>
        </div>
      </div>

      <div class="stat-card warning">
        <div class="stat-header">
          <div>
            <div class="stat-value"><?php echo $totalPhotographers; ?></div>
            <div class="stat-label">Photographer Accounts</div>
          </div>
          <div class="stat-icon">
            <i class="fas fa-camera"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Add User Form -->
    <div class="form-container">
      <div class="section-header">
        <div class="section-title">Add New Staff Member</div>
      </div>
      <form method="POST" id="addUserForm">
        <div class="form-grid">
          <div class="form-group">
            <label for="fullname" class="required">Full Name</label>
            <input type="text" id="fullname" name="fullname" class="form-control" 
                   value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" 
                   placeholder="Enter full name" required>
          </div>
          <div class="form-group">
            <label for="username" class="required">Username</label>
            <input type="text" id="username" name="username" class="form-control" 
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                   placeholder="Enter username" required>
          </div>
          <div class="form-group">
            <label for="password" class="required">Password</label>
            <input type="password" id="password" name="password" class="form-control" 
                   placeholder="Enter password (min. 4 characters)" required minlength="4">
          </div>
          <div class="form-group">
            <label for="role" class="required">Role</label>
            <select id="role" name="role" class="form-control" required>
              <option value="">Select Role</option>
              <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
              <option value="photographer" <?php echo (($_POST['role'] ?? '') === 'photographer') ? 'selected' : ''; ?>>Photographer</option>
            </select>
          </div>
          <div class="form-group">
            <button type="submit" name="add_user" class="btn btn-primary">
              <i class="fas fa-user-plus"></i> Add Staff
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- All Staff Table -->
    <div class="table-container">
      <div class="section-header">
        <div class="section-title">All Staff Accounts</div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Username</th>
              <th>Role</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                  <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                  No staff accounts found
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td><?= $user['id'] ?></td>
                  <td><?= htmlspecialchars($user['fullname']) ?></td>
                  <td><?= htmlspecialchars($user['username']) ?></td>
                  <td>
                    <span class="role-badge <?= $user['role'] === 'admin' ? 'role-admin' : 'role-photographer' ?>">
                      <?= strtoupper($user['role']) ?>
                    </span>
                  </td>
                  <td><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></td>
                  <td class="action-cell">
                    <button type="button" class="action-btn success" 
                            onclick="openEditModal(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['fullname'])) ?>', '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= $user['role'] ?>')">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars(addslashes($user['fullname'])) ?>')">
                      <input type="hidden" name="id" value="<?= $user['id'] ?>">
                      <button type="submit" name="delete_user" class="action-btn danger">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Footer -->
    <div class="footer">
      <p>&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved.</p>
    </div>
  </div>

  <!-- Enhanced Edit User Modal -->
  <div class="modal" id="editUserModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title"><i class="fas fa-user-edit"></i> Edit Staff Member</h3>
        <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
      </div>
      
      <div class="modal-body">
        <div class="user-avatar-preview">
          <div class="avatar-large" id="editUserAvatar">U</div>
        </div>
        
        <form method="POST" id="editUserForm">
          <input type="hidden" name="id" id="editUserId">
          <input type="hidden" name="update_user" value="1">
          
          <div class="form-section">
            <div class="section-label"><i class="fas fa-id-card"></i> Personal Information</div>
            <div class="form-grid-modal">
              <div class="form-group-modal">
                <label for="editFullname" class="required"><i class="fas fa-signature"></i> Full Name</label>
                <input type="text" id="editFullname" name="fullname" class="form-control" placeholder="Enter staff member's full name" required>
              </div>
              
              <div class="form-group-modal">
                <label for="editUsername" class="required"><i class="fas fa-user"></i> Username</label>
                <input type="text" id="editUsername" name="username" class="form-control" placeholder="Enter username for login" required>
              </div>
            </div>
          </div>
          
          <div class="form-section">
            <div class="section-label"><i class="fas fa-lock"></i> Security</div>
            <div class="form-group-modal">
              <label for="editPassword"><i class="fas fa-key"></i> Password</label>
              <div class="input-with-icon">
                <input type="password" id="editPassword" name="password" class="form-control" placeholder="Enter new password (leave blank to keep current)" minlength="4">
                <button type="button" class="input-icon" id="togglePassword">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div class="password-strength" id="passwordStrength">
                <div class="password-strength-bar"></div>
              </div>
              <div class="password-hint">
                <i class="fas fa-info-circle"></i>
                <span>Password must be at least 4 characters long</span>
              </div>
            </div>
          </div>
          
          <div class="form-section">
            <div class="section-label"><i class="fas fa-user-tag"></i> Role & Permissions</div>
            <div class="form-group-modal">
              <label class="required"><i class="fas fa-shield-alt"></i> Select Role</label>
              <div class="role-selector">
                <div class="role-option" data-value="admin">
                  <div class="role-icon"><i class="fas fa-user-shield"></i></div>
                  <div class="role-name">Admin</div>
                  <div class="role-description">Full system access</div>
                </div>
                <div class="role-option" data-value="photographer">
                  <div class="role-icon"><i class="fas fa-camera"></i></div>
                  <div class="role-name">Photographer</div>
                  <div class="role-description">Appointments only</div>
                </div>
              </div>
              <input type="hidden" id="editRole" name="role" required>
            </div>
          </div>
        </form>
      </div>
      
      <div class="modal-footer">
        <button type="button" class="btn" onclick="closeEditModal()" style="background: var(--gray);">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" form="editUserForm" class="btn btn-success">
          <i class="fas fa-save"></i> Update Staff
        </button>
      </div>
    </div>
  </div>

  <!-- Toast Notification -->
  <?php if (!empty($success)): ?>
    <div class="toast toast-success" id="successToast">
      <i class="fas fa-check-circle"></i>
      <span><?php echo htmlspecialchars($success); ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <div class="toast toast-error" id="errorToast">
      <i class="fas fa-exclamation-circle"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
  <?php endif; ?>

  <script>
    // Mobile sidebar toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
      });
      
      sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
      });
    }

    // Enhanced Edit Modal Functions
    function openEditModal(id, fullname, username, role) {
      document.getElementById('editUserId').value = id;
      document.getElementById('editFullname').value = fullname;
      document.getElementById('editUsername').value = username;
      
      // Set the role using the role selector
      document.querySelectorAll('.role-option').forEach(option => {
        option.classList.remove('selected');
        if (option.getAttribute('data-value') === role) {
          option.classList.add('selected');
        }
      });
      document.getElementById('editRole').value = role;
      
      // Update avatar with first letter of fullname
      const firstLetter = fullname.charAt(0).toUpperCase();
      document.getElementById('editUserAvatar').textContent = firstLetter;
      
      // Reset password field
      document.getElementById('editPassword').value = '';
      updatePasswordStrength('');
      
      // Show modal with animation
      document.getElementById('editUserModal').classList.add('active');
      // Prevent body scroll when modal is open
      document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
      document.getElementById('editUserModal').classList.remove('active');
      // Restore body scroll
      document.body.style.overflow = '';
    }

    // Close modal when clicking outside
    document.getElementById('editUserModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeEditModal();
      }
    });

    // Role selection
    document.querySelectorAll('.role-option').forEach(option => {
      option.addEventListener('click', function() {
        document.querySelectorAll('.role-option').forEach(opt => {
          opt.classList.remove('selected');
        });
        this.classList.add('selected');
        document.getElementById('editRole').value = this.getAttribute('data-value');
      });
    });

    // Password visibility toggle
    document.getElementById('togglePassword').addEventListener('click', function() {
      const passwordInput = document.getElementById('editPassword');
      const icon = this.querySelector('i');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    });

    // Password strength indicator
    function updatePasswordStrength(password) {
      const strengthBar = document.getElementById('passwordStrength');
      const strengthClasses = ['weak', 'medium', 'strong'];
      
      // Remove all strength classes
      strengthClasses.forEach(cls => strengthBar.classList.remove(cls));
      
      if (password.length === 0) {
        return;
      }
      
      if (password.length < 4) {
        strengthBar.classList.add('weak');
      } else if (password.length < 8) {
        strengthBar.classList.add('medium');
      } else {
        strengthBar.classList.add('strong');
      }
    }

    // Listen for password input changes
    document.getElementById('editPassword').addEventListener('input', function() {
      updatePasswordStrength(this.value);
    });

    // Enhanced form validation for modal
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
      const fullname = document.getElementById('editFullname').value.trim();
      const username = document.getElementById('editUsername').value.trim();
      const role = document.getElementById('editRole').value;
      const password = document.getElementById('editPassword').value;
      
      // Validate required fields
      if (!fullname) {
        e.preventDefault();
        highlightInvalidField(document.getElementById('editFullname'), 'Full name is required');
        return;
      }
      
      if (!username) {
        e.preventDefault();
        highlightInvalidField(document.getElementById('editUsername'), 'Username is required');
        return;
      }
      
      if (!role) {
        e.preventDefault();
        alert('Please select a role for the user');
        return;
      }
      
      if (password && password.length < 4) {
        e.preventDefault();
        highlightInvalidField(document.getElementById('editPassword'), 'Password must be at least 4 characters');
        return;
      }
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        submitBtn.disabled = true;
      }
    });

    function highlightInvalidField(field, message) {
      field.style.borderColor = 'var(--danger)';
      field.focus();
      
      // Create and show a temporary error message
      const errorDiv = document.createElement('div');
      errorDiv.className = 'flash-message flash-error';
      errorDiv.style.marginTop = '10px';
      errorDiv.style.marginBottom = '0';
      errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
      
      // Insert after the field
      field.parentNode.appendChild(errorDiv);
      
      // Remove after 3 seconds
      setTimeout(() => {
        errorDiv.remove();
        field.style.borderColor = '';
      }, 3000);
    }

    // Update current date and time
    function updateDateTime() {
      const now = new Date();
      const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      document.getElementById('current-date').textContent = dateString;
    }

    // Initialize date and update every minute
    updateDateTime();
    setInterval(updateDateTime, 60000);

    // Auto-hide flash messages after 5 seconds
    setTimeout(function() {
      const flashMessages = document.querySelectorAll('.flash-message');
      flashMessages.forEach(msg => {
        msg.style.opacity = '0';
        msg.style.transition = 'opacity 0.5s ease';
        setTimeout(() => msg.remove(), 500);
      });
    }, 5000);

    // Auto-hide toast notifications
    setTimeout(function() {
      const toasts = document.querySelectorAll('.toast');
      toasts.forEach(toast => {
        toast.style.animation = 'slideOutRight 0.5s ease-out forwards';
        setTimeout(() => toast.remove(), 500);
      });
    }, 5000);

    // Enhanced delete confirmation
    function confirmDelete(userName) {
      return confirm(`Are you sure you want to delete "${userName}"? This action cannot be undone.`);
    }

    // Clear form after successful submission
    <?php if ($success): ?>
      document.addEventListener('DOMContentLoaded', function() {
        const addForm = document.getElementById('addUserForm');
        if (addForm) {
          addForm.reset();
        }
      });
    <?php endif; ?>

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeEditModal();
      }
    });
  </script>
</body>
</html>