<?php
session_start();

// Allow login.php and any public scripts without forcing redirect
$currentFile = basename($_SERVER['PHP_SELF']);

// check if NOT logged in as admin, cashier, or photographer
if (
    !isset($_SESSION['admin']) &&
    !isset($_SESSION['photographer']) &&
    $currentFile !== 'login.php'
) {
    header("Location: login.php");
    exit();
}
