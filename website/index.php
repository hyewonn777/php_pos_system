<?php
session_start();
require __DIR__ . '/../db.php';

// -------------------------------
// Initialize Cart
// -------------------------------
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// -------------------------------
// Handle Add to Cart
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-triangle'></i> Please <a href='login.php'>login</a> first before ordering.</div>";
    } else {
        $item_id = intval($_POST['product_id']);
        if (isset($_SESSION['cart'][$item_id])) {
            $_SESSION['cart'][$item_id]++;
        } else {
            $_SESSION['cart'][$item_id] = 1;
        }
        $_SESSION['flash_message'] = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Item added to your cart!</div>";
    }
    header("Location: index.php#items");
    exit();
}

// -------------------------------
// Handle Booking Form
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    $customer_name = htmlspecialchars(trim($_POST['customer_name']));
    $event_date = htmlspecialchars(trim($_POST['event_date']));
    $start_time = htmlspecialchars(trim($_POST['start_time']));
    $end_time   = htmlspecialchars(trim($_POST['end_time']));
    $location   = htmlspecialchars(trim($_POST['location']));

    if ($customer_name && $event_date && $start_time && $end_time && $location) {
        if (!preg_match("/^[a-zA-Z\s]+$/", $customer_name)) {
            $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> Invalid characters in name.</div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO appointments (customer, service, date, start_time, end_time, location) VALUES (?, 'Photography', ?, ?, ?, ?)");
            if ($stmt === false) {
                $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> SQL Error: " . htmlspecialchars($conn->error) . "</div>";
            } else {
                $stmt->bind_param("sssss", $customer_name, $event_date, $start_time, $end_time, $location);
                if ($stmt->execute()) {
                    $_SESSION['booking_message'] = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Booking successful! We'll confirm the details shortly.</div>";
                } else {
                    $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-times-circle'></i> Booking failed: " . htmlspecialchars($stmt->error) . "</div>";
                }
                $stmt->close();
            }
        }
    } else {
        $_SESSION['booking_message'] = "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> All fields are required.</div>";
    }

    header("Location: index.php#booking");
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
$carousel_result = $conn->query("SELECT * FROM stock WHERE status='active' ORDER BY id DESC LIMIT 3");
if (!$carousel_result) {
    die("Carousel query failed: " . $conn->error);
}

