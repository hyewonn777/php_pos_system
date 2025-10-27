<?php
session_start();
require __DIR__ . '/../db.php';

// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// -------------------------------
// Initialize Cart
// -------------------------------
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// -------------------------------
// Security Functions
// -------------------------------
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_name($name) {
    return preg_match("/^[a-zA-Z\s.'-]{2,50}$/", $name);
}

function validate_time_slot($start_time, $end_time) {
    $start = strtotime($start_time);
    $end = strtotime($end_time);
    return $end > $start && ($end - $start) <= (12 * 3600); // Max 12 hours
}

// -------------------------------
// Fetch Photographers - COMPLETELY REWRITTEN QUERY
// -------------------------------
$photographers = [];

// First, let's see what roles actually exist in the database
$role_check = $conn->query("SELECT DISTINCT role, COUNT(*) as count FROM users GROUP BY role");
echo "<!-- Available roles in database: ";
if ($role_check && $role_check->num_rows > 0) {
    while ($role = $role_check->fetch_assoc()) {
        echo "Role: '" . $role['role'] . "' (Count: " . $role['count'] . ") | ";
    }
} else {
    echo "No roles found in database";
}
echo " -->";

// Debug all users to see what we have
$debug_users = $conn->query("SELECT id, fullname, email, role, status FROM users LIMIT 20");
echo "<!-- All users in database: ";
if ($debug_users && $debug_users->num_rows > 0) {
    while ($user = $debug_users->fetch_assoc()) {
        echo "ID: " . $user['id'] . ", Name: " . $user['fullname'] . ", Role: '" . $user['role'] . "', Status: " . $user['status'] . " | ";
    }
} else {
    echo "No users found in database";
}
echo " -->";

// FIXED: Try multiple variations of photographer role
$photographer_queries = [
    // Exact matches
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE role = 'photographer' AND status = 'active'",
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE role = 'Photographer' AND status = 'active'",
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE role = 'PHOTOGRAPHER' AND status = 'active'",
    
    // Case insensitive
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE LOWER(role) = 'photographer' AND status = 'active'",
    
    // Partial matches
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE role LIKE '%photographer%' AND status = 'active'",
    "SELECT id, fullname, email, profile_image, bio FROM users WHERE role LIKE '%photo%' AND status = 'active'",
    
    // Last resort: get any active user and we'll filter in PHP
    "SELECT id, fullname, email, profile_image, bio, role FROM users WHERE status = 'active'"
];

$photographers_found = false;
foreach ($photographer_queries as $query) {
    $result = $conn->query($query);
    echo "<!-- Query tried: " . htmlspecialchars($query) . " -->";
    
    if ($result && $result->num_rows > 0) {
        echo "<!-- Found " . $result->num_rows . " users with query -->";
        
        while ($user = $result->fetch_assoc()) {
            // For the last query, filter in PHP
            if (strpos($query, "WHERE status = 'active'") !== false && 
                !in_array(strtolower($user['role']), ['photographer', 'photo'])) {
                continue; // Skip non-photographers for the last query
            }
            
            $photographers[] = $user;
            $photographers_found = true;
        }
        
        if ($photographers_found) {
            break; // Stop once we find photographers
        }
    } else {
        echo "<!-- No results for this query -->";
    }
}

// If still no photographers, check if there are any users at all that we can manually assign as photographers
if (empty($photographers)) {
    $any_users = $conn->query("SELECT id, fullname, email, profile_image, bio FROM users WHERE status = 'active' LIMIT 5");
    if ($any_users && $any_users->num_rows > 0) {
        echo "<!-- No photographers found but there are active users. Consider updating their roles to 'photographer' -->";
        while ($user = $any_users->fetch_assoc()) {
            echo "<!-- Available user: " . $user['fullname'] . " (ID: " . $user['id'] . ") -->";
        }
    }
}

// Debug photographers
echo "<!-- Total photographers found: " . count($photographers) . " -->";
foreach($photographers as $p) {
    echo "<!-- Photographer: " . $p['fullname'] . " (ID: " . $p['id'] . ", Role: " . ($p['role'] ?? 'unknown') . ") -->";
}

// -------------------------------
// Handle Add to Cart
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security violation detected.");
    }
    
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-triangle'></i> Please <a href='login.php'>login</a> first before ordering.</div>";
    } else {
        $item_id = intval($_POST['product_id']);
        
        // Validate product exists
        $product_check = $conn->prepare("SELECT id, name, price FROM stock WHERE id = ? AND status='active'");
        $product_check->bind_param("i", $item_id);
        $product_check->execute();
        $product_result = $product_check->get_result();
        
        if ($product_result->num_rows > 0) {
            if (isset($_SESSION['cart'][$item_id])) {
                $_SESSION['cart'][$item_id]++;
            } else {
                $_SESSION['cart'][$item_id] = 1;
            }
            $_SESSION['flash_message'] = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Item added to your cart!</div>";
        } else {
            $_SESSION['flash_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> Product not found.</div>";
        }
        $product_check->close();
    }
    header("Location: index.php#items");
    exit();
}

// -------------------------------
// Handle Calendar Booking
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_photographer'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security violation detected.");
    }
    
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-triangle'></i> Please <a href='login.php'>login</a> first to book a photographer.</div>";
    } else {
        $user_id = intval($_SESSION['user_id']);
        $customer_name = sanitize_input($_POST['customer_name']);
        $event_date = sanitize_input($_POST['event_date']);
        $start_time = sanitize_input($_POST['start_time']);
        $end_time   = sanitize_input($_POST['end_time']);
        $location   = sanitize_input($_POST['location']);
        $event_type = sanitize_input($_POST['event_type']);
        $photographer_id = isset($_POST['photographer_id']) ? intval($_POST['photographer_id']) : 0;
        $notes      = sanitize_input($_POST['notes']);

        // Validate all required fields
        $missing_fields = [];
        if (empty($customer_name)) $missing_fields[] = 'Customer Name';
        if (empty($event_date)) $missing_fields[] = 'Event Date';
        if (empty($start_time)) $missing_fields[] = 'Start Time';
        if (empty($end_time)) $missing_fields[] = 'End Time';
        if (empty($location)) $missing_fields[] = 'Location';
        if (empty($event_type)) $missing_fields[] = 'Event Type';
        if (empty($photographer_id)) $missing_fields[] = 'Photographer';

        if (!empty($missing_fields)) {
            $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> The following fields are required: " . implode(', ', $missing_fields) . "</div>";
        } else {
            // Enhanced validation
            if (!validate_name($customer_name)) {
                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> Please enter a valid name (letters and spaces only, 2-50 characters).</div>";
            } elseif (!validate_time_slot($start_time, $end_time)) {
                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Invalid time slot. End time must be after start time and duration cannot exceed 12 hours.</div>";
            } elseif (strtotime($event_date) < strtotime('today')) {
                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Event date cannot be in the past.</div>";
            } else {
                // Check if photographer exists and is active - FIXED: More flexible role checking
                $photographer_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'photographer' OR role = 'Photographer' OR role = 'PHOTOGRAPHER' OR LOWER(role) LIKE '%photo%')");
                $photographer_check->bind_param("i", $photographer_id);
                $photographer_check->execute();
                $photographer_result = $photographer_check->get_result();
                
                if ($photographer_result->num_rows === 0) {
                    $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Selected photographer is not available.</div>";
                } else {
                    // Check for time slot conflicts
                    $check_stmt = $conn->prepare("SELECT id FROM appointments WHERE photographer_id = ? AND date = ? AND status != 'cancelled' AND (
                        (start_time <= ? AND end_time >= ?) OR 
                        (start_time <= ? AND end_time >= ?) OR
                        (start_time >= ? AND end_time <= ?)
                    )");
                    
                    if ($check_stmt) {
                        $check_stmt->bind_param("isssssss", $photographer_id, $event_date, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> This time slot is already booked for the selected photographer. Please choose a different time or photographer.</div>";
                        } else {
                            $stmt = $conn->prepare("INSERT INTO appointments (user_id, photographer_id, customer, service, date, start_time, end_time, location, event_type, notes, status) VALUES (?, ?, ?, 'Photography', ?, ?, ?, ?, ?, ?, 'pending')");
                            if ($stmt === false) {
                                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> System error. Please try again.</div>";
                            } else {
                                $stmt->bind_param("iiissssss", $user_id, $photographer_id, $customer_name, $event_date, $start_time, $end_time, $location, $event_type, $notes);
                                if ($stmt->execute()) {
                                    $booking_id = $conn->insert_id;
                                    $_SESSION['booking_message'] = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Booking request submitted! Your booking ID is #{$booking_id}. We'll confirm your appointment shortly.</div>";
                                } else {
                                    $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Booking failed. Please try again.</div>";
                                }
                                $stmt->close();
                            }
                        }
                        $check_stmt->close();
                    } else {
                        $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Database error. Please try again.</div>";
                    }
                }
                $photographer_check->close();
            }
        }
    }
    header("Location: index.php#photographer-booking");
    exit();
}

