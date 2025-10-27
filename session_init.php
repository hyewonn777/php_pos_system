<?php
// session_init.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('admin_session');
    session_start();
}
?>