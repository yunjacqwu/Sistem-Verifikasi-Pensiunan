<?php
// file: users/proses_absen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

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

// Validasi format base64 dari getUserMedia (harus diawali data:image)
if (!str_starts_with($foto_base64, 'data:image')) {
    header("Location: absen.php?error=Format foto tidak valid.");
    exit;
}

try {
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
        header("Location: dashboard.php?error=Anda sudah melakukan lapor diri pada kuartal ini.");
        exit;
    }

    // Konversi base64 ke binary
    $bagian      = explode(',', $foto_base64, 2);
    $foto_binary = base64_decode($bagian[1]);

    if ($foto_binary === false || strlen($foto_binary) < 100) {
        header("Location: absen.php?error=Data foto tidak valid atau terlalu kecil.");
        exit;
    }

    // Tambahkan watermark waktu pada foto menggunakan GD
    date_default_timezone_set('Asia/Jakarta');
    $gambar_gd = imagecreatefromstring($foto_binary);

    if ($gambar_gd !== false) {
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
        $foto_binary = ob_get_clean();
        imagedestroy($gambar_gd);
    }

    // Enkripsi foto binary menggunakan AES-256-CBC
    $foto_enkripsi = enkripsiFile($foto_binary, $npk);

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

    header("Location: dashboard.php?sukses=Lapor diri berhasil dikirim. Menunggu verifikasi admin.");
    exit;

} catch (PDOException $e) {
    header("Location: absen.php?error=Gagal menyimpan data. Silakan coba lagi.");
    exit;
} catch (RuntimeException $e) {
    header("Location: absen.php?error=Gagal mengenkripsi foto. Silakan coba lagi.");
    exit;
}