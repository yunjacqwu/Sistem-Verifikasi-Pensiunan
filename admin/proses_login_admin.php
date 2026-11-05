<?php
// file: admin/proses_login_admin.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

// F-01: verifikasi CSRF token
csrf_require('login.php');

// F-02: rate limiting server-side (DB), maksimal 5 percobaan / 10 menit
$lock_remaining = rate_limit_check($db, 'admin', 5, 600);
if ($lock_remaining > 0) {
    $menit = max(1, (int)ceil($lock_remaining / 60));
    header("Location: login.php?error=" . urlencode("Terlalu banyak percobaan login. Coba lagi dalam $menit menit."));
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    header("Location: login.php?error=Username dan password wajib diisi.");
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, username, pass_hash, nama FROM admin_sistem WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['pass_hash'])) {
        rate_limit_hit($db, 'admin', 5, 600);
        header("Location: login.php?error=Username atau password salah.");
        exit;
    }

    rate_limit_reset($db, 'admin'); // Reset counter login
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = $admin['id'];
    $_SESSION['admin_username']  = $admin['username'];
    $_SESSION['admin_nama']      = $admin['nama'];

    header("Location: dashboard.php");
    exit;

} catch (PDOException $e) {
    header("Location: login.php?error=Terjadi kesalahan sistem. Silakan coba lagi.");
    exit;
}