<?php
// file: admin/proses_reset_pin.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/security.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('data_pensiunan.php');
    $npk = trim($_POST['npk'] ?? '');

    if (empty($npk)) {
        header("Location: data_pensiunan.php?error=Gagal Reset PIN! Data tidak valid.");
        exit;
    }

    $pin_baru = generatePinAcak($npk); // F-03: PIN acak, bukan NPK

    try {
        $pin_hash = password_hash($pin_baru, PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            UPDATE pensiunan 
            SET pin_hash = :pin_hash, is_first_login = 1 
            WHERE npk = :npk
        ");
        $stmt->execute([
            'pin_hash' => $pin_hash,
            'npk'      => $npk,
        ]);

        // Catat aktivitas reset PIN ke audit log
        $stmt_log = $db->prepare("
            INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
            VALUES (:npk, 'pensiunan', 'pin_hash', NULL, NULL, :oleh)
        ");
        $stmt_log->execute([
            'npk'  => $npk,
            'oleh' => $_SESSION['admin_username'],
        ]);

        $_SESSION['sukses_reset_pin'] = [
            'npk' => $npk,
        ];

        header("Location: data_pensiunan.php");
        exit;

    } catch (PDOException $e) {
        header("Location: data_pensiunan.php?error=Gagal memperbarui PIN di database.");
        exit;
    }
} else {
    header("Location: data_pensiunan.php");
    exit;
}