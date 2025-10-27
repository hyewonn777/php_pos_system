<?php
// Start session first with proper name - only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('admin_session');
    session_start();
}

require 'auth.php';
require 'db.php';

// Debug: Check what's in session
error_log("Session contents: " . print_r($_SESSION, true));
error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("Role: " . ($_SESSION['role'] ?? 'NOT SET'));

// Check if user is logged in and has appropriate role (admin or photographer)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'photographer')) {
    error_log("Access denied - Redirecting to login. User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . ", Role: " . ($_SESSION['role'] ?? 'NOT SET'));
    header("Location: login.php");
    exit();
}

// Define current role for navigation
$current_role = $_SESSION['role'];

// Create necessary tables if they don't exist
$setup_queries = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'photographer', 'staff') DEFAULT 'staff',
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer VARCHAR(255) NOT NULL,
        service VARCHAR(255) DEFAULT 'Photography',
        date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        location TEXT,
        status ENUM('Pending', 'Approved', 'Cancelled', 'Vacant') DEFAULT 'Pending',
        cameraman_id INT NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cameraman_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    
    "CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        appointment_id INT,
        message TEXT NOT NULL,
        type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
    )",
    
    "ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cameraman_id INT NULL AFTER status",
    "ALTER TABLE appointments ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER location",
    "ALTER TABLE appointments ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER note",
    "ALTER TABLE appointments ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER customer",
    
    "INSERT IGNORE INTO users (username, password, role, status) VALUES 
    ('photographer1', '" . password_hash('password123', PASSWORD_DEFAULT) . "', 'photographer', 'active'),
    ('photographer2', '" . password_hash('password123', PASSWORD_DEFAULT) . "', 'photographer', 'active'),
    ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin', 'active')",
    
    // Add sample appointments if none exist
    "INSERT IGNORE INTO appointments (customer, service, date, start_time, end_time, location, status, cameraman_id, note) 
     SELECT 'John Doe', 'Photography', CURDATE(), '09:00:00', '10:00:00', 'Studio A', 'Approved', 
            (SELECT id FROM users WHERE role = 'photographer' LIMIT 1),
            'Sample appointment note'
     FROM DUAL 
     WHERE NOT EXISTS (SELECT 1 FROM appointments)"
];

foreach ($setup_queries as $query) {
    if (!$conn->query($query)) {
        error_log("Setup query failed: " . $conn->error);
    }
}

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
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';

