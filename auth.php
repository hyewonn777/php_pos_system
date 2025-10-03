<?php
session_start();

$currentFile = basename($_SERVER['PHP_SELF']);

// If not logged in, redirect to login
if (!isset($_SESSION['user_id']) && $currentFile !== 'login.php') {
    header("Location: login.php");
    exit();
}
