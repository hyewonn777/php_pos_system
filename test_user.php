<?php
require 'db.php';
$res = $conn->query("SELECT * FROM user LIMIT 1");
if ($res) {
    echo "Table exists. Rows: " . $res->num_rows;
} else {
    echo "Error: " . $conn->error;
}
?>
