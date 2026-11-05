<?php
// file: admin/proses_nonaktifkan_pensiunan.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: data_pensiunan.php");
    exit;
}

csrf_require('data_pensiunan.php');

$npk = trim($_POST['npk'] ?? '');

if (empty($npk)) {
    header("Location: data_pensiunan.php?error=NPK tidak valid.");
    exit;
}

try {
    // Ambil status lama
    $stmt_cek = $db->prepare("SELECT status, nama FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt_cek->execute(['npk' => $npk]);
    $data = $stmt_cek->fetch();

    if (!$data) {
        header("Location: data_pensiunan.php?error=Data pensiunan tidak ditemukan.");
        exit;
    }

    $status_baru = $data['status'] === 'Aktif' ? 'Tidak Aktif' : 'Aktif';

    // Update status
    $stmt = $db->prepare("UPDATE pensiunan SET status = :status WHERE npk = :npk");
    $stmt->execute(['status' => $status_baru, 'npk' => $npk]);

    // Catat audit log
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', 'status', :lama, :baru, :oleh)
    ");
    $stmt_log->execute([
        'npk'  => $npk,
        'lama' => $data['status'],
        'baru' => $status_baru,
        'oleh' => $_SESSION['admin_username'],
    ]);

    $pesan = $status_baru === 'Tidak Aktif'
        ? "Pensiunan NPK $npk berhasil dinonaktifkan."
        : "Pensiunan NPK $npk berhasil diaktifkan kembali.";

    header("Location: data_pensiunan.php?sukses=" . urlencode($pesan));
    exit;

} catch (PDOException $e) {
    header("Location: data_pensiunan.php?error=Gagal mengubah status. Silakan coba lagi.");
    exit;
}