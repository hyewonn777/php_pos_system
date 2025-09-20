<?php
require 'db.php';
$id = intval($_GET['id']);
$res = $conn->query("SELECT * FROM appointments WHERE id=$id LIMIT 1");
echo json_encode($res->fetch_assoc());
