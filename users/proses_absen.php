<?php
// file: users/proses_absen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

const FOTO_B64_MAKS    = 3145728;
const FOTO_BIN_MAKS    = 2097152;
const FOTO_LEBAR_MAKS  = 4000;
const FOTO_TINGGI_MAKS = 4000;
const FOTO_PIKSEL_MAKS = 12000000;

// Rollback yang tidak boleh melempar ke pengguna; kegagalan hanya dicatat (request ID + kelas)
function rollback_aman(PDO $db): void {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (Throwable $e) {
        $rid = bin2hex(random_bytes(4));
        error_log(sprintf('[%s] Rollback gagal: %s', $rid, get_class($e)));
    }
}

// Proteksi halaman
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

csrf_require('absen.php');

$npk         = $_SESSION['user_npk'];
$foto_base64 = trim($_POST['foto_base64'] ?? '');

// Validasi foto tidak kosong
if (empty($foto_base64)) {
    header("Location: absen.php?error=Foto belum diambil. Silakan ambil foto terlebih dahulu.");
    exit;
}

if (strlen($foto_base64) > FOTO_B64_MAKS) {
    header("Location: absen.php?error=Ukuran foto melebihi batas 2 MB.");
    exit;
}

// Prefix data URI harus JPEG atau PNG (format yang dihasilkan getUserMedia/canvas di absen.php)
if (!preg_match('#^data:image/(jpeg|png);base64,#', $foto_base64, $pfx)) {
    header("Location: absen.php?error=Format foto tidak valid.");
    exit;
}
$tipe = $pfx[1];

$foto_binary = base64_decode(substr($foto_base64, strlen($pfx[0])), true);

if ($foto_binary === false || strlen($foto_binary) < 100) {
    header("Location: absen.php?error=Data foto tidak valid atau terlalu kecil.");
    exit;
}

if (strlen($foto_binary) > FOTO_BIN_MAKS) {
    header("Location: absen.php?error=Ukuran foto melebihi batas 2 MB.");
    exit;
}

// MIME dari Fileinfo dan magic bytes harus sama-sama sesuai dengan tipe pada prefix
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? finfo_buffer($finfo, $foto_binary) : false;
$cocok = ($tipe === 'jpeg' && str_starts_with($foto_binary, "\xFF\xD8\xFF") && $mime === 'image/jpeg')
      || ($tipe === 'png'  && str_starts_with($foto_binary, "\x89PNG\r\n\x1a\n") && $mime === 'image/png');
if (!$cocok) {
    header("Location: absen.php?error=Format foto tidak valid.");
    exit;
}

