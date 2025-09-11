<?php
require 'auth.php';
require 'db.php';

$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$sort   = isset($_POST['sort']) ? trim($_POST['sort']) : '';

$sql = "SELECT * FROM stock WHERE name LIKE ? OR category LIKE ?";
$params = ["%$search%", "%$search%"];
$types = "ss";

if ($sort) {
    $allowed = ["category","price","created_at"];
    if (in_array($sort, $allowed)) {
        $sql .= " ORDER BY $sort ASC";
    }
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$html = '';
while($row = $res->fetch_assoc()) {
    $html .= "<tr>
        <td>{$row['id']}</td>
        <td>".htmlspecialchars($row['category'])."</td>
        <td>".htmlspecialchars($row['name'])."</td>
        <td>₱".number_format($row['price'],2)."</td>
        <td>".(!empty($row['image_path']) && file_exists(__DIR__.'/'.$row['image_path'])
              ? "<img src='{$row['image_path']}' height='50'>":"-")."</td>
        <td>{$row['created_at']}</td>
        <td>
            <form method='POST' onsubmit=\"return confirm('Delete this product?');\">
                <input type='hidden' name='delete_id' value='{$row['id']}'>
                <button type='submit' style='background:#e74c3c;color:white;padding:5px 10px;border:none;border-radius:4px;'>🗑 Delete</button>
            </form>
        </td>
    </tr>";
}

$count = $res->num_rows;
$stmt->close();

echo json_encode(["html"=>$html,"count"=>$count]);
