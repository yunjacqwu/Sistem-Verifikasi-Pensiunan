<?php
// file: admin/logout.php
require_once '../config/bootstrap.php';

// Catat logout admin ke audit. Logout harus selalu berhasil, jadi kegagalan audit hanya dicatat ke log server.
if (!empty($_SESSION['admin_username'])) {
    try {
        require_once '../config/koneksi.php';
require_once '../config/audit.php';
                audit_tulis($db, null, 'admin_sistem', 'logout', null, 'Logout', $_SESSION['admin_username']);
    } catch (Throwable $e) {
        error_log('Audit logout admin gagal: ' . get_class($e));
    }
}

session_unset();
session_destroy();
header("Location: login.php");
exit;