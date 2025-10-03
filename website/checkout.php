<?php
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$username  = $_SESSION['username'] ?? 'Guest';
$role      = $_SESSION['role'] ?? 'customer';
$cart      = $_SESSION['cart'] ?? [];

// Initialize messages
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($cart)) {
    $conn->begin_transaction();
    try {
        // Calculate total quantity
        $total_qty = array_sum($cart);

        // Insert into orders table
        $stmt = $conn->prepare("
            INSERT INTO orders (user_id, role, customer_name, status, quantity, created_at) 
            VALUES (?, ?, ?, 'Pending', ?, NOW())
        ");
        if (!$stmt) {
            throw new Exception("Prepare failed (orders): " . $conn->error);
        }
        // Correct binding: int, string, string, int
        $stmt->bind_param("issi", $user_id, $role, $username, $total_qty);
        $stmt->execute();
        $order_id = $conn->insert_id;
        $stmt->close();

        // Insert order items
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare failed (order_items): " . $conn->error);
        }

        foreach ($cart as $product_id => $qty) {
            if ($qty > 0) {
                $stmt->bind_param("iii", $order_id, $product_id, $qty);
                $stmt->execute();

                // Deduct stock
                $update = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE id = ?");
                if (!$update) {
                    throw new Exception("Prepare failed (stock update): " . $conn->error);
                }
                $update->bind_param("ii", $qty, $product_id);
                $update->execute();
                $update->close();
            }
        }
        $stmt->close();

        $conn->commit();
        $success_message = "Order #$order_id created successfully!";

        // clear cart + designs
        unset($_SESSION['cart']);
        unset($_SESSION['cart_design']);

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error creating order: " . $e->getMessage();
    }
}

// Fetch cart items from stock table
$cart_items = [];
$total = 0;

if (!empty($cart)) {
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sql = "SELECT * FROM stock WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Prepare failed (stock): " . $conn->error);
    }

    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $row['quantity'] = $cart[$id];
        $row['subtotal'] = $row['quantity'] * $row['price'];
        $total += $row['subtotal'];
        $cart_items[$id] = $row;
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout - Marcomedia POS</title>
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
    max-width: 1000px;
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

.checkout-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.checkout-item {
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

.checkout-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.12);
}

.checkout-item:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
}

