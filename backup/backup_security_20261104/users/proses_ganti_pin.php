<?php
// file: users/proses_ganti_pin.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

header('Content-Type: application/json');

// Validasi session
if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['sukses' => false, 'pesan' => 'Sesi tidak valid.']);
    exit;
}

if (!isset($_SESSION['is_first_login']) || !$_SESSION['is_first_login']) {
    echo json_encode(['sukses' => false, 'pesan' => 'Tidak ada permintaan ganti PIN.']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);

// F-01: verifikasi CSRF token (dikirim via header untuk request JSON)
$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (!csrf_verify(is_string($csrf_header) ? $csrf_header : '')) {
    echo json_encode(['sukses' => false, 'pesan' => 'Sesi tidak valid atau kedaluwarsa. Muat ulang halaman.']);
    exit;
}

$pin_baru = trim($input['pin_baru'] ?? '');
$npk      = $_SESSION['user_npk'];

// Validasi format PIN
if (!preg_match('/^\d{4}$/', $pin_baru)) {
    echo json_encode(['sukses' => false, 'pesan' => 'PIN harus berupa 4 digit angka.']);
    exit;
}

// Pastikan PIN baru tidak sama dengan NPK (PIN sementara)
if ($pin_baru === $npk) {
    echo json_encode(['sukses' => false, 'pesan' => 'PIN baru tidak boleh sama dengan PIN sementara (NPK Anda).']);
    exit;
}

try {
    $pin_hash = password_hash($pin_baru, PASSWORD_BCRYPT);

    $stmt = $db->prepare("
        UPDATE pensiunan 
        SET pin_hash = :pin_hash, is_first_login = 0 
        WHERE npk = :npk
    ");
    $stmt->execute(['pin_hash' => $pin_hash, 'npk' => $npk]);

    // Update session
    $_SESSION['is_first_login'] = false;

    // Catat audit log
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', 'pin_hash', NULL, NULL, :oleh)
    ");
    $stmt_log->execute(['npk' => $npk, 'oleh' => $npk]);

    echo json_encode(['sukses' => true]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['sukses' => false, 'pesan' => 'Terjadi kesalahan sistem.']);
    exit;
}