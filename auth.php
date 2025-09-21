<?php
session_start();

$currentFile = basename($_SERVER['PHP_SELF']);

// If not logged in, redirect to login
if (!isset($_SESSION['user_id']) && $currentFile !== 'login.php') {
    header("Location: login.php");
    exit();
}

// Optional: restrict certain roles from certain pages
// Example: only admin & photographer can access appointment.php
if ($currentFile === 'appointment.php') {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'photographer') {
        header("Location: index.php");
        exit();
    }
}
