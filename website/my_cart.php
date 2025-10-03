<?php
session_start();
require __DIR__ . '/../db.php';

// Handle update cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    foreach ($_POST['qty'] as $product_id => $new_qty) {
        $new_qty = max(0, intval($new_qty));
        if ($new_qty == 0) {
            unset($_SESSION['cart'][$product_id]);
        } else {
            $_SESSION['cart'][$product_id] = $new_qty;
        }
    }
}

// Fetch cart items
$cart_items = [];
$total = 0;

if (!empty($_SESSION['cart'])) {
    $ids = implode(',', array_keys($_SESSION['cart']));
    $result = $conn->query("SELECT * FROM stock WHERE id IN ($ids)");
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $row['quantity'] = $_SESSION['cart'][$id];
        $row['subtotal'] = $row['quantity'] * $row['price'];
        $total += $row['subtotal'];
        $cart_items[$id] = $row;
    }
}

// Remove item
if (isset($_GET['remove'])) {
    $id = intval($_GET['remove']);
    unset($_SESSION['cart'][$id]);
    header("Location: my_cart.php");
    exit;
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
    --dark-text: #333;
    --card-bg: #fff;
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
    padding: 20px;
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
.cart-item img {
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
    width: 70px;
    padding: 8px 12px;
    border: 2px solid #e1e5e9;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 500;
    transition: var(--transition);
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
.update-btn, .checkout-btn {
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
    .cart-item img {
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
    .update-btn, .checkout-btn {
        width: 100%;
        text-align: center;
    }
}
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h2><i class="fas fa-shopping-cart"></i> My Shopping Cart</h2>
        <a href="products.php" class="back-btn"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
    </div>
    
    <?php if(empty($cart_items)): ?>
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty. Start adding some products!</p>
            <a href="products.php" class="shop-btn"><i class="fas fa-store"></i> Shop Now</a>
        </div>
    <?php else: ?>
        <form method="post" action="my_cart.php">
            <div class="cart-grid">
                <?php foreach($cart_items as $item): ?>
                    <div class="cart-item">
                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                        <div class="cart-item-details">
                            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                            <p class="price">Price: ₱<?php echo number_format($item['price'],2); ?></p>
                            <p>
                                Quantity: 
                                <input type="number" name="qty[<?php echo $item['id']; ?>]" 
                                       value="<?php echo $item['quantity']; ?>" 
                                       min="0" class="qty-input" 
                                       data-price="<?php echo $item['price']; ?>">
                            </p>
                            <p class="subtotal-display">Subtotal: ₱<span class="subtotal"><?php echo number_format($item['subtotal'],2); ?></span></p>
                        </div>
                        <div class="cart-item-actions">
                            <a href="?remove=<?php echo $item['id']; ?>" class="remove-btn">
                                <i class="fas fa-trash"></i> Remove
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="cart-summary">
                <div class="cart-total">
                    <p>Total: <strong>₱<span id="grand-total"><?php echo number_format($total,2); ?></span></strong></p>
                </div>
                <div class="cart-actions">
                    <button type="submit" name="update_cart" class="update-btn">
                        <i class="fas fa-sync-alt"></i> Update Cart
                    </button>
                    <a href="checkout.php" class="checkout-btn">
                        <i class="fas fa-credit-card"></i> Proceed to Checkout
                    </a>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.qty-input').forEach(input => {
    input.addEventListener('input', function() {
        let price = parseFloat(this.dataset.price);
        let qty = parseInt(this.value) || 0;
        let subtotalCell = this.closest('.cart-item-details').querySelector('.subtotal');
        let newSubtotal = price * qty;
        subtotalCell.textContent = newSubtotal.toFixed(2);

        // update grand total
        let total = 0;
        document.querySelectorAll('.subtotal').forEach(cell => {
            total += parseFloat(cell.textContent);
        });
        document.getElementById('grand-total').textContent = total.toFixed(2);
    });
});
</script>

</body>
</html>