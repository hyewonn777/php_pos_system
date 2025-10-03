<?php
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Handle removing an item
if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$remove_id])) {
        unset($_SESSION['cart'][$remove_id]);
        if (isset($_SESSION['cart_design'][$remove_id])) unset($_SESSION['cart_design'][$remove_id]);
    }
    header("Location: my_cart.php");
    exit;
}

// Handle quantity updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    foreach ($_POST['qty'] as $id => $qty) {
        $id = intval($id);
        $qty = max(1, intval($qty));
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id] = $qty;
        }
    }
    header("Location: my_cart.php");
    exit;
}

// Fetch cart items from stock table
$cart_items = [];
$total = 0;
if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM stock WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $row['quantity'] = $_SESSION['cart'][$id];
        $row['subtotal'] = $row['quantity'] * $row['price'];
        $total += $row['subtotal'];
        $cart_items[$id] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Cart - Marcomedia POS</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary-color: #2596be;
    --secondary-color: #10627b;
    --accent-color: #ff6b6b;
    --light-bg: #f8f9fa;
    --dark-text: #2c3e50;
    --card-bg: #ffffff;
    --border-radius: 12px;
    --shadow: 0 10px 30px rgba(0,0,0,0.08);
    --transition: all 0.3s ease;
}

* {
    box-sizing: border-box;
}

body {
    font-family: 'Roboto', sans-serif;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    margin: 0;
    color: var(--dark-text);
    min-height: 100vh;
    padding: 20px;
}

.container {
    max-width: 1200px;
    margin: 40px auto;
    padding: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

h2 {
    color: var(--secondary-color);
    font-size: 2.2rem;
    margin: 0;
    position: relative;
    display: inline-block;
}

h2:after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 0;
    width: 60%;
    height: 4px;
    background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
    border-radius: 2px;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--secondary-color);
    color: #fff;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: var(--border-radius);
    font-weight: 500;
    transition: var(--transition);
}

.back-btn:hover {
    background: var(--primary-color);
    transform: translateY(-2px);
}

.empty-cart {
    text-align: center;
    padding: 60px 20px;
    background: var(--card-bg);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
}

.empty-cart i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 20px;
}

.empty-cart p {
    font-size: 1.3rem;
    color: var(--dark-text);
    margin-bottom: 25px;
}

.shop-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-color);
    color: #fff;
    text-decoration: none;
    padding: 12px 25px;
    border-radius: var(--border-radius);
    font-weight: 600;
    transition: var(--transition);
}

.shop-btn:hover {
    background: var(--secondary-color);
    transform: translateY(-2px);
}

.cart-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.cart-item {
    display: flex;
    align-items: center;
    background: var(--card-bg);
    border-radius: var(--border-radius);
    padding: 25px;
    box-shadow: var(--shadow);
    gap: 25px;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.cart-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.12);
}

.cart-item:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
}

.cart-item-image {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.cart-item-details {
    flex: 1;
}

.cart-item-details h3 {
    margin: 0 0 8px;
    color: var(--secondary-color);
    font-size: 1.3rem;
}

.cart-item-details p {
    margin: 6px 0;
    font-weight: 500;
}

.price {
    font-size: 1.1rem;
    color: var(--primary-color);
    font-weight: 600;
}

.subtotal-display {
    font-size: 1.2rem;
    color: var(--secondary-color);
    font-weight: 700;
}

.qty-input {
    width: 80px;
    padding: 10px 12px;
    border: 2px solid #e1e5e9;
    border-radius: var(--border-radius);
    font-size: 1rem;
    font-weight: 500;
    transition: var(--transition);
    text-align: center;
}

.qty-input:focus {
    border-color: var(--primary-color);
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 150, 190, 0.2);
}

.cart-item-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.remove-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: var(--accent-color);
    color: #fff;
    border: none;
    padding: 10px 15px;
    border-radius: var(--border-radius);
    cursor: pointer;
    text-decoration: none;
    font-weight: 500;
    transition: var(--transition);
}

.remove-btn:hover {
    background: #ff5252;
    transform: translateY(-2px);
}

.cart-summary {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    padding: 25px;
    margin-top: 30px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cart-total {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--secondary-color);
}

.cart-total strong {
    font-size: 1.8rem;
    color: var(--primary-color);
}