// -------------------------------
// Fetch Products
// -------------------------------
$items_result = $conn->query("SELECT * FROM stock WHERE status='active' ORDER BY id DESC");
if (!$items_result) {
    die("Products query failed: " . $conn->error);
}

// -------------------------------
// Fetch Carousel Items
// -------------------------------
$carousel_result = $conn->query("SELECT * FROM stock WHERE status='active' ORDER BY RAND() LIMIT 5");
if (!$carousel_result) {
    die("Carousel query failed: " . $conn->error);
}

// -------------------------------
// Fetch User Bookings
// -------------------------------
$userBookings = [];
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("
        SELECT a.id, a.customer, a.service, a.date, a.start_time, a.end_time, a.location, a.event_type, a.status, a.created_at, u.fullname as photographer_name
        FROM appointments a
        LEFT JOIN users u ON a.photographer_id = u.id
        WHERE a.user_id = ? 
        ORDER BY a.date DESC, a.start_time DESC
    ");
    if ($stmt !== false) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $userBookings[] = $row;
        }
        $stmt->close();
    }
}

// -------------------------------
// Fetch Booked Dates for Calendar
// -------------------------------
$bookedDates = [];
$bookedSlots = [];
$calendar_stmt = $conn->prepare("
    SELECT date, start_time, end_time, status, photographer_id 
    FROM appointments 
    WHERE status IN ('confirmed', 'pending') 
    AND date >= CURDATE() 
    ORDER BY date, start_time
");
if ($calendar_stmt !== false) {
    $calendar_stmt->execute();
    $calendar_result = $calendar_stmt->get_result();
    while ($row = $calendar_result->fetch_assoc()) {
        $bookedDates[$row['date']][] = [
            'start' => $row['start_time'],
            'end' => $row['end_time'],
            'status' => $row['status'],
            'photographer_id' => $row['photographer_id']
        ];
        $bookedSlots[] = $row;
    }
    $calendar_stmt->close();
}

// Display flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
$booking_message = $_SESSION['booking_message'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['booking_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Marcomedia POS | Professional Media & Photography Services</title>
<meta name="description" content="Marcomedia - Professional photography services, custom merchandise, and media solutions. Book photographers, order custom products, and elevate your brand.">
<meta name="keywords" content="photography, custom merchandise, media services, branding, events">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    /* ----------------------------------- */
    /* ENHANCED COLOR PALETTE & VARIABLES */
    /* ----------------------------------- */
    :root {
        --primary-color: #2563eb;
        --secondary-color: #1e40af;
        --accent-color: #f59e0b;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --error-color: #ef4444;
        --dark-text-color: #1f2937;
        --light-text-color: #6b7280;
        --background-light: #f8fafc;
        --card-bg: #ffffff;
        --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --gradient-accent: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --border-radius: 16px;
        --border-radius-lg: 24px;
        --shadow: 0 20px 40px rgba(0,0,0,0.1);
        --shadow-lg: 0 30px 60px rgba(0,0,0,0.15);
        --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        --header-height: 80px;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html {
        scroll-behavior: smooth;
    }

    body {
        font-family: 'Poppins', sans-serif;
        line-height: 1.7;
        color: var(--dark-text-color);
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }

    h1, h2, h3, h4 {
        font-family: 'Roboto', sans-serif;
        font-weight: 700;
        color: var(--secondary-color);
        margin-bottom: 0.5em;
    }

    section {
        padding: 100px 5%;
        text-align: center;
        position: relative;
    }
    
    /* Utility Classes */
    .container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .gradient-text {
        background: var(--gradient-primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    /* Alert Messages */
    .alert {
        padding: 20px 25px;
        border-radius: var(--border-radius);
        margin-bottom: 25px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: var(--shadow);
        border-left: 6px solid;
        animation: slideInDown 0.5s ease;
    }

    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .alert-success {
        background: linear-gradient(135deg, #d1fae5, #ecfdf5);
        color: #065f46;
        border-left-color: #10b981;
    }
    .alert-error {
        background: linear-gradient(135deg, #fee2e2, #fef2f2);
        color: #991b1b;
        border-left-color: #ef4444;
    }
    .alert-warning {
        background: linear-gradient(135deg, #fef3c7, #fffbeb);
        color: #92400e;
        border-left-color: #f59e0b;
    }
    .alert a {
        color: inherit;
        text-decoration: underline;
        font-weight: 600;
        transition: var(--transition);
    }
    .alert a:hover {
        opacity: 0.8;
    }

    /* Loading Spinner */
    .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid var(--primary-color);
        border-radius: 50%;
        width: 30px;
        height: 30px;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* ----------------------------------- */
    /* ENHANCED HEADER & NAVIGATION */
    /* ----------------------------------- */
    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 5%;
        height: var(--header-height);
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        box-shadow: 0 4px 30px rgba(0,0,0,0.1);
        transition: var(--transition);
    }

    header.scrolled {
        height: 70px;
        background: rgba(255, 255, 255, 0.98);
    }

    /* Logo Styling */
    .logo-link {
        text-decoration: none; 
        transition: var(--transition);
    }

    .logo-link:hover {
        transform: translateY(-2px);
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .logo-img {
        width: 45px;
        height: 45px;
        object-fit: contain;
        filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
    }

    .logo h2 {
        font-size: 2rem;
        font-weight: 800;
        background: var(--gradient-primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.5px;
    }

    /* Navigation */
    header nav {
        display: flex;
        align-items: center;
        gap: 30px;
    }

    header nav a {
        color: var(--dark-text-color);
        text-decoration: none;
        font-weight: 600;
        padding: 12px 0;
        transition: var(--transition);
        position: relative;
        font-size: 1.1rem;
    }

    header nav a:hover {
        color: var(--primary-color);
        transform: translateY(-2px);
    }

    header nav a:after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 0;
        height: 3px;
        background: var(--gradient-primary);
        transition: var(--transition);
        border-radius: 2px;
    }

    header nav a:hover:after {
        width: 100%;
    }

    /* Enhanced Cart Dropdown */
    .cart-dropdown { 
        position: relative; 
        cursor: pointer; 
        padding: 12px 20px;
        border-radius: var(--border-radius);
        background: var(--background-light);
        transition: var(--transition);
        font-weight: 600;
    }

    .cart-dropdown:hover {
        background: var(--primary-color);
        color: white;
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .cart-items { 
        display: none; 
        position: absolute;
        right: 0;
        top: 100%;
        background: var(--card-bg);
        border-radius: var(--border-radius);
        width: 320px;
        padding: 25px;
        box-shadow: var(--shadow-lg);
        z-index: 1000;
        margin-top: 15px;
        animation: fadeInUp 0.3s ease;
    }
    
    .cart-dropdown:hover .cart-items { 
        display: block; 
    }
    
    .cart-item { 
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f1f5f9;
        transition: var(--transition);
    }

    .cart-item:hover {
        transform: translateX(5px);
    }
    
    .cart-item img { 
        width: 60px;
        height: 60px;
        object-fit: cover;
        border-radius: 12px;
        box-shadow: var(--shadow);
    }
    
    .view-cart { 
        display: block;
        text-align: center;
        background: var(--gradient-primary);
        color: #fff;
        padding: 15px;
        border-radius: var(--border-radius);
        margin-top: 15px;
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        box-shadow: var(--shadow);
    }

    .view-cart:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ----------------------------------- */
    /* ENHANCED HERO SECTION - FIXED BACKGROUND PATH */
    /* ----------------------------------- */
    .hero {
        position: relative;
        color: #fff;
        /* FIXED: Corrected background image path */
        background: var(--gradient-primary), 
                    url('../images/IMG_7399.jpg') center/cover no-repeat;
        height: 100vh;
        min-height: 800px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding: 0 20px;
        overflow: hidden;
    }

    .hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" fill="%23ffffff" opacity="0.1"><circle cx="200" cy="200" r="3"/><circle cx="600" cy="300" r="2"/><circle cx="800" cy="150" r="4"/><circle cx="300" cy="600" r="3"/><circle cx="700" cy="700" r="2"/></svg>');
        animation: float 20s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-20px) rotate(180deg); }
    }

    .hero-content {
        position: relative;
        z-index: 2;
        max-width: 800px;
    }
    
    .hero h1 {
        font-size: 5rem;
        margin-bottom: 25px;
        color: #fff;
        font-weight: 800;
        text-shadow: 2px 2px 20px rgba(0,0,0,0.3);
        letter-spacing: -1px;
        line-height: 1.1;
        animation: slideInUp 1s ease;
    }

    .hero p.tagline {
        font-size: 1.8rem;
        margin-bottom: 50px;
        font-weight: 300;
        color: rgba(255,255,255,0.9);
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
        animation: slideInUp 1s ease 0.2s both;
    }

    .hero .button {
        background: var(--gradient-accent);
        padding: 20px 50px;
        color: #fff;
        text-decoration: none;
        font-weight: 700;
        border-radius: 50px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        box-shadow: 0 15px 35px rgba(79, 172, 254, 0.4);
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 12px;
        font-size: 1.1rem;
        animation: slideInUp 1s ease 0.4s both;
        position: relative;
        overflow: hidden;
    }

    .hero .button::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: 0.5s;
    }

    .hero .button:hover::before {
        left: 100%;
    }

    .hero .button:hover {
        transform: translateY(-5px) scale(1.05);
        box-shadow: 0 20px 40px rgba(79, 172, 254, 0.6);
    }

    /* Scroll Down Indicator */
    .scroll-indicator {
        position: absolute;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        animation: bounce 2s infinite;
    }

    .scroll-indicator i {
        font-size: 2rem;
        color: rgba(255,255,255,0.8);
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% { transform: translateX(-50%) translateY(0); }
        40% { transform: translateX(-50%) translateY(-10px); }
        60% { transform: translateX(-50%) translateY(-5px); }
    }

    /* ----------------------------------- */
    /* ENHANCED CAROUSEL */
    /* ----------------------------------- */
    .carousel-section {
        padding: 80px 5%;
        background: var(--background-light);
    }

    .carousel-header {
        text-align: center;
        margin-bottom: 50px;
    }

    .carousel-header h2 {
        font-size: 3rem;
        margin-bottom: 15px;
        background: var(--gradient-primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .carousel-container {
        position: relative;
        max-width: 1200px;
        margin: 0 auto;
        border-radius: var(--border-radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-lg);
    }

    .carousel-slide {
        display: flex;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .carousel-slide img {
        flex-shrink: 0;
        width: 100%;
        height: 600px;
        object-fit: cover;
    }

    .carousel-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255,255,255,0.9);
        border: none;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        font-size: 1.5rem;
        color: var(--primary-color);
        cursor: pointer;
        transition: var(--transition);
        box-shadow: var(--shadow);
        z-index: 10;
    }

    .carousel-nav:hover {
        background: var(--primary-color);
        color: white;
        transform: translateY(-50%) scale(1.1);
    }

    .carousel-prev {
        left: 20px;
    }

    .carousel-next {
        right: 20px;
    }

    /* ----------------------------------- */
    /* ENHANCED PRODUCTS SECTION - FIXED BUTTONS */
    /* ----------------------------------- */
    #items {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        position: relative;
        color: white;
    }

    #items::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="%23ffffff" opacity="0.05"><circle cx="50" cy="50" r="2"/></svg>') repeat;
    }

    #items h2 {
        font-size: 3.5rem;
        margin-bottom: 60px;
        color: #fff;
        position: relative;
        display: inline-block;
        text-shadow: 2px 2px 10px rgba(0,0,0,0.2);
    }

    #items h2:after {
        content: '';
        position: absolute;
        bottom: -15px;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 5px;
        background: var(--gradient-accent);
        border-radius: 3px;
    }

    .items-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 40px;
        max-width: 1300px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }

    .item-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius-lg);
        text-align: left;
        overflow: hidden;
        transition: var(--transition);
        box-shadow: var(--shadow);
        position: relative;
        border: 1px solid rgba(255,255,255,0.2);
    }

    .item-card:hover {
        transform: translateY(-15px) scale(1.02);
        box-shadow: var(--shadow-lg);
    }

    .item-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, transparent 100%);
        pointer-events: none;
    }

    .item-card img {
        width: 100%;
        height: 250px;
        object-fit: cover;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .item-card:hover img {
        transform: scale(1.1);
    }

    .item-content {
        padding: 30px;
    }

    .item-card h3 {
        font-size: 1.5rem;
        margin-bottom: 12px;
        color: var(--dark-text-color);
        font-weight: 700;
    }

    .item-card .price {
        font-size: 1.4rem;
        color: var(--primary-color);
        font-weight: 800;
        margin-bottom: 25px;
        display: block;
    }

    /* FIXED BUTTON STYLES */
    .item-card .actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        width: 100%;
    }

    .item-card form {
        width: 100%;
    }

    .item-card form button {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 15px 20px;
        border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-weight: 600;
        font-size: 1rem;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        min-height: 54px;
    }

    .item-card form button::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: 0.5s;
    }

    .item-card form button:hover::before {
        left: 100%;
    }

    .item-card form button.add-cart {
        background: var(--gradient-primary);
        color: #fff;
    }

    .item-card form button.design-item {
        background: var(--gradient-secondary);
        color: #fff;
    }

    .item-card form button:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }

    /* ----------------------------------- */
    /* ENHANCED PHOTOGRAPHER BOOKING SECTION */
    /* ----------------------------------- */
    #photographer-booking {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        position: relative;
        overflow: hidden;
    }

    #photographer-booking::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 200%;
        background: var(--gradient-accent);
        opacity: 0.03;
        transform: rotate(45deg);
    }

    #photographer-booking h2 {
        font-size: 3.5rem;
        margin-bottom: 60px;
        position: relative;
        display: inline-block;
    }

    #photographer-booking h2:after {
        content: '';
        position: absolute;
        bottom: -15px;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 5px;
        background: var(--gradient-primary);
        border-radius: 3px;
    }

    .booking-steps {
        display: flex;
        justify-content: center;
        gap: 40px;
        margin-bottom: 50px;
        position: relative;
        z-index: 2;
    }

    .step {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 20px 30px;
        background: white;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .step.active {
        background: var(--gradient-primary);
        color: white;
        transform: translateY(-5px);
    }

    .step-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }

    .step.active .step-number {
        background: white;
        color: var(--primary-color);
    }

    .booking-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 50px;
        max-width: 1400px;
        margin: 0 auto;
        align-items: start;
        position: relative;
        z-index: 2;
    }

    .calendar-section {
        background: var(--card-bg);
        border-radius: var(--border-radius-lg);
        padding: 40px;
        box-shadow: var(--shadow-lg);
        border: 1px solid rgba(255,255,255,0.2);
        backdrop-filter: blur(20px);
    }

    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f1f5f9;
    }

    .calendar-nav {
        background: var(--gradient-primary);
        color: white;
        border: none;
        padding: 12px 16px;
        border-radius: var(--border-radius);
        cursor: pointer;
        transition: var(--transition);
        font-weight: 600;
    }

    .calendar-nav:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
        margin-bottom: 25px;
    }

    .calendar-day-header {
        font-weight: 700;
        color: var(--primary-color);
        padding: 15px;
        text-align: center;
        font-size: 0.9rem;
    }

    .calendar-day {
        padding: 15px;
        text-align: center;
        border-radius: 12px;
        cursor: pointer;
        transition: var(--transition);
        border: 2px solid transparent;
        font-weight: 600;
        position: relative;
        overflow: hidden;
    }

    .calendar-day::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: var(--gradient-primary);
        opacity: 0;
        transition: var(--transition);
    }

    .calendar-day:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .calendar-day:hover::before {
        opacity: 0.1;
    }

    .calendar-day.selected {
        background: var(--gradient-primary);
        color: white;
        border-color: var(--secondary-color);
        transform: scale(1.05);
    }

    .calendar-day.booked {
        background: #fee2e2;
        color: #dc2626;
        cursor: not-allowed;
        opacity: 0.7;
    }

    .calendar-day.pending {
        background: #fef3c7;
        color: #d97706;
    }

    .calendar-day.today {
        border: 2px solid var(--accent-color);
        background: #fffbeb;
    }

    .calendar-day.other-month {
        color: #cbd5e1;
        cursor: not-allowed;
    }

    .time-input-section {
        margin-top: 30px;
        background: #f8fafc;
        padding: 25px;
        border-radius: var(--border-radius);
    }

    .time-inputs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 15px;
    }

    .time-input-group {
        display: flex;
        flex-direction: column;
    }

    .time-input-group label {
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--secondary-color);
        font-size: 1rem;
    }

    .time-input-group input {
        padding: 15px;
        border: 2px solid #e2e8f0;
        border-radius: var(--border-radius);
        font-size: 1rem;
        transition: var(--transition);
        background: white;
    }

    .time-input-group input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
        transform: translateY(-2px);
    }

    .duration-display {
        text-align: center;
        margin-top: 15px;
        padding: 15px;
        background: var(--gradient-accent);
        color: white;
        border-radius: var(--border-radius);
        font-weight: 600;
        box-shadow: var(--shadow);
    }

    .photographer-selection {
        margin-top: 30px;
    }

    .photographer-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
        margin-top: 20px;
        max-height: 400px;
        overflow-y: auto;
        padding: 15px;
        background: #f8fafc;
        border-radius: var(--border-radius);
    }

    .photographer-card {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 20px;
        border: 2px solid #e2e8f0;
        border-radius: var(--border-radius);
        cursor: pointer;
        transition: var(--transition);
        background: white;
        position: relative;
        overflow: hidden;
    }

    .photographer-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(37, 99, 235, 0.1), transparent);
        transition: 0.5s;
    }

    .photographer-card:hover::before {
        left: 100%;
    }

    .photographer-card:hover {
        border-color: var(--primary-color);
        background: #f8faff;
        transform: translateX(5px);
    }

    .photographer-card.selected {
        border-color: var(--primary-color);
        background: linear-gradient(135deg, #eff6ff, #f0f9ff);
        box-shadow: var(--shadow);
    }

    .photographer-avatar {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #e2e8f0;
        transition: var(--transition);
        box-shadow: var(--shadow);
    }

    .photographer-card.selected .photographer-avatar {
        border-color: var(--primary-color);
        transform: scale(1.1);
    }

    .photographer-info {
        flex: 1;
        text-align: left;
    }

    .photographer-info h4 {
        margin-bottom: 8px;
        color: var(--secondary-color);
        font-size: 1.2rem;
    }

    .photographer-info p {
        color: var(--light-text-color);
        font-size: 0.9rem;
        margin-bottom: 5px;
    }

    .photographer-bio {
        font-size: 0.85rem;
        color: #64748b;
        margin-top: 5px;
    }

    .availability-status {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .available {
        background: #d1fae5;
        color: #065f46;
    }

    .busy {
        background: #fee2e2;
        color: #991b1b;
    }

    .booking-form-section {
        background: var(--card-bg);
        border-radius: var(--border-radius-lg);
        padding: 40px;
        box-shadow: var(--shadow-lg);
        border: 1px solid rgba(255,255,255,0.2);
        backdrop-filter: blur(20px);
    }

    #booking-form {
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        text-align: left;
    }

    .form-group label {
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--secondary-color);
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    #booking-form input,
    #booking-form select,
    #booking-form textarea {
        padding: 18px;
        border-radius: var(--border-radius);
        border: 2px solid #e2e8f0;
        font-size: 1rem;
        color: var(--dark-text-color);
        transition: var(--transition);
        font-family: inherit;
        background: white;
    }

    #booking-form input:focus,
    #booking-form select:focus,
    #booking-form textarea:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
        transform: translateY(-2px);
    }

    #booking-form button[type="submit"] {
        background: var(--gradient-primary);
        color: #fff;
        padding: 20px;
        border: none;
        border-radius: var(--border-radius);
        font-size: 1.2rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-top: 20px;
        width: 100%;
        position: relative;
        overflow: hidden;
    }

    #booking-form button[type="submit"]::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: 0.5s;
    }

    #booking-form button[type="submit"]:hover::before {
        left: 100%;
    }

    #booking-form button[type="submit"]:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 30px rgba(37, 99, 235, 0.4);
    }

    #booking-form button[type="submit"]:disabled {
        background: #cbd5e1;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    #booking-form button[type="submit"]:disabled:hover::before {
        left: -100%;
    }

    /* Bookings List */
    .bookings-list {
        margin-top: 50px;
        text-align: left;
    }

    .booking-card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
        border-left: 6px solid var(--primary-color);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .booking-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(37, 99, 235, 0.05), transparent);
        transition: 0.5s;
    }

    .booking-card:hover::before {
        left: 100%;
    }

    .booking-card:hover {
        transform: translateX(5px);
        box-shadow: var(--shadow-lg);
    }

    .booking-card.confirmed {
        border-left-color: var(--success-color);
    }

    .booking-card.pending {
        border-left-color: var(--warning-color);
    }

    .booking-card.cancelled {
        border-left-color: var(--error-color);
    }

    .booking-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .booking-id {
        font-weight: 700;
        color: var(--secondary-color);
        font-size: 1.1rem;
    }

    .booking-status {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-confirmed {
        background: #d1fae5;
        color: #065f46;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .booking-summary {
        background: #f8fafc;
        padding: 25px;
        border-radius: var(--border-radius);
        margin: 25px 0;
        border-left: 4px solid var(--primary-color);
    }

    .booking-summary h4 {
        color: var(--secondary-color);
        margin-bottom: 15px;
        font-size: 1.3rem;
    }

    .booking-summary p {
        margin: 8px 0;
        font-weight: 500;
    }

    /* ----------------------------------- */
    /* ENHANCED FOOTER */
    /* ----------------------------------- */
    footer {
        background: linear-gradient(135deg, var(--secondary-color), #1e3a8a);
        color: #e0f2fe;
        padding: 80px 20px 40px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" fill="%23ffffff" opacity="0.05"><circle cx="100" cy="100" r="2"/><circle cx="300" cy="300" r="3"/><circle cx="500" cy="150" r="2"/><circle cx="700" cy="400" r="4"/><circle cx="900" cy="250" r="2"/></svg>');
    }
    
    footer p {
        margin-bottom: 20px;
        font-size: 1.3rem;
        position: relative;
        z-index: 2;
    }

    footer .social-links {
        display: flex;
        justify-content: center;
        gap: 25px;
        margin: 30px 0 40px;
        position: relative;
        z-index: 2;
    }

    footer .social-links a {
        color: #bae6fd;
        text-decoration: none;
        font-size: 1.4rem;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        background: rgba(255,255,255,0.1);
        border-radius: var(--border-radius);
        backdrop-filter: blur(10px);
    }

    footer .social-links a:hover {
        color: #fff;
        transform: translateY(-3px);
        background: rgba(255,255,255,0.2);
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }

    .copyright {
        margin-top: 40px;
        font-size: 1em;
        color: #94a3b8;
        border-top: 1px solid rgba(255,255,255,0.2);
        padding-top: 30px;
        position: relative;
        z-index: 2;
    }

    /* ----------------------------------- */
    /* RESPONSIVENESS */
    /* ----------------------------------- */
    @media (max-width: 1200px) {
        .hero h1 { 
            font-size: 4rem; 
        }
        
        .booking-container {
            gap: 30px;
        }
    }

    @media (max-width: 992px) {
        .hero h1 { 
            font-size: 3.5rem; 
        }
        
        .booking-container {
            grid-template-columns: 1fr;
            gap: 40px;
        }

        .booking-steps {
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .step {
            width: 100%;
            max-width: 400px;
        }
    }

    @media (max-width: 768px) {
        .hero h1 { 
            font-size: 3rem; 
        }
        
        section { 
            padding: 80px 20px; 
        }
        
        header {
            flex-direction: column;
            height: auto;
            padding: 15px;
            gap: 15px;
            position: relative;
        }
        
        header nav {
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .hero {
            height: auto;
            min-height: 600px;
            padding: 120px 20px 80px;
        }

        .time-inputs {
            grid-template-columns: 1fr;
        }

        .calendar-grid {
            gap: 4px;
        }

        .calendar-day {
            padding: 10px 5px;
            font-size: 0.9rem;
        }

        /* FIXED: Mobile buttons layout */
        .item-card .actions {
            grid-template-columns: 1fr;
        }

        .cart-items {
            width: 280px;
            right: -20px;
        }

        .photographer-card {
            flex-direction: column;
            text-align: center;
        }

        .availability-status {
            margin-left: 0;
            margin-top: 10px;
        }

        .carousel-slide img {
            height: 400px;
        }
    }

    @media (max-width: 480px) {
        .hero h1 { 
            font-size: 2.5rem; 
        }
        
        .hero p.tagline {
            font-size: 1.3rem;
        }
        
        .items-grid {
            grid-template-columns: 1fr;
        }

        #items h2,
        #photographer-booking h2 {
            font-size: 2.5rem;
        }

        .calendar-section,
        .booking-form-section {
            padding: 25px;
        }

        footer .social-links {
            flex-direction: column;
            align-items: center;
        }
    }
</style>
</head>
<body>
<header>
    <a href="index.php#home" class="logo-link">
        <div class="logo">
            <img src="images/rsz_logo.png" alt="Marcomedia Logo" class="logo-img">
            <h2>Marcomedia</h2>
        </div>
    </a>
    
    <nav>
        <a href="index.php#items"><i class="fas fa-box"></i> Products</a>
        <a href="index.php#photographer-booking"><i class="fas fa-camera"></i> Book Photographer</a>

        <div class="cart-dropdown">
            <span><i class="fas fa-shopping-cart"></i> Cart (<?php echo array_sum($_SESSION['cart']); ?>)</span>
            <?php if(!empty($_SESSION['cart'])): ?>
                <div class="cart-items">
                    <?php
                    $ids = implode(',', array_keys($_SESSION['cart']));
                    $cartQuery = $conn->query("SELECT * FROM stock WHERE id IN ($ids)");
                    while($cartItem = $cartQuery->fetch_assoc()):
                        $qty = $_SESSION['cart'][$cartItem['id']];
                    ?>
                    <div class="cart-item">
                        <img src="<?php echo htmlspecialchars($cartItem['image_path']); ?>" alt="<?php echo htmlspecialchars($cartItem['name']); ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($cartItem['name']); ?></strong>
                            <div>x <?php echo $qty; ?></div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <a href="my_cart.php" class="view-cart"><i class="fas fa-shopping-bag"></i> View Cart</a>
                </div>
            <?php endif; ?>
        </div>
        <?php if(isset($_SESSION['user_id'])): ?>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php else: ?>
            <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
        <?php endif; ?>
    </nav>
</header>

<!-- Flash Messages -->
<?php if($flash_message): ?>
    <div class="container" style="margin-top: 100px;">
        <?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero" id="home">
    <div class="hero-content">
        <h1>Capture Moments That <span class="gradient-text">Last Forever</span></h1>
        <p class="tagline">Professional Photography Services & Custom Merchandise Solutions for Every Occasion</p>
        <a href="#photographer-booking" class="button"><i class="fas fa-camera"></i> BOOK A PHOTOGRAPHER NOW</a>
    </div>
    <div class="scroll-indicator">
        <i class="fas fa-chevron-down"></i>
    </div>
</section>

<!-- Carousel Section -->
<section class="carousel-section">
    <div class="carousel-header">
        <h2>Featured Work</h2>
        <p style="color: var(--light-text-color); font-size: 1.2rem;">Discover our latest photography projects and custom merchandise</p>
    </div>
    <div class="carousel-container">
        <div class="carousel-slide">
            <?php 
            $slides = [];
            if ($carousel_result->num_rows > 0) {
                 while ($slide = $carousel_result->fetch_assoc()): 
                    $slides[] = $slide;
                    ?>
                    <img src="<?php echo htmlspecialchars($slide['image_path']); ?>" alt="Featured Work: <?php echo htmlspecialchars($slide['name']); ?>">
                 <?php endwhile;
            }
            
            if (!empty($slides)):
                $first_slide = $slides[0];
                ?>
                <img src="<?php echo htmlspecialchars($first_slide['image_path']); ?>" alt="Featured Work: <?php echo htmlspecialchars($first_slide['name']); ?>" class="clone-slide">
            <?php endif; ?>
        </div>
        <button class="carousel-nav carousel-prev"><i class="fas fa-chevron-left"></i></button>
        <button class="carousel-nav carousel-next"><i class="fas fa-chevron-right"></i></button>
    </div>
</section>

<!-- Products Section -->
<section id="items">
    <h2>Premium Products & Custom Merchandise</h2>
    <p style="color: rgba(255,255,255,0.8); font-size: 1.3rem; margin-bottom: 40px; max-width: 600px; margin-left: auto; margin-right: auto;">Transform your memories into tangible treasures with our high-quality custom products</p>
    <div class="items-grid">
        <?php if($items_result->num_rows > 0): ?>
            <?php while($item = $items_result->fetch_assoc()): ?>
                <div class="item-card">
                    <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                    <div class="item-content">
                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                        <span class="price">₱<?php echo number_format($item['price'], 2); ?></span>
                        <p style="color: var(--light-text-color); margin-bottom: 20px; font-size: 0.95rem;"><?php echo htmlspecialchars(substr($item['description'] ?? 'High-quality custom product', 0, 100)); ?>...</p>
                        
                        <!-- FIXED BUTTONS - Using grid layout -->
                        <div class="actions">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" name="add_to_cart" class="add-cart">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                            </form>

                            <form method="GET" action="design_item.php">
                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="design-item">
                                    <i class="fas fa-palette"></i> Customize Design
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: rgba(255,255,255,0.1); border-radius: var(--border-radius); backdrop-filter: blur(10px);">
                <i class="fas fa-box-open" style="font-size: 4rem; color: rgba(255,255,255,0.5); margin-bottom: 20px;"></i>
                <h3 style="color: white; margin-bottom: 15px;">No Products Available</h3>
                <p style="color: rgba(255,255,255,0.8); font-size: 1.1rem;">We're currently updating our product catalog. Please check back soon!</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Photographer Booking Section -->
<section id="photographer-booking">
    <h2>Book Your Professional Photographer</h2>
    <p style="color: var(--light-text-color); font-size: 1.3rem; margin-bottom: 50px; max-width: 700px; margin-left: auto; margin-right: auto;">Schedule your perfect photoshoot in just a few simple steps</p>
    
    <?php if($booking_message): ?>
        <div class="container" style="max-width: 1200px; margin-bottom: 40px;">
            <?php echo $booking_message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Booking Steps -->
    <div class="booking-steps">
        <div class="step active">
            <div class="step-number">1</div>
            <span>Choose Date & Time</span>
        </div>
        <div class="step">
            <div class="step-number">2</div>
            <span>Select Photographer</span>
        </div>
        <div class="step">
            <div class="step-number">3</div>
            <span>Event Details</span>
        </div>
        <div class="step">
            <div class="step-number">4</div>
            <span>Confirm Booking</span>
        </div>
    </div>
    
    <!-- Form now wraps the entire booking container -->
    <form method="POST" id="booking-form">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="booking-container">
            <!-- Calendar Section -->
            <div class="calendar-section">
                <div class="calendar-header">
                    <button class="calendar-nav" id="prev-month" type="button"><i class="fas fa-chevron-left"></i></button>
                    <h3 id="current-month">Month Year</h3>
                    <button class="calendar-nav" id="next-month" type="button"><i class="fas fa-chevron-right"></i></button>
                </div>
                <div class="calendar-grid" id="calendar-grid">
                    <!-- Calendar will be generated by JavaScript -->
                </div>

                <!-- Time Selection -->
                <div class="time-input-section">
                    <h4><i class="fas fa-clock"></i> Select Your Preferred Time</h4>
                    <p style="color: var(--light-text-color); margin-bottom: 15px; font-size: 0.95rem;">Choose start and end time for your photoshoot (max 12 hours)</p>
                    <div class="time-inputs">
                        <div class="time-input-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" min="06:00" max="22:00" required>
                        </div>
                        <div class="time-input-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" min="06:00" max="22:00" required>
                        </div>
                    </div>
                    <div class="duration-display" id="duration-display">
                        <i class="fas fa-hourglass-half"></i> Duration: 0 hours
                    </div>
                </div>

                <!-- Photographer Selection - FIXED DISPLAY -->
                <div class="photographer-selection">
                    <h4><i class="fas fa-user-camera"></i> Choose Your Photographer</h4>
                    <p style="color: var(--light-text-color); margin-bottom: 15px; font-size: 0.95rem;">Select from our talented team of professional photographers</p>
                    <div class="photographer-grid" id="photographer-grid">
                        <?php if(!empty($photographers)): ?>
                            <?php foreach($photographers as $photographer): ?>
                                <div class="photographer-card" data-photographer-id="<?php echo $photographer['id']; ?>">
                                    <?php
                                    // Handle profile image - use default if not available
                                    $avatar_url = 'https://ui-avatars.com/api/?name=' . urlencode($photographer['fullname']) . '&background=2563eb&color=fff&size=100';
                                    if (!empty($photographer['profile_image'])) {
                                        $avatar_url = htmlspecialchars($photographer['profile_image']);
                                    }
                                    ?>
                                    <img src="<?php echo $avatar_url; ?>" 
                                         alt="<?php echo htmlspecialchars($photographer['fullname']); ?>" class="photographer-avatar">
                                    <div class="photographer-info">
                                        <h4><?php echo htmlspecialchars($photographer['fullname']); ?></h4>
                                        <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($photographer['email']); ?></p>
                                        <?php if(!empty($photographer['bio'])): ?>
                                            <p class="photographer-bio"><?php echo htmlspecialchars(substr($photographer['bio'], 0, 100)); ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="availability-status available" id="status-<?php echo $photographer['id']; ?>">
                                        <i class="fas fa-check-circle"></i> Available
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: var(--light-text-color); background: #f8fafc; border-radius: var(--border-radius);">
                                <i class="fas fa-camera-slash" style="font-size: 3rem; margin-bottom: 20px; color: #cbd5e1;"></i>
                                <h4 style="color: var(--light-text-color); margin-bottom: 15px;">No Photographers Available</h4>
                                <p style="margin-bottom: 20px;">We're currently updating our photographer team. Please check back soon or contact us for special arrangements.</p>
                                <div style="background: #f1f5f9; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: left;">
                                    <h5 style="color: var(--secondary-color); margin-bottom: 10px;">Debug Information:</h5>
                                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 5px;">
                                        <strong>Queries tried:</strong><br>
                                        • SELECT ... WHERE role = 'photographer' AND status = 'active'<br>
                                        • SELECT ... WHERE role = 'Photographer' AND status = 'active'<br>
                                        • SELECT ... WHERE role = 'PHOTOGRAPHER' AND status = 'active'<br>
                                        • SELECT ... WHERE LOWER(role) = 'photographer' AND status = 'active'<br>
                                        • SELECT ... WHERE role LIKE '%photographer%' AND status = 'active'<br>
                                        • SELECT ... WHERE role LIKE '%photo%' AND status = 'active'
                                    </p>
                                    <p style="font-size: 0.85rem; color: #64748b;">
                                        <strong>Solution:</strong> Add users with role 'photographer' to your database or update existing user roles.
                                    </p>
                                </div>
                                <a href="mailto:info@marcomedia.com" style="background: var(--gradient-primary); color: white; padding: 12px 25px; border-radius: var(--border-radius); text-decoration: none; font-weight: 600; display: inline-block;">
                                    <i class="fas fa-envelope"></i> Contact Us
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Booking Form Section -->
            <div class="booking-form-section">
                <div class="booking-summary" id="booking-summary" style="display: none;">
                    <h4><i class="fas fa-clipboard-check"></i> Booking Summary</h4>
                    <div id="summary-details"></div>
                </div>

                <input type="hidden" name="event_date" id="selected-date">
                <input type="hidden" name="photographer_id" id="selected-photographer">
                
                <div class="form-group">
                    <label for="customer_name"><i class="fas fa-user"></i> Your Full Name</label>
                    <input type="text" name="customer_name" id="customer_name" required 
                           placeholder="Enter your full name"
                           value="<?php 
                           // Try multiple session variables to find the user's name
                           if (isset($_SESSION['user_name']) && !empty($_SESSION['user_name'])) {
                               echo htmlspecialchars($_SESSION['user_name']);
                           } elseif (isset($_SESSION['fullname']) && !empty($_SESSION['fullname'])) {
                               echo htmlspecialchars($_SESSION['fullname']);
                           } elseif (isset($_SESSION['name']) && !empty($_SESSION['name'])) {
                               echo htmlspecialchars($_SESSION['name']);
                           } elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
                               echo htmlspecialchars($_SESSION['username']);
                           }
                           ?>">
                </div>

                <div class="form-group">
                    <label for="event_type"><i class="fas fa-calendar-alt"></i> Event Type</label>
                    <select name="event_type" id="event_type" required>
                        <option value="">Select Event Type</option>
                        <option value="Wedding">💍 Wedding Photography</option>
                        <option value="Portrait">👤 Portrait Session</option>
                        <option value="Corporate">💼 Corporate Event</option>
                        <option value="Product">📦 Product Photography</option>
                        <option value="Real Estate">🏠 Real Estate</option>
                        <option value="Event">🎉 Special Event</option>
                        <option value="Family">👨‍👩‍👧‍👦 Family Photos</option>
                        <option value="Other">✨ Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="location"><i class="fas fa-map-marker-alt"></i> Event Location</label>
                    <input type="text" name="location" id="location" placeholder="Where will the event take place? (Full address)" required>
                </div>

                <div class="form-group">
                    <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes & Requirements</label>
                    <textarea name="notes" id="notes" rows="4" placeholder="Any specific requirements, special instructions, or details about your event..."></textarea>
                </div>

                <button type="submit" name="book_photographer" id="submit-booking" disabled>
                    <i class="fas fa-calendar-check"></i> CONFIRM BOOKING
                </button>

                <!-- User's Bookings -->
                <?php if(!empty($userBookings)): ?>
                <div class="bookings-list">
                    <h3 style="text-align: center; margin: 40px 0 25px; color: var(--secondary-color);"><i class="fas fa-history"></i> Your Recent Bookings</h3>
                    <?php foreach($userBookings as $booking): ?>
                        <div class="booking-card <?php echo $booking['status']; ?>">
                            <div class="booking-header">
                                <span class="booking-id">Booking #<?php echo $booking['id']; ?></span>
                                <span class="booking-status status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </div>
                            <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 15px;"><?php echo htmlspecialchars($booking['event_type']); ?></p>
                            <p><i class="fas fa-user"></i> <strong>Customer:</strong> <?php echo htmlspecialchars($booking['customer']); ?></p>
                            <?php if(isset($booking['photographer_name'])): ?>
                                <p><i class="fas fa-user-camera"></i> <strong>Photographer:</strong> <?php echo htmlspecialchars($booking['photographer_name']); ?></p>
                            <?php endif; ?>
                            <p><i class="fas fa-calendar"></i> <strong>Date:</strong> <?php echo date('F j, Y', strtotime($booking['date'])); ?></p>
                            <p><i class="fas fa-clock"></i> <strong>Time:</strong> <?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></p>
                            <p><i class="fas fa-map-marker-alt"></i> <strong>Location:</strong> <?php echo htmlspecialchars($booking['location']); ?></p>
                            <?php if(!empty($booking['notes'])): ?>
                                <p style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 8px; border-left: 4px solid var(--primary-color);">
                                    <i class="fas fa-comment"></i> <strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</section>

<!-- Footer -->
<footer id="footer">
    <p>Marcomedia POS | <strong>Where Memories Become Masterpieces</strong></p>
    
    <div class="social-links">
        <a href="#"><i class="fab fa-facebook-f"></i> Facebook</a>
        <a href="#"><i class="fab fa-instagram"></i> Instagram</a>
        <a href="#"><i class="fab fa-tiktok"></i> TikTok</a>
        <a href="#"><i class="fab fa-youtube"></i> YouTube</a>
        <a href="mailto:info@marcomedia.com"><i class="fas fa-envelope"></i> Email Us</a>
    </div>
    
    <p class="copyright">&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved. | Crafted with ❤️ for amazing experiences</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Header scroll effect
    const header = document.querySelector('header');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 100) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // Carousel functionality
    const carouselSlide = document.querySelector('.carousel-slide');
    const slides = document.querySelectorAll('.carousel-slide img');
    const prevBtn = document.querySelector('.carousel-prev');
    const nextBtn = document.querySelector('.carousel-next');
    const slideCount = slides.length - 1; 
    let index = 0;

    if (slideCount > 0) {
        const intervalTime = 5000;
        const transitionDuration = 800;

        function showNextSlide() {
            index++;
            carouselSlide.style.transition = `transform ${transitionDuration}ms cubic-bezier(0.25, 0.46, 0.45, 0.94)`;
            carouselSlide.style.transform = `translateX(-${index * 100}%)`;

            if (index >= slideCount) {
                setTimeout(() => {
                    carouselSlide.style.transition = 'none';
                    index = 0;
                    carouselSlide.style.transform = `translateX(-${index * 100}%)`;
                }, transitionDuration); 
            }
        }

        function showPrevSlide() {
            index--;
            if (index < 0) {
                carouselSlide.style.transition = 'none';
                index = slideCount - 1;
                carouselSlide.style.transform = `translateX(-${index * 100}%)`;
                setTimeout(() => {
                    carouselSlide.style.transition = `transform ${transitionDuration}ms cubic-bezier(0.25, 0.46, 0.45, 0.94)`;
                    index--;
                    carouselSlide.style.transform = `translateX(-${index * 100}%)`;
                }, 50);
            } else {
                carouselSlide.style.transition = `transform ${transitionDuration}ms cubic-bezier(0.25, 0.46, 0.45, 0.94)`;
                carouselSlide.style.transform = `translateX(-${index * 100}%)`;
            }
        }

        let carouselInterval = setInterval(showNextSlide, intervalTime);

        // Pause on hover
        carouselSlide.addEventListener('mouseenter', () => {
            clearInterval(carouselInterval);
        });

        carouselSlide.addEventListener('mouseleave', () => {
            carouselInterval = setInterval(showNextSlide, intervalTime);
        });

        // Navigation buttons
        nextBtn.addEventListener('click', () => {
            clearInterval(carouselInterval);
            showNextSlide();
            carouselInterval = setInterval(showNextSlide, intervalTime);
        });

        prevBtn.addEventListener('click', () => {
            clearInterval(carouselInterval);
            showPrevSlide();
            carouselInterval = setInterval(showNextSlide, intervalTime);
        });
    }

    // Calendar functionality
    let currentDate = new Date();
    let selectedDate = null;
    let selectedPhotographer = null;
    const bookedSlots = <?php echo json_encode($bookedSlots); ?>;
    const photographers = <?php echo json_encode($photographers); ?>;

    const monthNames = ["January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];

    function generateCalendar() {
        const calendarGrid = document.getElementById('calendar-grid');
        const currentMonthElement = document.getElementById('current-month');
        
        // Clear existing calendar
        calendarGrid.innerHTML = '';
        
        // Set current month header
        currentMonthElement.textContent = `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
        
        // Add day headers
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        days.forEach(day => {
            const dayElement = document.createElement('div');
            dayElement.className = 'calendar-day-header';
            dayElement.textContent = day;
            calendarGrid.appendChild(dayElement);
        });
        
        // Get first day of month and total days
        const firstDay = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        const lastDay = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);
        const totalDays = lastDay.getDate();
        const startingDay = firstDay.getDay();
        
        // Add empty cells for days before first day of month
        for (let i = 0; i < startingDay; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'calendar-day other-month';
            calendarGrid.appendChild(emptyDay);
        }
        
        // Add days of current month
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        for (let day = 1; day <= totalDays; day++) {
            const dayElement = document.createElement('div');
            dayElement.className = 'calendar-day';
            dayElement.textContent = day;
            
            const currentDay = new Date(currentDate.getFullYear(), currentDate.getMonth(), day);
            
            // Check if today
            if (currentDay.getTime() === today.getTime()) {
                dayElement.classList.add('today');
            }
            
            // Check if selected
            if (selectedDate && currentDay.getTime() === selectedDate.getTime()) {
                dayElement.classList.add('selected');
            }
            
            // Check if booked
            const dateString = formatDate(currentDay);
            const isBooked = bookedSlots.some(slot => slot.date === dateString);
            
            if (isBooked) {
                dayElement.classList.add('booked');
            } else if (currentDay >= today) {
                // Only allow future dates to be selected
                dayElement.addEventListener('click', () => selectDate(currentDay));
            } else {
                dayElement.style.opacity = '0.5';
                dayElement.style.cursor = 'not-allowed';
            }
            
            calendarGrid.appendChild(dayElement);
        }
        
        // Update photographer availability for selected date
        updatePhotographerAvailability();
        updateBookingSteps();
    }
    
    function formatDate(date) {
        return date.toISOString().split('T')[0];
    }
    
    function selectDate(date) {
        selectedDate = date;
        const dateInput = document.getElementById('selected-date');
        dateInput.value = formatDate(date);
        updateSubmitButton();
        generateCalendar();
        updatePhotographerAvailability();
        updateBookingSummary();
        updateBookingSteps();
    }
    
    function updatePhotographerAvailability() {
        if (!selectedDate) return;
        
        const dateString = formatDate(selectedDate);
        const bookedForDate = bookedSlots.filter(slot => slot.date === dateString);
        
        photographers.forEach(photographer => {
            const statusElement = document.getElementById(`status-${photographer.id}`);
            const photographerCard = document.querySelector(`[data-photographer-id="${photographer.id}"]`);
            
            if (statusElement && photographerCard) {
                // Check if photographer has any bookings on this date
                const photographerBookings = bookedForDate.filter(slot => slot.photographer_id == photographer.id);
                
                if (photographerBookings.length > 0) {
                    statusElement.innerHTML = '<i class="fas fa-times-circle"></i> Busy';
                    statusElement.className = 'availability-status busy';
                    photographerCard.style.opacity = '0.6';
                    photographerCard.style.cursor = 'not-allowed';
                    photographerCard.onclick = null;
                } else {
                    statusElement.innerHTML = '<i class="fas fa-check-circle"></i> Available';
                    statusElement.className = 'availability-status available';
                    photographerCard.style.opacity = '1';
                    photographerCard.style.cursor = 'pointer';
                    photographerCard.onclick = () => selectPhotographer(photographer.id);
                }
            }
        });
    }
    
    function selectPhotographer(photographerId) {
        // Remove selected class from all photographers
        document.querySelectorAll('.photographer-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Add selected class to clicked photographer
        const selectedCard = document.querySelector(`[data-photographer-id="${photographerId}"]`);
        if (selectedCard) {
            selectedCard.classList.add('selected');
        }
        
        selectedPhotographer = photographerId;
        const photographerInput = document.getElementById('selected-photographer');
        photographerInput.value = photographerId;
        updateSubmitButton();
        updateBookingSummary();
        updateBookingSteps();
    }
    
    function updateDuration() {
        const startTime = document.getElementById('start_time').value;
        const endTime = document.getElementById('end_time').value;
        const durationDisplay = document.getElementById('duration-display');
        
        if (startTime && endTime) {
            const start = new Date(`2000-01-01T${startTime}`);
            const end = new Date(`2000-01-01T${endTime}`);
            const duration = (end - start) / (1000 * 60 * 60); // Convert to hours
            
            if (duration > 0) {
                durationDisplay.innerHTML = `<i class="fas fa-hourglass-half"></i> Duration: ${duration.toFixed(1)} hours`;
                durationDisplay.style.background = 'var(--gradient-accent)';
            } else {
                durationDisplay.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Invalid time range';
                durationDisplay.style.background = 'var(--gradient-secondary)';
            }
            
            updateSubmitButton();
            updateBookingSummary();
            updateBookingSteps();
        }
    }
    
    function updateSubmitButton() {
        const submitButton = document.getElementById('submit-booking');
        const startTime = document.getElementById('start_time').value;
        const endTime = document.getElementById('end_time').value;
        
        const isEnabled = selectedDate && selectedPhotographer && startTime && endTime && 
                         document.getElementById('customer_name').value &&
                         document.getElementById('event_type').value &&
                         document.getElementById('location').value;
        
        submitButton.disabled = !isEnabled;
        
        if (isEnabled) {
            submitButton.innerHTML = '<i class="fas fa-calendar-check"></i> CONFIRM BOOKING';
        } else {
            submitButton.innerHTML = '<i class="fas fa-lock"></i> COMPLETE ALL STEPS TO BOOK';
        }
    }
    
    function updateBookingSummary() {
        const summaryElement = document.getElementById('summary-details');
        const bookingSummary = document.getElementById('booking-summary');
        
        if (selectedDate && selectedPhotographer) {
            const photographer = photographers.find(p => p.id == selectedPhotographer);
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const eventType = document.getElementById('event_type').value;
            const location = document.getElementById('location').value;
            
            let summaryHTML = `
                <div style="display: grid; gap: 10px;">
                    <p><strong>📅 Date:</strong> ${formatDate(selectedDate)}</p>
                    <p><strong>👨‍💼 Photographer:</strong> ${photographer ? photographer.fullname : 'Not selected'}</p>
            `;
            
            if (startTime && endTime) {
                const start = new Date(`2000-01-01T${startTime}`);
                const end = new Date(`2000-01-01T${endTime}`);
                const duration = (end - start) / (1000 * 60 * 60);
                
                summaryHTML += `<p><strong>⏰ Time:</strong> ${formatTime(startTime)} - ${formatTime(endTime)} (${duration.toFixed(1)} hours)</p>`;
            }
            
            if (eventType) {
                summaryHTML += `<p><strong>🎯 Event Type:</strong> ${eventType}</p>`;
            }
            
            if (location) {
                summaryHTML += `<p><strong>📍 Location:</strong> ${location}</p>`;
            }
            
            summaryHTML += `</div>`;
            summaryElement.innerHTML = summaryHTML;
            bookingSummary.style.display = 'block';
        } else {
            bookingSummary.style.display = 'none';
        }
    }
    
    function updateBookingSteps() {
        const steps = document.querySelectorAll('.step');
        steps.forEach((step, index) => {
            step.classList.remove('active');
        });
        
        // Activate steps based on progress
        if (selectedDate) {
            steps[0].classList.add('active');
        }
        if (selectedPhotographer) {
            steps[1].classList.add('active');
        }
        if (document.getElementById('event_type').value && document.getElementById('location').value) {
            steps[2].classList.add('active');
        }
        if (document.getElementById('submit-booking').disabled === false) {
            steps[3].classList.add('active');
        }
    }
    
    function formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const formattedHour = hour % 12 || 12;
        return `${formattedHour}:${minutes} ${ampm}`;
    }
    
    // Setup photographer selection
    function setupPhotographerSelection() {
        document.querySelectorAll('.photographer-card').forEach(card => {
            card.addEventListener('click', function() {
                const photographerId = this.getAttribute('data-photographer-id');
                if (!this.style.opacity || this.style.opacity !== '0.6') {
                    selectPhotographer(photographerId);
                }
            });
        });
    }
    
    // Form validation before submission
    document.getElementById('booking-form').addEventListener('submit', function(e) {
        const submitButton = document.getElementById('submit-booking');
        submitButton.innerHTML = '<div class="spinner"></div> PROCESSING...';
        submitButton.disabled = true;
        
        // Additional validation can be added here
        setTimeout(() => {
            submitButton.innerHTML = '<i class="fas fa-calendar-check"></i> CONFIRM BOOKING';
            submitButton.disabled = false;
        }, 3000);
    });
    
    // Navigation event listeners
    document.getElementById('prev-month').addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        generateCalendar();
    });
    
    document.getElementById('next-month').addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        generateCalendar();
    });
    
    // Time input event listeners
    document.getElementById('start_time').addEventListener('change', updateDuration);
    document.getElementById('end_time').addEventListener('change', updateDuration);
    
    // Form input event listeners
    document.getElementById('customer_name').addEventListener('input', updateSubmitButton);
    document.getElementById('event_type').addEventListener('change', updateSubmitButton);
    document.getElementById('location').addEventListener('input', updateSubmitButton);
    
    // Initialize everything
    generateCalendar();
    setupPhotographerSelection();
    updateBookingSteps();
    
    // Smooth scrolling for navigation links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});
</script>
</body>
</html>