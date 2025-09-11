<?php
session_start();

if (!isset($_SESSION['admin'])) {
    // Avoid redirect loop
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header("Location: login.php");
        exit();
    }
}