// -------------------------------
// Fetch User Orders
// -------------------------------
$customerOrders = [];
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("
        SELECT o.id, s.name AS item_name, o.status, o.created_at
        FROM website_orders o
        JOIN stock s ON o.item_id = s.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    if ($stmt !== false) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $customerOrders[] = $row;
        }
        $stmt->close();
    }
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
<title>Marcomedia POS | Enterprise Media Solutions</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* ----------------------------------- */
    /* ENHANCED COLOR PALETTE & VARIABLES */
    /* ----------------------------------- */
    :root {
        --primary-color: #2596be;
        --secondary-color: #10627b;
        --accent-color: #ff6b6b;
        --dark-text-color: #2c3e50;
        --light-text-color: #7f8c8d;
        --background-light: #f8f9fa;
        --card-bg: #ffffff;
        --border-radius: 12px;
        --shadow: 0 10px 30px rgba(0,0,0,0.08);
        --transition: all 0.3s ease;
        --header-height: 80px;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Roboto', sans-serif;
        line-height: 1.6;
        color: var(--dark-text-color);
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        scroll-behavior: smooth;
    }

    h1, h2, h3 {
        font-weight: 700;
        color: var(--secondary-color);
        margin-bottom: 0.5em;
    }

    section {
        padding: 80px 5%;
        text-align: center;
    }
    
    /* Utility Classes */
    .container {
        max-width: 1200px;
        margin: 0 auto;
    }

    /* Alert Messages */
    .alert {
        padding: 15px 20px;
        border-radius: var(--border-radius);
        margin-bottom: 20px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: var(--shadow);
    }
    .alert-success {
        background: #e8f5e8;
        color: #2e7d32;
        border-left: 4px solid #4caf50;
    }
    .alert-error {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid #f44336;
    }
    .alert a {
        color: inherit;
        text-decoration: underline;
        font-weight: 600;
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
        background: var(--card-bg);
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: var(--shadow);
    }

    /* Logo Styling */
    .logo-link {
        text-decoration: none; 
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-img {
        width: 40px;
        height: 40px;
        object-fit: contain;
    }

    .logo h2 {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--secondary-color);
        letter-spacing: 0.5px;
        transition: var(--transition);
        padding-top: 15px;
    }

    .logo-link:hover .logo h2 {
        color: var(--primary-color);
    }

    /* Navigation */
    header nav {
        display: flex;
        align-items: center;
        gap: 25px;
    }

    header nav a {
        color: var(--dark-text-color);
        text-decoration: none;
        font-weight: 500;
        padding: 10px 0;
        transition: var(--transition);
        position: relative;
    }

    header nav a:hover {
        color: var(--primary-color);
    }

    header nav a:after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 0;
        height: 3px;
        background: var(--primary-color);
        transition: var(--transition);
    }

    header nav a:hover:after {
        width: 100%;
    }

    /* Enhanced Cart Dropdown */
    .cart-dropdown { 
        position: relative; 
        cursor: pointer; 
        padding: 8px 15px;
        border-radius: var(--border-radius);
        background: var(--background-light);
        transition: var(--transition);
    }

    .cart-dropdown:hover {
        background: #e9ecef;
    }

    .cart-items { 
        display: none; 
        position: absolute;
        right: 0;
        top: 100%;
        background: var(--card-bg);
        border-radius: var(--border-radius);
        width: 280px;
        padding: 20px;
        box-shadow: var(--shadow);
        z-index: 1000;
        margin-top: 10px;
    }
    
    .cart-dropdown:hover .cart-items { 
        display: block; 
        animation: fadeIn 0.3s ease;
    }
    
    .cart-item { 
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #eee;
    }
    
    .cart-item img { 
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 8px;
    }
    
    .view-cart { 
        display: block;
        text-align: center;
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        color: #fff;
        padding: 10px;
        border-radius: var(--border-radius);
        margin-top: 10px;
        text-decoration: none;
        font-weight: 500;
        transition: var(--transition);
    }

    .view-cart:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(16, 98, 123, 0.3);
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ----------------------------------- */
    /* ENHANCED HERO SECTION */
    /* ----------------------------------- */
    .hero {
        position: relative;
        color: #fff;
        background: linear-gradient(135deg, rgba(16, 98, 123, 0.85), rgba(37, 150, 190, 0.85)), 
                    url('images/hero.jpg') center/cover no-repeat;
        height: 600px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding: 0 20px;
    }
    
    .hero h1 {
        font-size: 4.5rem;
        margin-bottom: 20px;
        color: #fff;
        font-weight: 700;
        text-shadow: 2px 2px 8px rgba(0,0,0,0.3);
        letter-spacing: -1px;
    }

    .hero p.tagline {
        font-size: 1.5rem;
        margin-bottom: 40px;
        font-weight: 300;
        color: #f0f0f0;
        max-width: 600px;
    }

    .hero .button {
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        padding: 16px 45px;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        border-radius: var(--border-radius);
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 8px 20px rgba(37, 150, 190, 0.4);
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .hero .button:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(37, 150, 190, 0.5);
    }

    /* ----------------------------------- */
    /* ENHANCED CAROUSEL */
    /* ----------------------------------- */
    .carousel-container {
        overflow: hidden;
        width: 100%;
        max-height: 500px;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        margin: 40px auto 60px;
        position: relative;
    }

    .carousel-slide {
        display: flex;
        transition: transform 0.8s ease-in-out;
    }

    .carousel-slide img {
        flex-shrink: 0;
        width: 100%;
        height: 500px;
        object-fit: cover;
    }

    /* ----------------------------------- */
    /* ENHANCED PRODUCTS SECTION */
    /* ----------------------------------- */
    #items {
        background-color: var(--background-light);
        position: relative;
    }

    #items:before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="%2310627b" opacity="0.03"><circle cx="50" cy="50" r="2"/></svg>') repeat;
        pointer-events: none;
    }

    #items h2 {
        font-size: 2.5rem;
        margin-bottom: 50px;
        color: var(--secondary-color);
        position: relative;
        display: inline-block;
    }

    #items h2:after {
        content: '';
        position: absolute;
        bottom: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 4px;
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        border-radius: 2px;
    }

    .items-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 30px;
        max-width: 1100px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }

    .item-card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        text-align: left;
        overflow: hidden;
        transition: var(--transition);
        box-shadow: var(--shadow);
        position: relative;
    }

    .item-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    }

    .item-card:before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to bottom, transparent, rgba(16, 98, 123, 0.05));
        pointer-events: none;
    }

    .item-card img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        transition: transform 0.5s;
    }

    .item-card:hover img {
        transform: scale(1.05);
    }

    .item-content {
        padding: 25px;
    }

    .item-card h3 {
        font-size: 1.3rem;
        margin-bottom: 8px;
        color: var(--secondary-color);
        font-weight: 600;
    }

    .item-card .price {
        font-size: 1.2rem;
        color: var(--primary-color);
        font-weight: 700;
        margin-bottom: 20px;
    }

    .item-card .actions {
        display: flex;
        gap: 10px;
    }

    .item-card form button {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 15px;
        border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-weight: 500;
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .item-card form button.add-cart {
        background: var(--primary-color);
        color: #fff;
    }

    .item-card form button.design-item {
        background: var(--dark-text-color);
        color: #fff;
    }

    .item-card form button:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }

    /* ----------------------------------- */
    /* ENHANCED BOOKING SECTION */
    /* ----------------------------------- */
    #booking {
        background: var(--card-bg);
        position: relative;
    }

    #booking h2 {
        font-size: 2.5rem;
        margin-bottom: 50px;
        position: relative;
        display: inline-block;
    }

    #booking h2:after {
        content: '';
        position: absolute;
        bottom: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 4px;
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        border-radius: 2px;
    }

    #booking form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        max-width: 700px;
        margin: 0 auto;
        padding: 40px;
        border-radius: var(--border-radius);
        background: var(--background-light);
        box-shadow: var(--shadow);
        position: relative;
        z-index: 1;
    }
    
    #booking input {
        padding: 15px;
        border-radius: var(--border-radius);
        border: 2px solid #e1e5e9;
        font-size: 1rem;
        color: var(--dark-text-color);
        transition: var(--transition);
    }

    #booking input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 150, 190, 0.2);
        outline: none;
    }

    .time-inputs {
        display: flex;
        gap: 10px;
        grid-column: span 2;
    }

    .time-inputs input {
        flex: 1;
    }

    #booking button {
        grid-column: span 2;
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        color: #fff;
        padding: 16px;
        border: none;
        border-radius: var(--border-radius);
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    #booking button:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(16, 98, 123, 0.3);
    }

    /* ----------------------------------- */
    /* ENHANCED FOOTER */
    /* ----------------------------------- */
    footer {
        background: linear-gradient(135deg, var(--secondary-color), #0a4a5f);
        color: #e0f7ff;
        padding: 50px 20px 30px;
        text-align: center;
    }
    
    footer p {
        margin-bottom: 15px;
        font-size: 1.1rem;
    }

    footer .social-links {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin: 20px 0 30px;
    }

    footer .social-links a {
        color: #c0d3e0;
        text-decoration: none;
        font-size: 1.2rem;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 5px;
    }

    footer .social-links a:hover {
        color: #fff;
        transform: translateY(-2px);
    }

    .copyright {
        margin-top: 30px;
        font-size: 0.9em;
        color: #8fa6b5;
        border-top: 1px solid rgba(255,255,255,0.1);
        padding-top: 20px;
    }

    /* ----------------------------------- */
    /* RESPONSIVENESS */
    /* ----------------------------------- */
    @media (max-width: 768px) {
        .hero h1 { 
            font-size: 3rem; 
        }
        
        section { 
            padding: 60px 20px; 
        }
        
        header {
            flex-direction: column;
            height: auto;
            padding: 15px;
            gap: 15px;
        }
        
        header nav {
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        #booking form {
            grid-template-columns: 1fr;
            padding: 25px;
        }

        .time-inputs {
            grid-column: span 1;
            flex-direction: column;
        }

        #booking button {
            grid-column: span 1;
        }

        .item-card .actions {
            flex-direction: column;
        }

        .cart-items {
            width: 250px;
            right: -50%;
        }
    }

    @media (max-width: 480px) {
        .hero h1 { 
            font-size: 2.5rem; 
        }
        
        .hero p.tagline {
            font-size: 1.2rem;
        }
        
        .items-grid {
            grid-template-columns: 1fr;
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
        <a href="index.php#booking"><i class="fas fa-calendar-alt"></i> Booking</a>
        <?php if(isset($_SESSION['user_id'])): ?>
            <a href="my_orders.php"><i class="fas fa-clipboard-list"></i> My Orders</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php else: ?>
            <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
        <?php endif; ?>

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
    </nav>
</header>

<!-- Flash Messages -->
<?php if($flash_message): ?>
    <div class="container" style="margin-top: 20px;">
        <?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero" id="home">
    <div class="hero-content">
        <h1>Marcomedia</h1>
        <p class="tagline">Professional Media Services & Customizable Merchandise Solutions</p>
        <a href="#items" class="button"><i class="fas fa-arrow-down"></i> VIEW OUR PRODUCTS</a>
    </div>
</section>

<!-- Carousel -->
<div class="container">
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
    </div>
</div>

<!-- Products Section -->
<section id="items">
    <h2>Our Custom Products & Merchandise</h2>
    <div class="items-grid">
        <?php if($items_result->num_rows > 0): ?>
            <?php while($item = $items_result->fetch_assoc()): ?>
                <div class="item-card">
                    <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                    <div class="item-content">
                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                        <p class="price">Price from: ₱<?php echo number_format($item['price'], 2); ?></p>
                        
                        <div class="actions">
                            <form method="POST">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" name="add_to_cart" class="add-cart">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                            </form>

                            <form method="GET" action="design_item.php">
                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="design-item">
                                    <i class="fas fa-palette"></i> Customize
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                <i class="fas fa-box-open" style="font-size: 3rem; color: #ccc; margin-bottom: 15px;"></i>
                <p style="font-size: 1.2rem; color: var(--light-text-color);">No products are currently available.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Booking Section -->
<section id="booking">
    <h2>Schedule a Consultation</h2>
    <?php if($booking_message): ?>
        <div class="container" style="max-width: 700px; margin-bottom: 30px;">
            <?php echo $booking_message; ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="text" name="customer_name" placeholder="Your Full Name" required>
        <input type="text" name="location" placeholder="Event Location / Project Scope" required>
        
        <input type="date" name="event_date" required>
        <div class="time-inputs">
            <input type="time" name="start_time" required title="Start Time">
            <input type="time" name="end_time" required title="End Time">
        </div>

        <button type="submit" name="book">
            <i class="fas fa-calendar-check"></i> SUBMIT BOOKING REQUEST
        </button>
    </form>
</section>

<!-- Footer -->
<footer id="footer">
    <p>Marcomedia POS | <strong>Visual Solutions for the Modern Enterprise</strong></p>
    
    <div class="social-links">
        <a href="#"><i class="fab fa-facebook"></i> Facebook</a>
        <a href="#"><i class="fab fa-instagram"></i> Instagram</a>
        <a href="#"><i class="fab fa-tiktok"></i> TikTok</a>
        <a href="mailto:info@marcomedia.com"><i class="fas fa-envelope"></i> Inquire Now</a>
    </div>
    
    <p class="copyright">&copy; <?php echo date('Y'); ?> Marcomedia POS. All rights reserved. | Powered by PHP</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const carouselSlide = document.querySelector('.carousel-slide');
    const slides = document.querySelectorAll('.carousel-slide img');
    const slideCount = slides.length - 1; 
    let index = 0;

    if (slideCount > 0) {
        const intervalTime = 4000;
        const transitionDuration = 800;

        function showNextSlide() {
            index++;
            carouselSlide.style.transition = `transform ${transitionDuration}ms ease-in-out`;
            carouselSlide.style.transform = `translateX(-${index * 100}%)`;

            if (index >= slideCount) {
                setTimeout(() => {
                    carouselSlide.style.transition = 'none';
                    index = 0;
                    carouselSlide.style.transform = `translateX(-${index * 100}%)`;
                }, transitionDuration); 
            }
        }

        setInterval(showNextSlide, intervalTime);
    }
});
</script>
</body>
</html>