.checkout-item img {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.checkout-item-details {
    flex: 1;
}

.checkout-item-details h3 {
    margin: 0 0 8px;
    color: var(--secondary-color);
    font-size: 1.3rem;
}

.checkout-item-details p {
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

.checkout-summary {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    padding: 25px;
    margin-top: 30px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.total-display {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--secondary-color);
}

.total-display strong {
    font-size: 1.8rem;
    color: var(--primary-color);
}

.checkout-actions {
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

.confirm-btn {
    background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
    color: #fff;
}

.confirm-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 15px rgba(16, 98, 123, 0.3);
}

.print-btn {
    background: #e9ecef;
    color: var(--dark-text);
}

.print-btn:hover {
    background: #dee2e6;
    transform: translateY(-2px);
}

.shop-btn {
    background: var(--primary-color);
    color: #fff;
}

.shop-btn:hover {
    background: var(--secondary-color);
    transform: translateY(-2px);
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

@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .checkout-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .checkout-item img {
        width: 100%;
        height: 200px;
    }
    
    .checkout-summary {
        flex-direction: column;
        gap: 20px;
        align-items: flex-start;
    }
    
    .checkout-actions {
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

.checkout-item {
    animation: fadeIn 0.5s ease forwards;
}

.checkout-item:nth-child(1) { animation-delay: 0.1s; }
.checkout-item:nth-child(2) { animation-delay: 0.2s; }
.checkout-item:nth-child(3) { animation-delay: 0.3s; }
.checkout-item:nth-child(4) { animation-delay: 0.4s; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2><i class="fas fa-shopping-bag"></i> Checkout</h2>
        <a href="my_cart.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Cart</a>
    </div>

    <?php if($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>
    
    <?php if($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <?php if(empty($cart_items)): ?>
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty. Nothing to checkout!</p>
            <a href="index.php#items" class="btn shop-btn"><i class="fas fa-store"></i> Continue Shopping</a>
        </div>
    <?php else: ?>
        <div class="checkout-grid" id="printableArea">
            <?php foreach($cart_items as $item): ?>
                <div class="checkout-item">
                    <img src="<?= htmlspecialchars($item['image_path']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                    <div class="checkout-item-details">
                        <h3><?= htmlspecialchars($item['name']) ?></h3>
                        <p class="price">Price: ₱<?= number_format($item['price'],2) ?></p>
                        <p>Quantity: <?= $item['quantity'] ?></p>
                        <p class="subtotal-display">Subtotal: ₱<?= number_format($item['subtotal'],2) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="checkout-summary">
            <div class="total-display">
                <p>Total: <strong>₱<?= number_format($total,2) ?></strong></p>
            </div>
            <div class="checkout-actions">
                <form method="POST" style="display:inline;">
                    <button type="submit" name="confirm_order" class="btn confirm-btn">
                        <i class="fas fa-check-circle"></i> Confirm Order
                    </button>
                </form>
                <button class="btn print-btn" onclick="printReceipt()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <a href="index.php#items" class="btn shop-btn">
                    <i class="fas fa-store"></i> Continue Shopping
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function printReceipt() {
    const printContents = document.getElementById('printableArea').innerHTML;
    const total = document.querySelector('.total-display strong')?.innerText || '₱0.00';

    const receiptHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt - Marcomedia</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    color: #333;
                }
                .receipt-header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 2px dashed #ccc;
                }
                .receipt-header img {
                    max-height: 60px;
                    margin-bottom: 10px;
                }
                .receipt-header h2 {
                    margin: 0;
                    font-size: 22px;
                    color: #10627b;
                }
                .receipt-header p {
                    margin: 0;
                    font-size: 13px;
                    color: #666;
                }
                .receipt-info {
                    margin-bottom: 15px;
                    font-size: 14px;
                    color: #444;
                }
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                    font-size: 14px;
                }
                .items-table th {
                    background: #f8f9fa;
                    text-align: left;
                    padding: 8px;
                    border-bottom: 1px solid #ddd;
                }
                .items-table td {
                    padding: 8px;
                    border-bottom: 1px solid #eee;
                }
                .total-section {
                    text-align: right;
                    margin-top: 20px;
                    font-size: 16px;
                    font-weight: bold;
                }
                .receipt-footer {
                    margin-top: 25px;
                    text-align: center;
                    font-size: 13px;
                    color: #555;
                    border-top: 2px dashed #ccc;
                    padding-top: 15px;
                }
                @media print {
                    body { margin: 0; padding: 15px; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <img src="images/rsz_logo.png" alt="Marcomedia Logo">
                <h2>Marcomedia</h2>
                <p>Tangub City, Philippines</p>
            </div>

            <div class="receipt-info">
                <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
                <p><strong>Time:</strong> ${new Date().toLocaleTimeString()}</p>
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($username); ?></p>
            </div>

            <h3 style="margin-bottom:10px; font-size:16px; color:#2596be;">Items Purchased</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($cart_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>₱<?= number_format($item['price'],2) ?></td>
                        <td style="text-align:right;">₱<?= number_format($item['subtotal'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="total-section">
                <p>Total: ${total}</p>
            </div>

            <div class="receipt-footer">
                <p>Thank you for shopping with Marcomedia!<br>
                For inquiries, contact us at (XXX) XXX-XXXX</p>
                <p style="margin-top:10px; font-size:12px; color:#aaa;">This is a system-generated receipt.</p>
            </div>
            
            <div class="no-print" style="text-align:center; margin-top:20px;">
                <button onclick="window.close()" style="padding:8px 15px; background:#10627b; color:white; border:none; border-radius:4px; cursor:pointer;">
                    Close Window
                </button>
            </div>
        </body>
        </html>
    `;

    const printWindow = window.open('', '_blank', 'width=650,height=700');
    printWindow.document.write(receiptHTML);
    printWindow.document.close();
    
    // Wait for content to load before printing
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
    };
}
</script>
</body>
</html>