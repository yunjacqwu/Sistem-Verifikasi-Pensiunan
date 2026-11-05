<?php
// file: admin/proses_tambah_pensiunan.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';
require_once '../config/security.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: data_pensiunan.php");
    exit;
}

csrf_require('data_pensiunan.php');

$npk      = trim($_POST['npk'] ?? '');
$nama     = trim($_POST['nama_lengkap'] ?? '');
$telp     = trim($_POST['no_telp'] ?? '');
$telp2    = trim($_POST['no_telp2'] ?? '');
$nik      = trim($_POST['nik'] ?? '');
$tmpt_lhr = trim($_POST['tempat_lahir'] ?? '');
$tgl_lhr  = $_POST['tanggal_lahir'] ?? '';
$jk       = $_POST['jenis_kelamin'] ?? '';
$alamat   = trim($_POST['alamat'] ?? '');
$tgl_msk  = $_POST['tanggal_masuk'] ?? '';
$tgl_psn  = $_POST['tanggal_pensiun'] ?? '';
$jns_mnft = trim($_POST['jenis_manfaat_pensiun'] ?? '');

if (empty($npk) || empty($nama) || empty($telp)) {
    header("Location: data_pensiunan.php?error=NPK, Nama Lengkap, dan No. Telepon wajib diisi.");
    exit;
}

// Validasi format NPK tidak mengandung karakter berbahaya
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $npk)) {
    header("Location: data_pensiunan.php?error=Format NPK tidak valid.");
    exit;
}

try {
    // Cek duplikasi NPK
    $cek = $db->prepare("SELECT npk FROM pensiunan WHERE npk = :npk LIMIT 1");
    $cek->execute(['npk' => $npk]);
    if ($cek->fetch()) {
        header("Location: data_pensiunan.php?error=NPK " . htmlspecialchars($npk) . " sudah terdaftar.");
        exit;
    }

    // F-03: Generate PIN awal acak 6 digit (bukan NPK)
    $pin_awal = generatePinAcak($npk);
    $pin_hash = hashPin($pin_awal);

    // Enkripsi kolom sensitif
    $nama_enc     = enkripsi($nama, $npk);
    $nik_enc      = !empty($nik)      ? enkripsi($nik, $npk)      : null;
    $tmpt_lhr_enc = !empty($tmpt_lhr) ? enkripsi($tmpt_lhr, $npk) : null;
    $telp_enc     = enkripsi($telp, $npk);
    $telp2_enc    = !empty($telp2)    ? enkripsi($telp2, $npk)    : null;
    $alamat_enc   = !empty($alamat)   ? enkripsi($alamat, $npk)   : null;

    $stmt = $db->prepare("
        INSERT INTO pensiunan
            (npk, pin_hash, nik, nama, alamat, telp, telp2, tmpt_lhr,
            tgl_lhr, jk, tgl_msk, tgl_psn, jns_mnft, status, is_first_login)
        VALUES
            (:npk, :pin_hash, :nik, :nama, :alamat, :telp, :telp2, :tmpt_lhr,
            :tgl_lhr, :jk, :tgl_msk, :tgl_psn, :jns_mnft, 'Aktif', 1)
    ");

    $stmt->execute([
        'npk'      => $npk,
        'pin_hash' => $pin_hash,
        'nik'      => $nik_enc,
        'nama'     => $nama_enc,
        'alamat'   => $alamat_enc,
        'telp'     => $telp_enc,
        'telp2'    => $telp2_enc,
        'tmpt_lhr' => $tmpt_lhr_enc,
        'tgl_lhr'  => !empty($tgl_lhr) ? $tgl_lhr : null,
        'jk'       => !empty($jk)      ? $jk      : null,
        'tgl_msk'  => !empty($tgl_msk) ? $tgl_msk : null,
        'tgl_psn'  => !empty($tgl_psn) ? $tgl_psn : null,
        'jns_mnft' => !empty($jns_mnft) ? $jns_mnft : null,
    ]);

    // Catat audit log
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', 'INSERT', NULL, 'Data pensiunan baru ditambahkan', :oleh)
    ");
    $stmt_log->execute([
        'npk'  => $npk,
        'oleh' => $_SESSION['admin_username'],
    ]);

    // Tampilkan PIN satu kali pada halaman bergaya admin; tidak disimpan di session atau log.
    header('Content-Type: text/html; charset=UTF-8');
    $nama_html = htmlspecialchars($nama, ENT_QUOTES, 'UTF-8');
    $npk_html = htmlspecialchars($npk, ENT_QUOTES, 'UTF-8');
    $pin_html = htmlspecialchars($pin_awal, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PIN Sementara - Admin</title><script src="https://cdn.tailwindcss.com"></script></head>';
    echo '<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4"><main class="w-full max-w-lg bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">';
    echo '<div class="bg-emerald-600 px-6 py-5 text-white"><p class="text-sm font-semibold text-emerald-100">Dana Pensiun Perum Peruri</p><h1 class="text-2xl font-bold mt-1">Akun Berhasil Dibuat</h1></div>';
    echo '<div class="p-6 space-y-5"><div><p class="text-sm text-slate-500">Nama pensiunan</p><p class="font-semibold text-slate-800">' . $nama_html . '</p><p class="text-sm text-slate-500 mt-3">NPK</p><p class="font-mono font-semibold text-slate-800">' . $npk_html . '</p></div>';
    echo '<div class="rounded-xl border border-amber-300 bg-amber-50 p-5"><p class="text-sm font-semibold text-amber-800">PIN sementara — tampil satu kali</p><p class="mt-2 text-3xl font-mono font-bold tracking-[0.3em] text-slate-900">' . $pin_html . '</p><p class="mt-3 text-xs text-amber-800">Sampaikan melalui kanal aman. Pengguna wajib mengganti PIN saat login pertama.</p></div>';
    echo '<a href="data_pensiunan.php" class="inline-flex w-full justify-center rounded-xl bg-blue-600 px-5 py-3 font-semibold text-white hover:bg-blue-700">Kembali ke Data Pensiunan</a></div></main></body></html>';
    exit;

} catch (PDOException $e) {
    header("Location: data_pensiunan.php?error=Gagal menyimpan data. Silakan coba lagi.");
    exit;
}