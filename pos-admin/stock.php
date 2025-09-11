<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Stock Management</title>
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="css/style.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
  <div class="sidebar">
    <div class="sidebar">
  <h2>Admin Panel</h2>
  <ul>
    <li><a href="index.php">Dashboard</a></li>
    <li><a href="sales.php">Sales & Tracking</a></li>
    <li><a href="stock.php">Product / Stock</a></li>
    <li><a href="appointment.php">Photography & Cinematography Bookings</a></li>
    <li><a href="orders.php">Printing Orders</a></li>
  </ul>
</div>


  <div class="content">
    <h1>Product / Stock Management</h1>
    <form id="stockForm" enctype="multipart/form-data">
      <label>Category:</label>
      <input type="text" name="category" required><br>
      <label>Name:</label>
      <input type="text" name="name" required><br>
      <label>Price:</label>
      <input type="number" name="price" step="0.01" required><br>
      <label>Image:</label>
      <input type="file" name="image" accept="image/*" required><br>
      <button type="submit">Insert Product</button>
    </form>

    <hr>
    <h2>Existing Products</h2>
    <?php
      $conn = new mysqli("127.0.0.1", "root", "", "pos");
      $result = $conn->query("SELECT * FROM stock");
      echo "<table border='1' cellpadding='10'><tr><th>ID</th><th>Category</th><th>Name</th><th>Price</th><th>Image</th></tr>";
      while($row = $result->fetch_assoc()){
        echo "<tr>
                <td>".$row['id']."</td>
                <td>".$row['category']."</td>
                <td>".$row['name']."</td>
                <td>₱".$row['price']."</td>
                <td><img src='".$row['image_path']."' width='50'></td>
              </tr>";
      }
      echo "</table>";
    ?>
  </div>
  <script src="js/script.js"></script>
</body>
</html>
