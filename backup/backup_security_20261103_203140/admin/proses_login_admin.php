<?php
// file: admin/proses_login_admin.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
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
        $attempts['count']++;
        header("Location: login.php?error=Username atau password salah.");
        exit;
    }

    unset($_SESSION[$ip_key]); // Reset counter login
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