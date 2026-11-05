<?php
// file: admin/proses_verifikasi_absen.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: riwayat_absen.php");
    exit;
}

csrf_require('riwayat_absen.php');

$id_lapor = (int)($_POST['id_lapor'] ?? 0);
$npk      = trim($_POST['npk']      ?? '');
$aksi     = trim($_POST['aksi']     ?? '');
$catatan  = trim($_POST['catatan']  ?? '');

if ($id_lapor <= 0 || empty($npk) || !in_array($aksi, ['Disetujui','Ditolak'])) {
    header("Location: riwayat_absen.php?error=Data tidak valid.");
    exit;
}

if ($aksi === 'Ditolak' && empty($catatan)) {
    header("Location: riwayat_absen.php?error=Catatan penolakan wajib diisi.");
    exit;
}

try {
    // Update status
    $stmt = $db->prepare("
        UPDATE lapor_diri
        SET status_verifikasi = :status, catatan_tolak = :catatan
        WHERE id = :id
    ");
    $stmt->execute([
        'status'  => $aksi,
        'catatan' => $aksi === 'Ditolak' ? $catatan : null,
        'id'      => $id_lapor,
    ]);

    // Catat audit log
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'lapor_diri', 'status_verifikasi', 'Menunggu Verifikasi', :baru, :oleh)
    ");
    $stmt_log->execute([
        'npk'  => $npk,
        'baru' => $aksi,
        'oleh' => $_SESSION['admin_username'],
    ]);

    // Kirim notifikasi ke user
    if ($aksi === 'Disetujui') {
        $judul = 'Lapor Diri Disetujui';
        $pesan = 'Foto lapor diri Anda telah diverifikasi dan disetujui oleh admin. Terima kasih.';
    } else {
        $judul = 'Lapor Diri Ditolak';
        $pesan = 'Foto lapor diri Anda ditolak. Alasan: ' . $catatan . '. Silakan lakukan lapor diri ulang.';
    }

    $stmt_notif = $db->prepare("
        INSERT INTO notifikasi (npk, judul, pesan, jenis, status_baca)
        VALUES (:npk, :judul, :pesan, 'lapor_diri', 'Belum Dibaca')
    ");
    $stmt_notif->execute([
        'npk'   => $npk,
        'judul' => $judul,
        'pesan' => $pesan,
    ]);

    $pesan_sukses = $aksi === 'Disetujui'
        ? 'Lapor diri berhasil disetujui.'
        : 'Lapor diri berhasil ditolak.';

    header("Location: riwayat_absen.php?sukses=" . urlencode($pesan_sukses));
    exit;

} catch (PDOException $e) {
    header("Location: riwayat_absen.php?error=Gagal memproses verifikasi.");
    exit;
}