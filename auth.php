<?php
// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('admin_session');
    session_start();
}

$currentFile = basename($_SERVER['PHP_SELF']);

// If not logged in, redirect to login
if (!isset($_SESSION['user_id']) && $currentFile !== 'login.php') {
    header("Location: login.php");
    exit();
}

// If logged in and trying to access admin pages, check for admin role
if (isset($_SESSION['user_id']) && $currentFile !== 'login.php') {
    // Define admin-only pages
    $adminPages = [
        'index.php', 
        'user_management.php',
        // Add other admin-only pages here as needed
    ];
    
    // Check if current page is admin-only and user is not admin
    if (in_array($currentFile, $adminPages) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')) {
        header("Location: unauthorized.php");
        exit();
    }
}
?>