// Dimensi dibaca dari header tanpa mengalokasikan bitmap GD
$info      = getimagesizefromstring($foto_binary);
$tipe_info = $tipe === 'jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
if ($info === false
    || $info[2] !== $tipe_info
    || $info[0] > FOTO_LEBAR_MAKS
    || $info[1] > FOTO_TINGGI_MAKS
    || $info[0] * $info[1] > FOTO_PIKSEL_MAKS) {
    header("Location: absen.php?error=Dimensi foto tidak didukung.");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

try {
    $db->beginTransaction();

    // Kunci baris pensiunan agar pemeriksaan kuartal dan penyimpanan berurutan untuk pengguna yang sama
    $stmt_kunci = $db->prepare("SELECT npk FROM pensiunan WHERE npk = :npk FOR UPDATE");
    $stmt_kunci->execute(['npk' => $npk]);

    // Cek apakah kuartal ini sudah lapor diri dan statusnya Menunggu/Disetujui
    $stmt_cek = $db->prepare("
        SELECT id, status_verifikasi FROM lapor_diri
        WHERE npk = :npk
        AND QUARTER(waktu) = QUARTER(CURRENT_DATE())
        AND YEAR(waktu) = YEAR(CURRENT_DATE())
        ORDER BY waktu DESC LIMIT 1
    ");
    $stmt_cek->execute(['npk' => $npk]);
    $lapor_existing = $stmt_cek->fetch();

    if ($lapor_existing && in_array($lapor_existing['status_verifikasi'], ['Menunggu Verifikasi', 'Disetujui'])) {
        rollback_aman($db);
        header("Location: dashboard.php?error=Anda sudah melakukan lapor diri pada kuartal ini.");
        exit;
    }

    // Tambahkan watermark waktu pada foto menggunakan GD
    // F1: warning GD/libpng hanya ditekan pada decoding ini; error handler sebelumnya dipulihkan di finally
    set_error_handler(static fn(): bool => true, E_WARNING);
    try {
        $gambar_gd = imagecreatefromstring($foto_binary);
    } finally {
        restore_error_handler();
    }
    if ($gambar_gd === false) {
        rollback_aman($db);
        header("Location: absen.php?error=Format foto tidak valid.");
        exit;
    }

    $hari_array  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $hari        = $hari_array[date('w')];
    $teks_waktu  = $hari . ', ' . date('d/m/Y H:i:s') . ' WIB';
    $teks_npk    = 'NPK: ' . $npk;

    $lebar_foto  = imagesx($gambar_gd);
    $tinggi_foto = imagesy($gambar_gd);

    $warna_teks  = imagecolorallocate($gambar_gd, 255, 255, 255);
    $warna_bg    = imagecolorallocatealpha($gambar_gd, 0, 0, 0, 50);

    // Pita background di bawah foto
    imagefilledrectangle($gambar_gd, 0, $tinggi_foto - 55, $lebar_foto, $tinggi_foto, $warna_bg);
    imagestring($gambar_gd, 5, 12, $tinggi_foto - 48, $teks_waktu, $warna_teks);
    imagestring($gambar_gd, 4, 12, $tinggi_foto - 28, $teks_npk, $warna_teks);

    // Konversi kembali ke binary JPEG
    ob_start();
    imagejpeg($gambar_gd, null, 85);
    $foto_jpeg = ob_get_clean();
    imagedestroy($gambar_gd);

    if (!is_string($foto_jpeg) || $foto_jpeg === '') {
        rollback_aman($db);
        header("Location: absen.php?error=Gagal memproses foto. Silakan coba lagi.");
        exit;
    }

    // Enkripsi foto binary menggunakan AES-256-CBC
    $foto_enkripsi = enkripsiFile($foto_jpeg, $npk);

    // Simpan ciphertext ke tabel lapor_diri
    $stmt = $db->prepare("
        INSERT INTO lapor_diri (npk, foto, status_verifikasi)
        VALUES (:npk, :foto, 'Menunggu Verifikasi')
    ");
    $stmt->execute([
        'npk'  => $npk,
        'foto' => $foto_enkripsi,
    ]);

    // Catat aktivitas Lapor Diri ke audit log
    $periode_label = 'Q' . ceil(date('n') / 3) . ' ' . date('Y');
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'lapor_diri', 'lapor_diri', NULL, :periode, :npk2)
    ");
    $stmt_log->execute([
        'npk'     => $npk,
        'periode' => $periode_label,
        'npk2'    => $npk,
    ]);

    $db->commit();

    header("Location: dashboard.php?sukses=Lapor diri berhasil dikirim. Menunggu verifikasi admin.");
    exit;

} catch (PDOException $e) {
    rollback_aman($db);
    $rid = bin2hex(random_bytes(4));
    error_log(sprintf('[%s] Lapor diri gagal: %s', $rid, get_class($e)));
    header("Location: absen.php?error=Gagal menyimpan data. Silakan coba lagi.");
    exit;
} catch (RuntimeException $e) {
    rollback_aman($db);
    $rid = bin2hex(random_bytes(4));
    error_log(sprintf('[%s] Enkripsi foto gagal: %s', $rid, get_class($e)));
    header("Location: absen.php?error=Gagal mengenkripsi foto. Silakan coba lagi.");
    exit;
} catch (Throwable $e) {
    rollback_aman($db);
    $rid = bin2hex(random_bytes(4));
    error_log(sprintf('[%s] Lapor diri gagal: %s', $rid, get_class($e)));
    header("Location: absen.php?error=Gagal menyimpan data. Silakan coba lagi.");
    exit;
}