.cart-actions {
    display: flex;
    gap: 15px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 25px;
    border-radius: var(--border-radius);
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    border: none;
    cursor: pointer;
    font-size: 1rem;
}

.update-btn {
    background: #e9ecef;
    color: var(--dark-text);
}

.update-btn:hover {
    background: #dee2e6;
    transform: translateY(-2px);
}

.checkout-btn {
    background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
    color: #fff;
}

.checkout-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 15px rgba(16, 98, 123, 0.3);
}

@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .cart-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .cart-item-image {
        width: 100%;
        height: 200px;
    }
    
    .cart-item-actions {
        flex-direction: row;
        width: 100%;
    }
    
    .cart-summary {
        flex-direction: column;
        gap: 20px;
        align-items: flex-start;
    }
    
    .cart-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        text-align: center;
    }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.cart-item {
    animation: fadeIn 0.5s ease forwards;
}

.cart-item:nth-child(1) { animation-delay: 0.1s; }
.cart-item:nth-child(2) { animation-delay: 0.2s; }
.cart-item:nth-child(3) { animation-delay: 0.3s; }
.cart-item:nth-child(4) { animation-delay: 0.4s; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2><i class="fas fa-shopping-cart"></i> My Shopping Cart</h2>
        <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
    </div>

    <?php if(empty($cart_items)): ?>
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty. Start adding some products!</p>
            <a href="index.php#items" class="shop-btn"><i class="fas fa-store"></i> Shop Now</a>
        </div>
    <?php else: ?>
        <form method="post">
            <div class="cart-grid">
                <?php foreach($cart_items as $item): ?>
                    <div class="cart-item">
                        <img src="<?php 
                            if(isset($_SESSION['cart_design'][$item['id']])): 
                                echo $_SESSION['cart_design'][$item['id']];
                            elseif(!empty($item['image_path'])): 
                                echo htmlspecialchars($item['image_path']);
                            else: 
                                echo 'images/placeholder.jpg';
                            endif; 
                        ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="cart-item-image">
                        
                        <div class="cart-item-details">
                            <h3><?= htmlspecialchars($item['name']) ?></h3>
                            <p class="price">Price: ₱<?= number_format($item['price'],2) ?></p>
                            <p>
                                Quantity: 
                                <input type="number" name="qty[<?= $item['id'] ?>]" 
                                       value="<?= $item['quantity'] ?>" 
                                       min="1" class="qty-input">
                            </p>
                            <p class="subtotal-display">Subtotal: ₱<?= number_format($item['subtotal'],2) ?></p>
                        </div>
                        
                        <div class="cart-item-actions">
                            <a href="?remove=<?= $item['id'] ?>" class="remove-btn">
                                <i class="fas fa-trash"></i> Remove
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="cart-summary">
                <div class="cart-total">
                    <p>Total: <strong>₱<?= number_format($total,2) ?></strong></p>
                </div>
                <div class="cart-actions">
                    <button type="submit" name="update" class="btn update-btn">
                        <i class="fas fa-sync-alt"></i> Update Cart
                    </button>
                    <a href="checkout.php" class="btn checkout-btn">
                        <i class="fas fa-credit-card"></i> Proceed to Checkout
                    </a>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update subtotals when quantity changes
    document.querySelectorAll('.qty-input').forEach(input => {
        input.addEventListener('input', function() {
            const item = this.closest('.cart-item');
            const priceText = item.querySelector('.price').textContent;
            const price = parseFloat(priceText.replace('Price: ₱', ''));
            const qty = parseInt(this.value) || 1;
            const subtotal = price * qty;
            
            item.querySelector('.subtotal-display').textContent = 
                'Subtotal: ₱' + subtotal.toFixed(2);
            
            // Update grand total
            updateGrandTotal();
        });
    });
    
    function updateGrandTotal() {
        let total = 0;
        document.querySelectorAll('.subtotal-display').forEach(display => {
            const subtotalText = display.textContent;
            const subtotal = parseFloat(subtotalText.replace('Subtotal: ₱', ''));
            total += subtotal;
        });
        
        document.querySelector('.cart-total strong').textContent = 
            '₱' + total.toFixed(2);
    }
});
</script>
</body>
</html>