// Get all customers from users table for dropdown
$customers_result = $conn->query("
    SELECT id, username, COALESCE(fullname, username) as display_name 
    FROM users 
    WHERE role IN ('client', 'customer') OR role IS NULL
    ORDER BY display_name
");

$customers = [];
if ($customers_result && $customers_result->num_rows > 0) {
    while($customer = $customers_result->fetch_assoc()) {
        $customers[] = $customer;
    }
}

/* ------------------------
   Auto-Assign Cameraman Function
------------------------ */
function autoAssignCameraman($conn, $date, $start_time, $end_time) {
    // Get all active photographers
    $photographers_result = $conn->query("
        SELECT id, username 
        FROM users 
        WHERE role = 'photographer' 
        AND status = 'active'
    ");
    
    // If no photographers exist, return null
    if (!$photographers_result || $photographers_result->num_rows === 0) {
        return null;
    }
    
    $available_photographers = [];
    
    while ($photographer = $photographers_result->fetch_assoc()) {
        // Check if photographer is available during the requested time
        $check_stmt = $conn->prepare("
            SELECT COUNT(*) as conflict_count 
            FROM appointments 
            WHERE cameraman_id = ? 
            AND date = ? 
            AND status != 'Cancelled'
            AND (
                (start_time <= ? AND end_time >= ?) OR
                (start_time <= ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        
        if ($check_stmt) {
            $check_stmt->bind_param("isssssss", 
                $photographer['id'], $date, $start_time, $start_time, 
                $end_time, $end_time, $start_time, $end_time
            );
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $conflict = $result->fetch_assoc();
            $check_stmt->close();
            
            if ($conflict['conflict_count'] == 0) {
                $available_photographers[] = $photographer['id'];
            }
        }
    }
    
    // If available photographers found, return the first one
    if (!empty($available_photographers)) {
        return $available_photographers[0];
    }
    
    // If no available photographers, find the one with least appointments on that date
    $least_busy = $conn->query("
        SELECT u.id, COUNT(a.id) as appointment_count
        FROM users u
        LEFT JOIN appointments a ON u.id = a.cameraman_id AND a.date = '$date' AND a.status != 'Cancelled'
        WHERE u.role = 'photographer' AND u.status = 'active'
        GROUP BY u.id
        ORDER BY appointment_count ASC
        LIMIT 1
    ");
    
    if ($least_busy && $least_busy->num_rows > 0) {
        return $least_busy->fetch_assoc()['id'];
    }
    
    return null; // No photographers available
}

/* ------------------------
   Get Cameraman Name
------------------------ */
function getCameramanName($conn, $cameraman_id) {
    if (!$cameraman_id) return null;
    
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $cameraman_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $cameraman = $result->fetch_assoc();
        $stmt->close();
        
        return $cameraman ? $cameraman['username'] : null;
    }
    return null;
}

/* ------------------------
   Handle Form Submissions
------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Handle bookings from index.php ---
    if (isset($_POST['book_photographer'])) {
        // Debug: Log all POST data
        error_log("Booking POST data: " . print_r($_POST, true));
        error_log("Booking SESSION user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));

        // Map the fields from index.php to appointment.php structure
        $customer = trim($_POST['customer_name']);
        $date = $_POST['event_date'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $location = trim($_POST['location']);
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $cameraman_id = isset($_POST['photographer_id']) && !empty($_POST['photographer_id']) ? intval($_POST['photographer_id']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : 'Photography';
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        
        // Default status for client bookings
        $status = 'Pending';

        // Validate required fields
        if (empty($customer) || empty($date) || empty($start) || empty($end) || empty($location)) {
            $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> Please fill all required fields.</div>";
            header("Location: index.php#photographer-booking");
            exit;
        }

        // If no cameraman selected or photographer_id is 0, auto-assign the most available one
        if (empty($cameraman_id) || $cameraman_id == 0) {
            $cameraman_id = autoAssignCameraman($conn, $date, $start, $end);
        }

        // Build the note content
        $note = "";
        if (!empty($event_type) && $event_type != 'Photography') {
            $note .= "Event Type: " . $event_type . "\n";
        }
        if (!empty($notes)) {
            $note .= $notes;
        }

        // Prepare the SQL statement
        $stmt = $conn->prepare("INSERT INTO appointments 
            (customer, user_id, service, date, start_time, end_time, location, note, status, cameraman_id, created_at) 
            VALUES (?, ?, 'Photography', ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if ($stmt) {
            $stmt->bind_param("sissssssi", $customer, $user_id, $date, $start, $end, $location, $note, $status, $cameraman_id);
            
            if ($stmt->execute()) {
                $booking_id = $conn->insert_id;
                $cameraman_name = getCameramanName($conn, $cameraman_id);
                
                // Create notification for the user if user_id exists
                if ($user_id) {
                    $notification_msg = "Your booking request #{$booking_id} has been received and is pending approval.";
                    $notification_stmt = $conn->prepare("
                        INSERT INTO user_notifications (user_id, appointment_id, message, type, is_read) 
                        VALUES (?, ?, ?, 'info', 0)
                    ");
                    if ($notification_stmt) {
                        $notification_stmt->bind_param("iis", $user_id, $booking_id, $notification_msg);
                        $notification_stmt->execute();
                        $notification_stmt->close();
                    }
                }
                
                $_SESSION['booking_message'] = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Booking request submitted! Your booking ID is #{$booking_id}. We'll confirm your appointment shortly." . ($cameraman_name ? " Assigned to: $cameraman_name" : "") . "</div>";
            } else {
                error_log("Booking execution error: " . $stmt->error);
                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Booking failed. Please try again. Error: " . $stmt->error . "</div>";
            }
            $stmt->close();
        } else {
            error_log("Booking preparation error: " . $conn->error);
            $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> System error. Please try again.</div>";
        }
        
        header("Location: index.php#photographer-booking");
        exit;
    }

    // --- Handle Admin Approval/Rejection ---
    if (isset($_POST['admin_action'])) {
        $appointment_id = intval($_POST['appointment_id']);
        $action = $_POST['action']; // 'approve' or 'reject'
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Get appointment details for notification
        $appointment_query = $conn->prepare("
            SELECT a.*, u.id as user_id, u.email as user_email, u.fullname as user_name 
            FROM appointments a 
            LEFT JOIN users u ON a.user_id = u.id 
            WHERE a.id = ?
        ");
        $appointment_query->bind_param("i", $appointment_id);
        $appointment_query->execute();
        $appointment_result = $appointment_query->get_result();
        
        if ($appointment_result->num_rows > 0) {
            $appointment = $appointment_result->fetch_assoc();
            
            if ($action === 'approve') {
                $new_status = 'Approved';
                $message = "Your booking #{$appointment_id} has been approved!";
                $notification_type = 'success';
            } else {
                $new_status = 'Cancelled';
                $message = "Your booking #{$appointment_id} has been cancelled. " . ($admin_notes ? "Reason: {$admin_notes}" : "");
                $notification_type = 'warning';
            }
            
            // Update appointment status
            $update_stmt = $conn->prepare("UPDATE appointments SET status = ?, admin_notes = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $new_status, $admin_notes, $appointment_id);
            
            if ($update_stmt->execute()) {
                // Store notification for user
                if ($appointment['user_id']) {
                    $notification_stmt = $conn->prepare("
                        INSERT INTO user_notifications (user_id, appointment_id, message, type, is_read) 
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    $notification_stmt->bind_param("iiss", $appointment['user_id'], $appointment_id, $message, $notification_type);
                    $notification_stmt->execute();
                    $notification_stmt->close();
                }
                
                $_SESSION['flash'] = ['message' => "Appointment {$action}d successfully!", 'type' => 'success'];
            } else {
                $_SESSION['flash'] = ['message' => "Failed to update appointment.", 'type' => 'error'];
            }
            $update_stmt->close();
        } else {
            $_SESSION['flash'] = ['message' => "Appointment not found.", 'type' => 'error'];
        }
        
        $appointment_query->close();
        header("Location: appointment.php");
        exit;
    }

    // --- Delete multiple appointments ---
    if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
        $deleted_count = 0;
        $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        
        foreach ($_POST['delete_ids'] as $delete_id) {
            $delete_id = intval($delete_id);
            if ($delete_id > 0) {
                if ($stmt) {
                    $stmt->bind_param("i", $delete_id);
                    $stmt->execute();
                    $deleted_count++;
                }
            }
        }
        
        if ($stmt) {
            $stmt->close();
        }
        
        $_SESSION['flash'] = ['message' => "Successfully deleted $deleted_count appointment(s)!", 'type' => 'success'];
        header("Location: appointment.php");
        exit;
    }

    // --- Delete appointment ---
    if (!empty($_POST['delete_id'])) {
        $delete_id = intval($_POST['delete_id']);
        $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment deleted successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Update appointment status ---
    if (!empty($_POST['update_status'])) {
        $id       = intval($_POST['update_id']);
        $status   = $_POST['status'];

        $stmt = $conn->prepare("UPDATE appointments SET status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment status updated successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Edit appointment ---
    if (!empty($_POST['edit_id'])) {
        $id       = intval($_POST['edit_id']);
        $customer = trim($_POST['customer']);
        $date     = $_POST['date'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $location = trim($_POST['location']);
        $note     = trim($_POST['note']);
        $status   = $_POST['status'];
        $cameraman_id = !empty($_POST['cameraman_id']) ? intval($_POST['cameraman_id']) : null;

        $stmt = $conn->prepare("UPDATE appointments 
            SET customer=?, date=?, start_time=?, end_time=?, location=?, note=?, status=?, cameraman_id=?
            WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("sssssssii", $customer, $date, $start, $end, $location, $note, $status, $cameraman_id, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['message' => "Appointment updated successfully!", 'type' => 'success'];
        }
        header("Location: appointment.php");
        exit;
    }

    // --- Add appointment ---
    if (!empty($_POST['customer']) && !empty($_POST['date']) && !empty($_POST['start_time']) && !empty($_POST['end_time']) && !empty($_POST['status'])) {
        $customer = trim($_POST['customer']);
        $date     = $_POST['date'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $location = trim($_POST['location']);
        $note     = trim($_POST['note']);
        $status   = $_POST['status'];
        $cameraman_id = !empty($_POST['cameraman_id']) ? intval($_POST['cameraman_id']) : null;

        // If no cameraman selected, auto-assign the most available one
        if (empty($cameraman_id)) {
            $cameraman_id = autoAssignCameraman($conn, $date, $start, $end);
        }

        $stmt = $conn->prepare("INSERT INTO appointments 
            (customer, service, date, start_time, end_time, location, note, status, cameraman_id, created_at) 
            VALUES (?, 'Photography', ?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sssssssi", $customer, $date, $start, $end, $location, $note, $status, $cameraman_id);
            $stmt->execute();
            $stmt->close();
            
            $cameraman_name = getCameramanName($conn, $cameraman_id);
            $_SESSION['flash'] = ['message' => "Appointment added successfully!" . ($cameraman_name ? " Assigned to: $cameraman_name" : ""), 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['message' => "Error adding appointment: " . $conn->error, 'type' => 'error'];
        }
        header("Location: appointment.php");
        exit;
    }

    $_SESSION['flash'] = ['message' => "Invalid request. Please fill all required fields.", 'type' => 'error'];
    header("Location: appointment.php");
    exit;
}

/* ------------------------
   Auto-Cancel Expired
------------------------ */
// Cancel only Pending events if expired
$conn->query("UPDATE appointments 
    SET status='Cancelled' 
    WHERE CONCAT(date,' ',end_time) < NOW() 
    AND status='Pending'");

/* ------------------------
   Fetch Events for Calendar
------------------------ */
$events = [];
$res = $conn->query("
    SELECT a.*, u.username as cameraman_name 
    FROM appointments a 
    LEFT JOIN users u ON a.cameraman_id = u.id
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $color = "#4361ee"; // Marcomedia primary
        if ($row['status'] === "Vacant")   $color = "#4cc9f0"; // Marcomedia light blue
        if ($row['status'] === "Approved") $color = "#10b981"; // Marcomedia success
        if ($row['status'] === "Cancelled") $color = "#e63946"; // Marcomedia danger
        if ($row['status'] === "Pending")  $color = "#f59e0b"; // Marcomedia warning

        $title = $row['customer'] . " (" . $row['service'] . ")";
        if ($row['cameraman_name']) {
            $title .= " - " . $row['cameraman_name'];
        }

        $events[] = [
            "id"      => $row['id'],
            "title"   => $title,
            "start"   => $row['date'] . "T" . $row['start_time'],
            "end"     => $row['date'] . "T" . $row['end_time'],
            "color"   => $color,
            "location"=> $row['location'],
            "note"    => $row['note'],
            "status"  => $row['status'],
            "cameraman" => $row['cameraman_name']
        ];
    }
}
$events_json = json_encode($events);  

// Get appointment statistics
$totalAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments")->fetch_assoc()['cnt'];
$pendingAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE status='Pending'")->fetch_assoc()['cnt'];
$approvedAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE status='Approved'")->fetch_assoc()['cnt'];
$todayAppointments = $conn->query("SELECT COUNT(*) as cnt FROM appointments WHERE date = CURDATE()")->fetch_assoc()['cnt'];

// Get available photographers
$photographers_result = $conn->query("
    SELECT id, username 
    FROM users 
    WHERE role = 'photographer' 
    AND status = 'active'
");

$photographers = [];
if ($photographers_result && $photographers_result->num_rows > 0) {
    while($photographer = $photographers_result->fetch_assoc()) {
        $photographers[] = $photographer;
    }
}

// Get appointments for table
$result = $conn->query("
    SELECT a.*, u.username as cameraman_name 
    FROM appointments a 
    LEFT JOIN users u ON a.cameraman_id = u.id 
    ORDER BY a.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Appointments & Booking - Marcomedia POS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
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

    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      font-size: 11px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      box-shadow: 0 2px 8px rgba(230, 57, 70, 0.4);
    }

    .notification-wrapper {
      position: relative;
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

    /* Form Container */
    .form-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 40px;
      display: none;
      transition: var(--transition);
    }

    .form-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group.full-width {
      grid-column: 1 / -1;
    }

    .form-group label {
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text);
      font-size: 14px;
    }

    .form-control {
      padding: 12px 16px;
      border: 2px solid var(--border);
      border-radius: 10px;
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
    }

    textarea.form-control {
      min-height: 80px;
      resize: vertical;
    }

    /* Buttons */
    .btn {
      padding: 12px 20px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: var(--shadow);
      font-size: 14px;
    }

    .btn:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }

    .btn-primary {
      background: linear-gradient(to right, var(--primary), var(--primary-dark));
      color: white;
    }

    .btn-primary:hover {
      background: linear-gradient(to right, var(--primary-dark), var(--primary));
    }

    .btn-success {
      background: var(--success);
      color: white;
    }

    .btn-success:hover {
      background: #34a853;
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #e55a5a;
    }

    .btn-warning {
      background: var(--warning);
      color: white;
    }

    .btn-warning:hover {
      background: #e6ac00;
    }

    /* Calendar Container */
    .calendar-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 40px;
      transition: var(--transition);
    }

    .calendar-container:hover {
      box-shadow: var(--shadow-hover);
    }

    /* Table Container */
    .table-container {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      box-shadow: var(--shadow);
      margin-bottom: 40px;
      transition: var(--transition);
    }

    .table-container:hover {
      box-shadow: var(--shadow-hover);
    }

    .table-header {
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

    .search-box {
      position: relative;
      width: 300px;
    }

    .search-box input {
      width: 100%;
      padding: 12px 16px 12px 42px;
      border-radius: 10px;
      border: 2px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 14px;
      transition: var(--transition);
    }

    .search-box input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
    }

    .search-box i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
    }

    .table {
      width: 100%;
      border-collapse: collapse;
    }

    .table th, .table td {
      padding: 16px;
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

    .table tr {
      transition: var(--transition);
    }

    .table tr:hover {
      background: rgba(67, 97, 238, 0.03);
    }

    /* Enhanced Action Buttons */
    .action-cell {
      display: flex;
      gap: 6px;
      align-items: center;
      justify-content: flex-start;
      flex-wrap: wrap;
    }

    .action-btn {
      padding: 8px 12px;
      border-radius: 8px;
      border: none;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      position: relative;
      overflow: hidden;
      min-width: 36px;
      height: 36px;
    }

    .action-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .action-btn:hover::before {
      left: 100%;
    }

    .action-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .action-btn.primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
    }

    .action-btn.success {
      background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
      color: white;
    }

    .action-btn.danger {
      background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
      color: white;
    }

    .action-btn.warning {
      background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
      color: white;
    }

    .btn-text {
      font-size: 11px;
      font-weight: 600;
    }

    /* Mobile responsive for action buttons */
    @media (max-width: 768px) {
      .action-cell {
        gap: 4px;
      }
      
      .action-btn {
        padding: 6px 8px;
        height: 32px;
        min-width: 32px;
      }
      
      .btn-text {
        display: none;
      }
      
      .action-btn i {
        margin-right: 0;
      }
    }

    /* Enhanced Form Labels */
    .form-label {
      display: flex;
      align-items: center;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text);
      font-size: 14px;
    }

    /* Duration Display */
    .duration-display {
      font-weight: 600;
      color: var(--primary);
      background: rgba(67, 97, 238, 0.1) !important;
      border: 2px solid rgba(67, 97, 238, 0.2) !important;
    }

    /* Outline Button */
    .btn-outline {
      background: transparent;
      border: 2px solid var(--border);
      color: var(--text);
      transition: all 0.3s ease;
    }

    .btn-outline:hover {
      background: var(--border);
      transform: translateY(-2px);
    }

    /* Status Badges */
    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.3px;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-approved {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-cancelled {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
      border: 1px solid rgba(230, 57, 70, 0.2);
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }

    /* Enhanced Modal Styling */
    .modal-content {
      background: var(--card-bg);
      border-radius: var(--card-radius);
      padding: 28px;
      width: 500px;
      max-width: 90%;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-body {
      margin-bottom: 20px;
    }

    .modal-body p {
      margin: 12px 0;
      color: var(--text);
      font-size: 14px;
    }

    .modal-footer {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 20px;
    }

    /* Form Focus Effects */
    .form-control:focus {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.15) !important;
    }

    /* ----------------------------------- */
    /* TOAST NOTIFICATION SYSTEM - FIXED FOR LIGHT MODE */
    /* ----------------------------------- */
    .toast-container {
        position: fixed;
        top: 100px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
    }

    .toast {
        background: var(--card-bg);
        border-radius: var(--card-radius);
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: var(--shadow);
        border-left: 6px solid;
        display: flex;
        align-items: flex-start;
        gap: 15px;
        transform: translateX(400px);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        max-width: 380px;
        position: relative;
        overflow: hidden;
    }

    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }

    .toast.hiding {
        transform: translateX(400px);
        opacity: 0;
    }

    .toast::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: currentColor;
        opacity: 0.3;
    }

    .toast-success {
        border-left-color: var(--success);
        color: var(--success);
    }

    .toast-error {
        border-left-color: var(--danger);
        color: var(--danger);
    }

    .toast-warning {
        border-left-color: var(--warning);
        color: var(--warning);
    }

    .toast-info {
        border-left-color: var(--primary);
        color: var(--primary);
    }

    .toast-icon {
        font-size: 1.5rem;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .toast-content {
        flex: 1;
        text-align: left;
    }

    .toast-title {
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 5px;
        color: var(--text);
    }

    .toast-message {
        color: var(--text);
        font-size: 0.95rem;
        line-height: 1.4;
    }

    .toast-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: var(--text-muted);
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: var(--transition);
        flex-shrink: 0;
    }

    .toast-close:hover {
        background: rgba(0, 0, 0, 0.1);
        color: var(--text);
    }

    .toast-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        background: currentColor;
        opacity: 0.5;
        width: 100%;
        transform: scaleX(1);
        transform-origin: left;
        transition: transform 5s linear;
    }

    .toast-progress.hiding {
        transform: scaleX(0);
    }

    /* Flash Message */
    .flash-message {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 30px;
      font-weight: 600;
      transition: var(--transition);
      animation: slideIn 0.5s ease-out;
      box-shadow: var(--shadow);
      border-left: 4px solid;
    }

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

    .flash-success {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
      border-left-color: var(--success);
    }

    .flash-error {
      background: rgba(230, 57, 70, 0.1);
      color: var(--danger);
      border-left-color: var(--danger);
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

    /* Quick Actions - IMPROVED STYLING */
    .quick-actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-bottom: 30px;
    }

    .quick-action-btn {
      background: var(--card-bg);
      border: none;
      border-radius: 12px;
      padding: 18px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow);
      text-decoration: none;
      color: var(--text);
      position: relative;
      overflow: hidden;
    }

    .quick-action-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
      transition: left 0.5s;
    }

    .quick-action-btn:hover::before {
      left: 100%;
    }

    .quick-action-btn:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .quick-action-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      color: white;
      box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .quick-action-btn:nth-child(2) .quick-action-icon {
      background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
    }

    .quick-action-btn:nth-child(3) .quick-action-icon {
      background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
    }

    .quick-action-btn:nth-child(4) .quick-action-icon {
      background: linear-gradient(135deg, var(--info) 0%, #3a7bd5 100%);
    }

    .quick-action-text {
      font-weight: 600;
      font-size: 14px;
      text-align: center;
    }

    /* Status Dropdown */
    .status-dropdown {
      padding: 6px 10px;
      border-radius: 6px;
      border: 2px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
    }

    .status-dropdown:focus {
      outline: none;
      border-color: var(--primary);
    }

    /* Note Styles */
    .note-container {
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .note-full {
      white-space: normal;
      word-wrap: break-word;
    }

    /* Bulk Actions */
    .bulk-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        align-items: center;
        padding: 15px;
        background: var(--card-bg);
        border-radius: var(--card-radius);
        box-shadow: var(--shadow);
        display: none;
    }
    
    .bulk-actions.active {
        display: flex;
    }
    
    .selected-count {
        font-weight: 600;
        color: var(--primary);
        margin-right: 10px;
    }
    
    .select-all-container {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-right: auto;
    }
    
    .table th:first-child,
    .table td:first-child {
        width: 40px;
        text-align: center;
    }
    
    .form-buttons {
        display: flex;
        gap: 12px;
        margin-top: 20px;
        grid-column: 1 / -1;
    }
    
    .form-buttons .btn {
        flex: 1;
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

      .quick-actions {
        grid-template-columns: repeat(2, 1fr);
      }

      /* Toast adjustments for mobile */
      .toast-container {
          right: 10px;
          left: 10px;
          max-width: none;
      }

      .toast {
          max-width: none;
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
      
      .table-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      
      .search-box {
        width: 100%;
      }
      
      .action-cell {
        flex-direction: column;
        align-items: flex-start;
      }

      /* Toast adjustments for small screens */
      .toast-container {
          top: 80px;
      }

      .toast {
          padding: 15px;
      }

      .toast-title {
          font-size: 1rem;
      }

      .toast-message {
          font-size: 0.9rem;
      }
    }

    @media (max-width: 480px) {
      .form-container, .calendar-container, .table-container {
        padding: 18px;
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
  </style>
</head>
<body class="light-mode">
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
            <!-- Photographer sees only appointments and logout -->
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
          <h1>Appointments & Booking</h1>
          <p>Manage client bookings and appointments</p>
        </div>
        <div class="header-actions">
          <div class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
          </div>

          <label class="theme-toggle" for="theme-toggle">
            <i class="fas fa-moon"></i>
          </label>
          <input type="checkbox" id="theme-toggle" style="display: none;">
          <div>
          </div>
        </div>
      </div>

      <!-- Welcome Section -->
      <div class="welcome-container">
        <div class="welcome-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div class="welcome-text">
          <h2><?php echo $greeting; ?>, <?php echo $username; ?>!</h2>
          <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
        </div>
      </div>

      <!-- Flash Message will be shown as toast -->
      <?php if(!empty($_SESSION['flash'])): ?>
      <script>
          document.addEventListener('DOMContentLoaded', function() {
              setTimeout(() => {
                  <?php if($_SESSION['flash']['type'] === 'success'): ?>
                      Toast.success(`<?php echo addslashes($_SESSION['flash']['message']); ?>`, 'Success!');
                  <?php else: ?>
                      Toast.error(`<?php echo addslashes($_SESSION['flash']['message']); ?>`, 'Error!');
                  <?php endif; ?>
              }, 1000);
          });
      </script>
      <?php 
          unset($_SESSION['flash']); 
      endif; ?>

      <!-- Quick Actions - IMPROVED -->
      <div class="quick-actions">
        <button class="quick-action-btn" onclick="toggleForm()">
          <div class="quick-action-icon">
            <i class="fas fa-plus-circle"></i>
          </div>
          <div class="quick-action-text">Add New Appointment</div>
        </button>
        <button class="quick-action-btn" onclick="openQuickAddModal()">
          <div class="quick-action-icon">
            <i class="fas fa-bolt"></i>
          </div>
          <div class="quick-action-text">Quick Add (Today)</div>
        </button>
        <button class="quick-action-btn" onclick="window.location.reload()">
          <div class="quick-action-icon">
            <i class="fas fa-sync-alt"></i>
          </div>
          <div class="quick-action-text">Refresh Calendar</div>
        </button>
        <button class="quick-action-btn" onclick="toggleMultiSelect()">
          <div class="quick-action-icon">
            <i class="fas fa-check-square"></i>
          </div>
          <div class="quick-action-text">Select Multiple</div>
        </button>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card" onclick="toggleForm()">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $totalAppointments; ?></div>
              <div class="stat-label">Total Appointments</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-calendar-check"></i>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $pendingAppointments; ?></div>
              <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-clock"></i>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $approvedAppointments; ?></div>
              <div class="stat-label">Approved</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-check-circle"></i>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <div>
              <div class="stat-value"><?php echo $todayAppointments; ?></div>
              <div class="stat-label">Today's Appointments</div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-calendar-day"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Bulk Actions -->
      <div class="bulk-actions" id="bulkActions">
        <div class="selected-count" id="selectedCount">0 selected</div>
        <div class="select-all-container">
          <input type="checkbox" id="selectAll">
          <label for="selectAll">Select All</label>
        </div>
        <button type="button" class="btn btn-danger" onclick="deleteSelected()">
          <i class="fas fa-trash"></i> Delete Selected
        </button>
        <button type="button" class="btn btn-warning" onclick="clearSelection()">
          <i class="fas fa-times"></i> Clear Selection
        </button>
      </div>

      <!-- Add Appointment Form -->
      <div class="form-container" id="appointmentForm">
        <div class="table-header">
          <div class="section-title">Schedule New Appointment</div>
          <button type="button" class="btn btn-warning" onclick="toggleForm()">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
        <form method="POST" id="addAppointmentForm">
          <div class="form-grid">
            <div class="form-group">
              <label for="customer">Customer Name *</label>
              <select id="customer" name="customer" class="form-control" required>
                <option value="">Select Customer</option>
                <?php if(!empty($customers)): ?>
                  <?php foreach($customers as $customer): ?>
                    <option value="<?= htmlspecialchars($customer['display_name']) ?>">
                      <?= htmlspecialchars($customer['display_name']) ?> 
                      <?= $customer['username'] ? "(@".htmlspecialchars($customer['username']).")" : "" ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <small>Or <a href="javascript:void(0)" onclick="enableCustomCustomer()">enter custom customer name</a></small>
            </div>
            <div class="form-group">
              <label for="date">Date *</label>
              <input type="date" id="date" name="date" class="form-control" required onchange="checkCameramanAvailability()">
            </div>
            <div class="form-group">
              <label for="start_time">Start Time *</label>
              <input type="time" id="start_time" name="start_time" class="form-control" required onchange="checkCameramanAvailability()">
            </div>
            <div class="form-group">
              <label for="end_time">End Time *</label>
              <input type="time" id="end_time" name="end_time" class="form-control" required onchange="checkCameramanAvailability()">
            </div>
            <div class="form-group">
              <label for="location">Location *</label>
              <input type="text" id="location" name="location" class="form-control" placeholder="Enter location" required>
            </div>
            <div class="form-group full-width">
              <label for="note">Note (Optional)</label>
              <textarea id="note" name="note" class="form-control" placeholder="Add any additional notes..."></textarea>
            </div>
            <div class="form-group">
              <label for="status">Status *</label>
              <select id="status" name="status" class="form-control" required>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Cancelled">Cancelled</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label for="cameraman_id">Assign Cameraman (Leave empty for auto-assignment)</label>
              <select id="cameraman_id" name="cameraman_id" class="form-control">
                <option value="">Auto-assign (Recommended)</option>
                <?php 
                if (!empty($photographers)) {
                    foreach($photographers as $photographer): 
                ?>
                  <option value="<?= $photographer['id'] ?>"><?= htmlspecialchars($photographer['username']) ?></option>
                <?php 
                    endforeach;
                } else {
                    echo '<option value="">No photographers available</option>';
                }
                ?>
              </select>
              <div id="cameramanAvailability"></div>
            </div>
            
            <!-- Improved button placement -->
            <div class="form-buttons">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-plus"></i> Schedule Appointment
              </button>
              <button type="button" class="btn btn-warning" onclick="toggleForm()">
                <i class="fas fa-times"></i> Cancel
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Calendar -->
      <div class="calendar-container">
        <div class="table-header">
          <div class="section-title">Appointment Calendar</div>
          <button type="button" class="btn btn-primary" onclick="toggleForm()">
            <i class="fas fa-plus"></i> Add Appointment
          </button>
        </div>
        <div id="calendar"></div>
      </div>

      <!-- Appointments Table -->
      <div class="table-container">
        <div class="table-header">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search appointments...">
          </div>
          <div class="section-title">Appointment Management</div>
          <button type="button" class="btn btn-primary" onclick="toggleForm()">
            <i class="fas fa-plus"></i> Add Appointment
          </button>
        </div>

        <form method="POST" id="multiDeleteForm">
          <table class="table">
            <thead>
              <tr>
                <th>
                  <input type="checkbox" id="selectAllTable" style="display: none;">
                </th>
                <th>ID</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Time</th>
                <th>Location</th>
                <th>Note</th>
                <th>Cameraman</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if ($result && $result->num_rows > 0):
                  while($row = $result->fetch_assoc()):
              ?>
              <tr>
                <td>
                  <input type="checkbox" name="delete_ids[]" value="<?= intval($row['id']) ?>" class="row-checkbox" style="display: none;">
                </td>
                <td><strong>#<?= intval($row['id']) ?></strong></td>
                <td><?= htmlspecialchars($row['customer']) ?></td>
                <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                <td><?= date("g:i A", strtotime($row['start_time'])) ?> - <?= date("g:i A", strtotime($row['end_time'])) ?></td>
                <td><?= htmlspecialchars($row['location']) ?></td>
                <td class="note-container" title="<?= htmlspecialchars($row['note']) ?>">
                  <?php if(!empty($row['note'])): ?>
                    <i class="fas fa-sticky-note" style="color: var(--primary);"></i>
                    <?= strlen($row['note']) > 30 ? htmlspecialchars(substr($row['note'], 0, 30)) . '...' : htmlspecialchars($row['note']) ?>
                  <?php else: ?>
                    <span style="color: var(--text-muted); font-style: italic;">No note</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if($row['cameraman_name']): ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--primary);"></div>
                      <?= htmlspecialchars($row['cameraman_name']) ?>
                    </div>
                  <?php else: ?>
                    <span style="color: var(--text-muted); font-style: italic;">Not assigned</span>
                  <?php endif; ?>
                </td>
                <td>
                  <!-- Status Form - Moved to separate form -->
                  <form method="POST" class="status-form" style="display: inline;">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="update_id" value="<?= intval($row['id']) ?>">
                    <select name="status" onchange="this.form.submit()" class="status-dropdown">
                      <option value="Pending" <?= $row['status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="Approved" <?= $row['status'] == 'Approved' ? 'selected' : '' ?>>Approved</option>
                      <option value="Cancelled" <?= $row['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                  </form>
                </td>
                <td class="action-cell">
                  <!-- Admin Approval/Rejection Buttons -->
                  <?php if ($current_role === 'admin' && $row['status'] === 'Pending'): ?>
                    <button class="action-btn success" onclick="openAdminActionModal(<?= $row['id'] ?>, 'approve')" title="Approve Appointment">
                      <i class="fas fa-check"></i>
                      <span class="btn-text">Approve</span>
                    </button>
                    <button class="action-btn danger" onclick="openAdminActionModal(<?= $row['id'] ?>, 'reject')" title="Reject Appointment">
                      <i class="fas fa-times"></i>
                      <span class="btn-text">Reject</span>
                    </button>
                  <?php endif; ?>
                  
                  <!-- Edit Button -->
                  <button class="action-btn primary" onclick="openEditModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['customer']) ?>', '<?= $row['date'] ?>', '<?= $row['start_time'] ?>', '<?= $row['end_time'] ?>', '<?= htmlspecialchars($row['location']) ?>', '<?= htmlspecialchars($row['note']) ?>', '<?= $row['status'] ?>', '<?= $row['cameraman_id'] ?>')" title="Edit Appointment">
                    <i class="fas fa-edit"></i>
                    <span class="btn-text">Edit</span>
                  </button>
                  
                  <!-- Delete Button -->
                  <form method="POST" style="display: inline;" onsubmit="return confirmDelete('Are you sure you want to delete appointment #<?= $row['id'] ?>?');">
                    <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
                    <button type="submit" class="action-btn danger" title="Delete Appointment">
                      <i class="fas fa-trash"></i>
                      <span class="btn-text">Delete</span>
                    </button>
                  </form>
                </td>
              </tr>
              <?php
                  endwhile;
              else:
              ?>
              <tr>
                <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 40px;">
                  <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                  No appointments found.
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </form>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved.</p>
      </div>
    </div>
  </div>

  <!-- Event Details Modal -->
  <div id="eventModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="modalTitle">Appointment Details</h3>
        <button class="btn btn-warning" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="modal-body">
        <p id="modalDate"></p>
        <p id="modalTime"></p>
        <p id="modalLocation"></p>
        <p id="modalNote"></p>
        <p id="modalCameraman"></p>
        <p id="modalStatus"></p>
      </div>
    </div>
  </div>

  <!-- Enhanced Edit Appointment Modal -->
  <div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="modal-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: var(--card-radius) var(--card-radius) 0 0; padding: 25px 30px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div class="modal-icon" style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-edit" style="font-size: 20px;"></i>
          </div>
          <div>
            <h3 style="margin: 0; font-size: 24px; font-weight: 700;">Edit Appointment</h3>
            <p style="margin: 4px 0 0 0; opacity: 0.9; font-size: 14px;">Update appointment details</p>
          </div>
        </div>
        <button class="btn btn-light" onclick="closeEditModal()" style="background: rgba(255,255,255,0.2); color: white; border: none;">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="editForm">
        <div class="modal-body" style="padding: 30px;">
          <input type="hidden" name="edit_id" id="edit_id">
          
          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Customer & Date -->
            <div class="form-group">
              <label for="edit_customer" class="form-label">
                <i class="fas fa-user" style="color: var(--primary); margin-right: 8px;"></i>
                Customer Name *
              </label>
              <input type="text" id="edit_customer" name="customer" class="form-control" required 
                     placeholder="Enter customer name" style="padding: 12px 16px;">
            </div>
            
            <div class="form-group">
              <label for="edit_date" class="form-label">
                <i class="fas fa-calendar" style="color: var(--primary); margin-right: 8px;"></i>
                Date *
              </label>
              <input type="date" id="edit_date" name="date" class="form-control" required
                     style="padding: 12px 16px;">
            </div>
            
            <!-- Time Slots -->
            <div class="form-group">
              <label for="edit_start_time" class="form-label">
                <i class="fas fa-clock" style="color: var(--primary); margin-right: 8px;"></i>
                Start Time *
              </label>
              <input type="time" id="edit_start_time" name="start_time" class="form-control" required
                     style="padding: 12px 16px;">
            </div>
            
            <div class="form-group">
              <label for="edit_end_time" class="form-label">
                <i class="fas fa-clock" style="color: var(--primary); margin-right: 8px;"></i>
                End Time *
              </label>
              <input type="time" id="edit_end_time" name="end_time" class="form-control" required
                     style="padding: 12px 16px;">
            </div>
            
            <!-- Location & Status -->
            <div class="form-group">
              <label for="edit_location" class="form-label">
                <i class="fas fa-map-marker-alt" style="color: var(--primary); margin-right: 8px;"></i>
                Location *
              </label>
              <input type="text" id="edit_location" name="location" class="form-control" required 
                     placeholder="Enter location" style="padding: 12px 16px;">
            </div>
            
            <div class="form-group">
              <label for="edit_status" class="form-label">
                <i class="fas fa-tag" style="color: var(--primary); margin-right: 8px;"></i>
                Status *
              </label>
              <select id="edit_status" name="status" class="form-control" required
                      style="padding: 12px 16px;">
                <option value="Pending">⏳ Pending</option>
                <option value="Approved">✅ Approved</option>
                <option value="Cancelled">❌ Cancelled</option>
              </select>
            </div>
            
            <!-- Cameraman Assignment -->
            <div class="form-group">
              <label for="edit_cameraman_id" class="form-label">
                <i class="fas fa-camera" style="color: var(--primary); margin-right: 8px;"></i>
                Assign Cameraman
              </label>
              <select id="edit_cameraman_id" name="cameraman_id" class="form-control"
                      style="padding: 12px 16px;">
                <option value="">🤖 Auto-assign</option>
                <?php 
                if (!empty($photographers)) {
                    foreach($photographers as $photographer): 
                ?>
                  <option value="<?= $photographer['id'] ?>">📸 <?= htmlspecialchars($photographer['username']) ?></option>
                <?php 
                    endforeach;
                }
                ?>
              </select>
            </div>
            
            <!-- Duration Display -->
            <div class="form-group">
              <label class="form-label">
                <i class="fas fa-hourglass-half" style="color: var(--primary); margin-right: 8px;"></i>
                Duration
              </label>
              <div id="edit_duration_display" class="duration-display" 
                   style="padding: 12px 16px; background: var(--bg); border-radius: 10px; border: 2px solid var(--border); color: var(--text-muted);">
                Calculating...
              </div>
            </div>
          </div>
          
          <!-- Note Section -->
          <div class="form-group full-width" style="margin-top: 20px;">
            <label for="edit_note" class="form-label">
              <i class="fas fa-sticky-note" style="color: var(--primary); margin-right: 8px;"></i>
              Notes & Additional Information
            </label>
            <textarea id="edit_note" name="note" class="form-control" 
                      placeholder="Add any special requirements, client preferences, or additional notes..."
                      style="padding: 16px; min-height: 100px; resize: vertical;"></textarea>
            <div class="char-counter" style="text-align: right; color: var(--text-muted); font-size: 12px; margin-top: 4px;">
              <span id="edit_note_count">0</span>/500 characters
            </div>
          </div>
          
          <!-- Preview Section -->
          <div class="preview-section" style="margin-top: 25px; padding: 20px; background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(76, 201, 240, 0.05) 100%); border-radius: 12px; border-left: 4px solid var(--primary);">
            <h4 style="margin: 0 0 12px 0; color: var(--primary); font-size: 16px;">
              <i class="fas fa-eye" style="margin-right: 8px;"></i>
              Appointment Preview
            </h4>
            <div id="edit_preview" style="color: var(--text-muted); font-size: 14px; line-height: 1.5;">
              Preview will appear here as you fill the form...
            </div>
          </div>
        </div>
        
        <div class="modal-footer" style="padding: 20px 30px; border-top: 1px solid var(--border); display: flex; gap: 12px; justify-content: flex-end;">
          <button type="button" class="btn btn-outline" onclick="closeEditModal()" 
                  style="background: transparent; border: 2px solid var(--border); color: var(--text);">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">
            <i class="fas fa-save"></i> Update Appointment
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Quick Add Modal -->
  <div id="quickAddModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Quick Add Appointment (Today)</h3>
        <button class="btn btn-warning" onclick="closeQuickAddModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <form method="POST" id="quickAddForm">
        <div class="modal-body">
          <div class="form-group">
            <label for="quick_customer">Customer Name *</label>
            <select id="quick_customer" name="customer" class="form-control" required>
              <option value="">Select Customer</option>
              <?php if(!empty($customers)): ?>
                <?php foreach($customers as $customer): ?>
                  <option value="<?= htmlspecialchars($customer['display_name']) ?>">
                    <?= htmlspecialchars($customer['display_name']) ?> 
                    <?= $customer['username'] ? "(@".htmlspecialchars($customer['username']).")" : "" ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="quick_start_time">Start Time *</label>
            <input type="time" id="quick_start_time" name="start_time" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="quick_end_time">End Time *</label>
            <input type="time" id="quick_end_time" name="end_time" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="quick_location">Location *</label>
            <input type="text" id="quick_location" name="location" class="form-control" placeholder="Enter location" required>
          </div>
          <div class="form-group">
            <label for="quick_note">Note (Optional)</label>
            <textarea id="quick_note" name="note" class="form-control" placeholder="Add any additional notes..."></textarea>
          </div>
          <input type="hidden" name="date" id="quick_date" value="<?php echo date('Y-m-d'); ?>">
          <input type="hidden" name="status" value="Approved">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-warning" onclick="closeQuickAddModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-bolt"></i> Quick Add
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Admin Action Modal -->
  <div id="adminActionModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="adminModalTitle">Appointment Action</h3>
        <button class="btn btn-warning" onclick="closeAdminActionModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <form method="POST" id="adminActionForm">
        <div class="modal-body">
          <input type="hidden" name="admin_action" value="1">
          <input type="hidden" name="appointment_id" id="adminAppointmentId">
          <input type="hidden" name="action" id="adminActionType">
          
          <div class="form-group">
            <label for="admin_notes">Notes (Optional)</label>
            <textarea id="admin_notes" name="admin_notes" class="form-control" 
                      placeholder="Add notes for the user (especially for rejections)..."></textarea>
          </div>
          
          <div id="actionPreview" style="margin-top: 15px; padding: 15px; border-radius: 8px; display: none;">
            <strong>Action Preview:</strong>
            <span id="previewText"></span>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-warning" onclick="closeAdminActionModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" id="adminActionSubmit">
            <i class="fas fa-check"></i> Confirm
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toast Notifications Container -->
  <div class="toast-container" id="toastContainer"></div>

  <script>
    // Toast Notification System - FIXED FOR LIGHT MODE
    function showToast(message, title = '', type = 'info', duration = 5000) {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };
        
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${icons[type] || icons.info}"></i>
            </div>
            <div class="toast-content">
                ${title ? `<div class="toast-title">${title}</div>` : ''}
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
            <div class="toast-progress"></div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.classList.add('show');
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.transition = `transform ${duration}ms linear`;
                progressBar.classList.add('hiding');
            }
        }, 100);
        
        // Auto remove after duration
        const autoRemove = setTimeout(() => {
            hideToast(toast);
        }, duration);
        
        // Pause on hover
        toast.addEventListener('mouseenter', () => {
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.transition = 'none';
            }
            clearTimeout(autoRemove);
        });
        
        toast.addEventListener('mouseleave', () => {
            const progressBar = toast.querySelector('.toast-progress');
            const remainingTime = duration - 100; // Approximate remaining time
            
            if (progressBar) {
                progressBar.style.transition = `transform ${remainingTime}ms linear`;
                progressBar.classList.add('hiding');
            }
            
            setTimeout(() => {
                if (toast.parentElement) {
                    hideToast(toast);
                }
            }, remainingTime);
        });
    }

    function hideToast(toast) {
        toast.classList.remove('show');
        toast.classList.add('hiding');
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 400);
    }

    // Quick toast functions
    const Toast = {
        success: (message, title = 'Success!') => showToast(message, title, 'success'),
        error: (message, title = 'Error!') => showToast(message, title, 'error'),
        warning: (message, title = 'Warning!') => showToast(message, title, 'warning'),
        info: (message, title = 'Info') => showToast(message, title, 'info')
    };

    // Multi-select functionality
    let multiSelectMode = false;
    
    function toggleMultiSelect() {
      multiSelectMode = !multiSelectMode;
      const checkboxes = document.querySelectorAll('.row-checkbox');
      const selectAllTable = document.getElementById('selectAllTable');
      const bulkActions = document.getElementById('bulkActions');
      
      checkboxes.forEach(checkbox => {
        checkbox.style.display = multiSelectMode ? 'block' : 'none';
      });
      
      selectAllTable.style.display = multiSelectMode ? 'block' : 'none';
      
      if (multiSelectMode) {
        bulkActions.classList.add('active');
        updateSelectedCount();
        Toast.info('Multi-select mode enabled', 'Selection');
      } else {
        bulkActions.classList.remove('active');
        clearSelection();
      }
    }
    
    function updateSelectedCount() {
      const selectedCount = document.querySelectorAll('.row-checkbox:checked').length;
      document.getElementById('selectedCount').textContent = selectedCount + ' selected';
    }
    
    function clearSelection() {
      const checkboxes = document.querySelectorAll('.row-checkbox');
      checkboxes.forEach(checkbox => {
        checkbox.checked = false;
      });
      document.getElementById('selectAll').checked = false;
      document.getElementById('selectAllTable').checked = false;
      updateSelectedCount();
    }
    
    function deleteSelected() {
      const selectedCount = document.querySelectorAll('.row-checkbox:checked').length;
      if (selectedCount === 0) {
        Toast.error('Please select at least one appointment to delete.', 'No Selection');
        return;
      }
      
      if (confirm(`Are you sure you want to delete ${selectedCount} appointment(s)?`)) {
        Toast.info(`Deleting ${selectedCount} appointment(s)...`, 'Processing');
        document.getElementById('multiDeleteForm').submit();
      }
    }
    
    // Enhanced Edit Modal Functions - FIXED CLOSING ISSUE
    function openEditModal(id, customer, date, startTime, endTime, location, note, status, cameramanId) {
      // Set form values
      document.getElementById('edit_id').value = id;
      document.getElementById('edit_customer').value = customer;
      document.getElementById('edit_date').value = date;
      document.getElementById('edit_start_time').value = startTime;
      document.getElementById('edit_end_time').value = endTime;
      document.getElementById('edit_location').value = location;
      document.getElementById('edit_note').value = note || '';
      document.getElementById('edit_status').value = status;
      document.getElementById('edit_cameraman_id').value = cameramanId || '';
      
      // Update note character count
      updateNoteCounter('edit_note', 'edit_note_count');
      
      // Calculate and display duration
      updateDurationDisplay();
      
      // Update preview
      updateEditPreview();
      
      // Show modal
      document.getElementById('editModal').style.display = 'flex';
      
      Toast.info(`Editing appointment #${id}`, 'Edit Mode');
    }

    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
    }

    // Update duration display
    function updateDurationDisplay() {
      const startTime = document.getElementById('edit_start_time').value;
      const endTime = document.getElementById('edit_end_time').value;
      
      if (startTime && endTime) {
        const start = new Date(`2000-01-01T${startTime}`);
        const end = new Date(`2000-01-01T${endTime}`);
        const diffMs = end - start;
        const diffHrs = Math.floor(diffMs / (1000 * 60 * 60));
        const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
        
        let durationText = '';
        if (diffHrs > 0) {
          durationText += `${diffHrs} hour${diffHrs !== 1 ? 's' : ''} `;
        }
        if (diffMins > 0) {
          durationText += `${diffMins} minute${diffMins !== 1 ? 's' : ''}`;
        }
        
        if (durationText) {
          document.getElementById('edit_duration_display').textContent = durationText;
          document.getElementById('edit_duration_display').style.color = 'var(--primary)';
        } else {
          document.getElementById('edit_duration_display').textContent = 'Invalid time range';
          document.getElementById('edit_duration_display').style.color = 'var(--danger)';
        }
      }
    }

    // Update note character counter
    function updateNoteCounter(textareaId, counterId) {
      const textarea = document.getElementById(textareaId);
      const counter = document.getElementById(counterId);
      const count = textarea.value.length;
      
      counter.textContent = count;
      
      if (count > 450) {
        counter.style.color = 'var(--warning)';
      } else if (count > 490) {
        counter.style.color = 'var(--danger)';
      } else {
        counter.style.color = 'var(--text-muted)';
      }
    }

    // Update edit preview
    function updateEditPreview() {
      const customer = document.getElementById('edit_customer').value || 'Not specified';
      const date = document.getElementById('edit_date').value || 'Not set';
      const startTime = document.getElementById('edit_start_time').value || 'Not set';
      const endTime = document.getElementById('edit_end_time').value || 'Not set';
      const location = document.getElementById('edit_location').value || 'Not specified';
      const status = document.getElementById('edit_status').value || 'Pending';
      const cameraman = document.getElementById('edit_cameraman_id').value ? 
        document.getElementById('edit_cameraman_id').options[document.getElementById('edit_cameraman_id').selectedIndex].text : 
        'Auto-assign';
      
      const preview = `
        <div style="display: grid; grid-template-columns: auto 1fr; gap: 8px 12px; font-size: 13px;">
          <strong>Customer:</strong> <span>${customer}</span>
          <strong>Date:</strong> <span>${date}</span>
          <strong>Time:</strong> <span>${startTime} - ${endTime}</span>
          <strong>Location:</strong> <span>${location}</span>
          <strong>Status:</strong> <span class="status-${status.toLowerCase()}">${status}</span>
          <strong>Cameraman:</strong> <span>${cameraman}</span>
        </div>
      `;
      
      document.getElementById('edit_preview').innerHTML = preview;
    }

    // Enable custom customer input
    function enableCustomCustomer() {
      const customerSelect = document.getElementById('customer');
      const customInput = document.createElement('input');
      customInput.type = 'text';
      customInput.name = 'customer';
      customInput.id = 'customer';
      customInput.className = 'form-control';
      customInput.placeholder = 'Enter customer name';
      customInput.required = true;
      
      customerSelect.parentNode.replaceChild(customInput, customerSelect);
    }

    // Admin Action Modal Functions
    function openAdminActionModal(appointmentId, action) {
        document.getElementById('adminAppointmentId').value = appointmentId;
        document.getElementById('adminActionType').value = action;
        
        const modalTitle = document.getElementById('adminModalTitle');
        const previewText = document.getElementById('previewText');
        const actionPreview = document.getElementById('actionPreview');
        const submitBtn = document.getElementById('adminActionSubmit');
        
        if (action === 'approve') {
            modalTitle.textContent = 'Approve Appointment';
            previewText.textContent = `Approve appointment #${appointmentId}`;
            actionPreview.style.display = 'block';
            actionPreview.style.background = 'rgba(16, 185, 129, 0.1)';
            actionPreview.style.borderLeft = '4px solid var(--success)';
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Approve Appointment';
            submitBtn.className = 'btn btn-success';
        } else {
            modalTitle.textContent = 'Reject Appointment';
            previewText.textContent = `Reject appointment #${appointmentId}`;
            actionPreview.style.display = 'block';
            actionPreview.style.background = 'rgba(230, 57, 70, 0.1)';
            actionPreview.style.borderLeft = '4px solid var(--danger)';
            submitBtn.innerHTML = '<i class="fas fa-times"></i> Reject Appointment';
            submitBtn.className = 'btn btn-danger';
        }
        
        document.getElementById('adminActionModal').style.display = 'flex';
    }

    function closeAdminActionModal() {
        document.getElementById('adminActionModal').style.display = 'none';
        document.getElementById('admin_notes').value = '';
    }
    
    // Initialize multi-select event listeners
    document.addEventListener('DOMContentLoaded', function() {
      // Select all checkboxes
      document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(checkbox => {
          checkbox.checked = this.checked;
        });
        document.getElementById('selectAllTable').checked = this.checked;
        updateSelectedCount();
      });
      
      document.getElementById('selectAllTable').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(checkbox => {
          checkbox.checked = this.checked;
        });
        document.getElementById('selectAll').checked = this.checked;
        updateSelectedCount();
      });
      
      // Individual checkbox changes
      document.addEventListener('change', function(e) {
        if (e.target.classList.contains('row-checkbox')) {
          updateSelectedCount();
          
          // Update select all checkboxes
          const totalCheckboxes = document.querySelectorAll('.row-checkbox').length;
          const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
          document.getElementById('selectAll').checked = checkedCount === totalCheckboxes;
          document.getElementById('selectAllTable').checked = checkedCount === totalCheckboxes;
        }
      });

      // Edit modal event listeners
      const editInputs = ['edit_customer', 'edit_date', 'edit_start_time', 'edit_end_time', 'edit_location', 'edit_status', 'edit_cameraman_id'];
      
      editInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
          input.addEventListener('input', updateEditPreview);
          input.addEventListener('change', updateEditPreview);
        }
      });
      
      // Time inputs for duration calculation
      const timeInputs = ['edit_start_time', 'edit_end_time'];
      timeInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
          input.addEventListener('change', updateDurationDisplay);
        }
      });
      
      // Note character counter
      const editNote = document.getElementById('edit_note');
      if (editNote) {
        editNote.addEventListener('input', function() {
          updateNoteCounter('edit_note', 'edit_note_count');
          updateEditPreview();
        });
      }

      // Theme toggle functionality
      const themeToggle = document.getElementById('theme-toggle');
      if (themeToggle) {
        themeToggle.addEventListener('change', function() {
          if (this.checked) {
            document.body.classList.remove('light-mode');
            document.body.classList.add('dark-mode');
            localStorage.setItem('theme', 'dark');
            Toast.info('Dark mode enabled', 'Theme');
          } else {
            document.body.classList.remove('dark-mode');
            document.body.classList.add('light-mode');
            localStorage.setItem('theme', 'light');
            Toast.info('Light mode enabled', 'Theme');
          }
        });

        // Set initial theme
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
          document.body.classList.remove('light-mode');
          document.body.classList.add('dark-mode');
          themeToggle.checked = true;
        }
      }

      // Enhanced form submissions with toasts
      const addForm = document.getElementById('addAppointmentForm');
      if (addForm) {
          addForm.addEventListener('submit', function(e) {
              const submitBtn = this.querySelector('button[type="submit"]');
              if (submitBtn) {
                  const originalHtml = submitBtn.innerHTML;
                  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scheduling...';
                  submitBtn.disabled = true;
                  
                  setTimeout(() => {
                      submitBtn.innerHTML = originalHtml;
                      submitBtn.disabled = false;
                  }, 3000);
              }
          });
      }
      
      // Edit form submission
      const editForm = document.getElementById('editForm');
      if (editForm) {
          editForm.addEventListener('submit', function(e) {
              const submitBtn = this.querySelector('button[type="submit"]');
              if (submitBtn) {
                  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                  submitBtn.disabled = true;
                  
                  setTimeout(() => {
                      submitBtn.innerHTML = '<i class="fas fa-check"></i> Updated!';
                  }, 1000);
              }
          });
      }
      
      // Quick add form
      const quickAddForm = document.getElementById('quickAddForm');
      if (quickAddForm) {
          quickAddForm.addEventListener('submit', function(e) {
              Toast.info('Adding appointment...', 'Processing');
          });
      }

      // Admin action form
      const adminActionForm = document.getElementById('adminActionForm');
      if (adminActionForm) {
          adminActionForm.addEventListener('submit', function(e) {
              Toast.info('Processing admin action...', 'Processing');
          });
      }
    });

    // Toggle Add Appointment Form
    function toggleForm() {
      const form = document.getElementById("appointmentForm");
      form.style.display = form.style.display === "none" ? "block" : "none";
      
      // Set today's date as default
      if (form.style.display === "block") {
        document.getElementById('date').value = new Date().toISOString().split('T')[0];
        document.getElementById('start_time').value = '09:00';
        document.getElementById('end_time').value = '10:00';
        checkCameramanAvailability();
        Toast.info('Add appointment form opened', 'Form');
      }
    }

    // Open Quick Add Modal
    function openQuickAddModal() {
      document.getElementById('quickAddModal').style.display = 'flex';
      // Set current time + 1 hour as default
      const now = new Date();
      const nextHour = new Date(now.getTime() + 60 * 60 * 1000);
      document.getElementById('quick_start_time').value = now.toTimeString().substring(0, 5);
      document.getElementById('quick_end_time').value = nextHour.toTimeString().substring(0, 5);
      Toast.info('Quick add modal opened', 'Quick Add');
    }

    // Close Quick Add Modal
    function closeQuickAddModal() {
      document.getElementById('quickAddModal').style.display = 'none';
    }

    // Search Filter
    document.getElementById("searchInput").addEventListener("keyup", function () {
      const filter = this.value.toLowerCase();
      const rows = document.querySelectorAll(".table tbody tr");
      rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? "" : "none";
      });
    });

    // FullCalendar Initialization
    document.addEventListener("DOMContentLoaded", function () {
      const calendarEl = document.getElementById("calendar");
      const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: "dayGridMonth",
        height: 600,
        headerToolbar: {
          left: "prev,next today",
          center: "title",
          right: "dayGridMonth,timeGridWeek,listWeek"
        },
        events: <?= $events_json ?>,
        eventClick: function (info) {
          info.jsEvent.preventDefault();

          // Populate modal with details
          document.getElementById("modalTitle").innerText = info.event.title;
          document.getElementById("modalDate").innerText =
            "Date: " + info.event.start.toLocaleDateString();
          document.getElementById("modalTime").innerText =
            "Time: " +
            info.event.start.toLocaleTimeString([], {
              hour: "numeric",
              minute: "2-digit",
              hour12: true
            }) +
            " - " +
            (info.event.end
              ? info.event.end.toLocaleTimeString([], {
                  hour: "numeric",
                  minute: "2-digit",
                  hour12: true
                })
              : "N/A");

          document.getElementById("modalLocation").innerText =
            "Location: " + (info.event.extendedProps.location || "N/A");
          
          const note = info.event.extendedProps.note;
          document.getElementById("modalNote").innerText =
            "Note: " + (note && note.trim() !== "" ? note : "No note");
            
          document.getElementById("modalCameraman").innerText =
            "Cameraman: " + (info.event.extendedProps.cameraman || "Not assigned");
          document.getElementById("modalStatus").innerText =
            "Status: " + (info.event.extendedProps.status || "N/A");

          // Show modal
          document.getElementById("eventModal").style.display = "flex";
        }
      });
      calendar.render();
    });

    // Close Modal
    function closeModal() {
      document.getElementById("eventModal").style.display = "none";
    }

    // Enhanced delete confirmation
    function confirmDelete(message) {
      const result = confirm(message);
      if (result) {
        Toast.info('Deleting appointment...', 'Processing');
      }
      return result;
    }

    // Close modals when clicking outside - FIXED FOR EDIT MODAL
    window.onclick = function (event) {
      const eventModal = document.getElementById("eventModal");
      const editModal = document.getElementById("editModal");
      const quickAddModal = document.getElementById("quickAddModal");
      const adminActionModal = document.getElementById("adminActionModal");
      
      if (event.target === eventModal) {
        closeModal();
      }
      if (event.target === editModal) {
        closeEditModal();
      }
      if (event.target === quickAddModal) {
        closeQuickAddModal();
      }
      if (event.target === adminActionModal) {
        closeAdminActionModal();
      }
    };

    // Check cameraman availability
    function checkCameramanAvailability() {
      const date = document.getElementById('date').value;
      const startTime = document.getElementById('start_time').value;
      const endTime = document.getElementById('end_time').value;
      
      if (!date || !startTime || !endTime) {
        return;
      }
      
      // In a real implementation, you would make an AJAX call here
      // For now, we'll simulate availability checking
      const availabilityDiv = document.getElementById('cameramanAvailability');
      
      if (date && startTime && endTime) {
        availabilityDiv.innerHTML = '<span style="color: var(--warning)"><i class="fas fa-sync fa-spin"></i> Checking availability...</span>';
        
        // Simulate API call delay
        setTimeout(() => {
          const isWeekend = new Date(date).getDay() === 0 || new Date(date).getDay() === 6;
          const isPeakTime = parseInt(startTime.split(':')[0]) >= 9 && parseInt(startTime.split(':')[0]) <= 17;
          
          if (isWeekend && isPeakTime) {
            availabilityDiv.innerHTML = '<span style="color: var(--warning)"><i class="fas fa-exclamation-triangle"></i> High demand period. Auto-assignment recommended.</span>';
          } else {
            availabilityDiv.innerHTML = '<span style="color: var(--success)"><i class="fas fa-check-circle"></i> Good availability. Auto-assignment recommended.</span>';
          }
        }, 1000);
      }
    }

    // Mobile sidebar functionality
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
  </script>
</body>
</html>