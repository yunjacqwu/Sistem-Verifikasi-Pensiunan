<?php
// file: users/logout.php
session_start();

// Hapus semua data session user
$_SESSION = array();

// Jika menggunakan cookie session, hancurkan juga cookie-nya
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hancurkan session resmi dari server
session_destroy();

// Tendang balik ke halaman login milik user
header("Location: login.php");
exit;