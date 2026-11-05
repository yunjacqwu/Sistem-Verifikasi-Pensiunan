<?php
// file: users/proses_login_user.php
session_start();

// Rate limiting — maksimal 5 percobaan gagal dalam 10 menit
$ip_key = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR']);
$max_attempts = 5;
$lockout_time = 600; // 10 menit dalam detik

if (!isset($_SESSION[$ip_key])) {
    $_SESSION[$ip_key] = ['count' => 0, 'first_attempt' => time()];
}

$attempts = &$_SESSION[$ip_key];

// Reset counter kalau sudah lewat 10 menit
if (time() - $attempts['first_attempt'] > $lockout_time) {
    $attempts = ['count' => 0, 'first_attempt' => time()];
}

// Cek apakah sudah terkunci
if ($attempts['count'] >= $max_attempts) {
    $sisa_waktu = $lockout_time - (time() - $attempts['first_attempt']);
    $menit = ceil($sisa_waktu / 60);
    header("Location: login.php?error=Terlalu banyak percobaan login. Coba lagi dalam $menit menit.");
    exit;
}

require_once '../config/koneksi.php';
require_once '../config/crypto.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
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
        // Tambahkan percobaan login yang gagal
        $attempts['count']++;
        header("Location: login.php?error=NPK atau PIN yang Anda masukkan salah.");
        exit;
    }

    // Cek status akun
    if ($user['status'] !== 'Aktif') {
        $attempts['count']++;
        header("Location: login.php?error=Akun Anda dinonaktifkan. Silakan hubungi Admin.");
        exit;
    }

    // Verifikasi PIN menggunakan Bcrypt
    if (!verifikasiPin($pin, $user['pin_hash'])) {
        $attempts['count']++;
        header("Location: login.php?error=NPK atau PIN yang Anda masukkan salah.");
        exit;
    }

    // Login berhasil — buat session
    unset($_SESSION[$ip_key]);
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