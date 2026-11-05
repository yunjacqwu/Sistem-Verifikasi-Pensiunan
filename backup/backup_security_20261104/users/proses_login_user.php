<?php
// file: users/proses_login_user.php
session_start();

require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';
require_once '../config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

// F-01: verifikasi CSRF token
csrf_require('login.php');

// F-02: rate limiting server-side (DB), maksimal 5 percobaan / 10 menit
$lock_remaining = rate_limit_check($db, 'user', 5, 600);
if ($lock_remaining > 0) {
    $menit = max(1, (int)ceil($lock_remaining / 60));
    header("Location: login.php?error=" . urlencode("Terlalu banyak percobaan login. Coba lagi dalam $menit menit."));
    exit;
}

$npk = trim($_POST['npk'] ?? '');
$pin = trim($_POST['pin'] ?? '');

// Validasi input kosong
if (empty($npk) || empty($pin)) {
    header("Location: login.php?error=NPK dan PIN tidak boleh kosong.");
    exit;
}

// Validasi PIN harus 4 digit angka
if (!preg_match('/^\d{4}$/', $pin)) {
    header("Location: login.php?error=PIN harus berupa 4 digit angka.");
    exit;
}

try {
    $stmt = $db->prepare("SELECT npk, pin_hash, status, is_first_login FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    $user = $stmt->fetch();

    if (!$user) {
        rate_limit_hit($db, 'user', 5, 600);
        header("Location: login.php?error=NPK atau PIN yang Anda masukkan salah.");
        exit;
    }

    // Cek status akun
    if ($user['status'] !== 'Aktif') {
        rate_limit_hit($db, 'user', 5, 600);
        header("Location: login.php?error=Akun Anda dinonaktifkan. Silakan hubungi Admin.");
        exit;
    }

    // Verifikasi PIN menggunakan Bcrypt
    if (!verifikasiPin($pin, $user['pin_hash'])) {
        rate_limit_hit($db, 'user', 5, 600);
        header("Location: login.php?error=NPK atau PIN yang Anda masukkan salah.");
        exit;
    }

    // Login berhasil — reset rate limit & buat session
    rate_limit_reset($db, 'user');
    session_regenerate_id(true);
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_npk']       = $user['npk'];
    $_SESSION['is_first_login'] = (bool)$user['is_first_login'];

    // Redirect berdasarkan is_first_login
    if ($user['is_first_login']) {
        header("Location: dashboard.php?first_login=1");
    } else {
        header("Location: dashboard.php");
    }
    exit;

} catch (PDOException $e) {
    header("Location: login.php?error=Terjadi kesalahan sistem. Silakan coba lagi.");
    exit;
}