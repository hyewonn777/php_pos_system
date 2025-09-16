<?php
session_start();

// Allow login.php and any public scripts without forcing redirect
$currentFile = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['admin']) && $currentFile !== 'login.php') {
    header("Location: login.php");
    exit();
}
