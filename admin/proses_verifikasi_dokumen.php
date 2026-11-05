<?php
// file: admin/proses_verifikasi_dokumen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: kelola_dokumen.php");
    exit;
}

csrf_require('kelola_dokumen.php');

$id_dok  = (int)($_POST['id_dok'] ?? 0);
$npk     = trim($_POST['npk']    ?? '');
$aksi    = trim($_POST['aksi']   ?? '');
$catatan = trim($_POST['catatan'] ?? '');

if ($id_dok <= 0 || empty($npk) || !in_array($aksi, ['Disetujui','Ditolak'])) {
    header("Location: kelola_dokumen.php?error=Data tidak valid.");
    exit;
}

if ($aksi === 'Ditolak' && empty($catatan)) {
    header("Location: kelola_dokumen.php?error=Catatan penolakan wajib diisi.");
    exit;
}

try {
    // Ambil data dokumen untuk notifikasi
    $stmt_cek = $db->prepare("SELECT jns_dok FROM dokumen WHERE id = :id LIMIT 1");
    $stmt_cek->execute(['id' => $id_dok]);
    $dok = $stmt_cek->fetch();

    if (!$dok) {
        header("Location: kelola_dokumen.php?error=Dokumen tidak ditemukan.");
        exit;
    }

    // Update status dokumen + catatan tolak (NULL kalau disetujui)
    $stmt = $db->prepare("
        UPDATE dokumen
        SET status = :status, catatan_tolak = :catatan, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([
        'status'  => $aksi,
        'catatan' => $aksi === 'Ditolak' ? $catatan : null,
        'id'      => $id_dok,
    ]);

    // Audit log
    $kolom_audit = 'status_' . strtolower($dok['jns_dok']); // 'status_ktp' atau 'status_kk'

    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'dokumen', :kolom, 'Menunggu Verifikasi', :baru, :oleh)
    ");
    $stmt_log->execute([
        'npk'   => $npk,
        'kolom' => $kolom_audit,
        'baru'  => $aksi,
        'oleh'  => $_SESSION['admin_username'],
    ]);

    // Notifikasi ke user
    if ($aksi === 'Disetujui') {
        $judul = 'Dokumen ' . $dok['jns_dok'] . ' Disetujui';
        $pesan = 'Dokumen ' . $dok['jns_dok'] . ' Anda telah diverifikasi dan disetujui oleh admin.';
    } else {
        $judul = 'Dokumen ' . $dok['jns_dok'] . ' Ditolak';
        $pesan = 'Dokumen ' . $dok['jns_dok'] . ' Anda ditolak. Alasan: ' . $catatan . '. Silakan unggah ulang dokumen yang sesuai.';
    }

    $stmt_notif = $db->prepare("
        INSERT INTO notifikasi (npk, judul, pesan, jenis, status_baca)
        VALUES (:npk, :judul, :pesan, 'dokumen', 'Belum Dibaca')
    ");
    $stmt_notif->execute(['npk' => $npk, 'judul' => $judul, 'pesan' => $pesan]);

    $pesan_sukses = $aksi === 'Disetujui'
        ? 'Dokumen berhasil disetujui.'
        : 'Dokumen berhasil ditolak.';

    header("Location: kelola_dokumen.php?sukses=" . urlencode($pesan_sukses));
    exit;

} catch (PDOException $e) {
    header("Location: kelola_dokumen.php?error=Gagal memproses verifikasi.");
    exit;
}