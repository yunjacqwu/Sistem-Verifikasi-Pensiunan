<?php
// file: admin/setup_2fa.php
// Pendaftaran 2FA (TOTP) untuk admin yang belum punya. Login selesai setelah kode cadangan disimpan.
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/security.php';
require_once '../config/totp.php';
require_once '../config/admin_2fa.php';

$tunggu = $_SESSION['admin_2fa_tunggu'] ?? null;
if (!$tunggu || (int)$tunggu['aktif'] !== 0 || time() - (int)$tunggu['waktu'] > 300) {
    unset($_SESSION['admin_2fa_tunggu'], $_SESSION['admin_2fa_rahasia'], $_SESSION['admin_2fa_kode_baru']);
    header('Location: login.php');
    exit;
}

$error = '';
$konteks = 'admin2fa_setup_' . (int)$tunggu['id'];

// Tahap 2: admin sudah menyimpan kode cadangan, login diselesaikan.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selesai'])) {
    csrf_require('setup_2fa.php');
    if (empty($_SESSION['admin_2fa_kode_baru'])) {
        header('Location: setup_2fa.php');
        exit;
    }
    $st = $db->prepare("SELECT id, username, nama FROM admin_sistem WHERE id = :id");
    $st->execute(['id' => $tunggu['id']]);
    $admin = $st->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        header('Location: login.php');
        exit;
    }
    admin_2fa_selesai_login($db, $admin);
    header('Location: dashboard.php');
    exit;
}

// Tahap 1: memasukkan kode pertama untuk memastikan aplikasi sudah benar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kode'])) {
    csrf_require('setup_2fa.php');
    if (rate_limit_check($db, $konteks, 5, 600) > 0) {
        $error = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
    } elseif (empty($_SESSION['admin_2fa_rahasia'])) {
        $error = 'Sesi pendaftaran habis. Muat ulang halaman.';
    } else {
        $bersih = preg_replace('/[^0-9]/', '', $_POST['kode']);
        $langkah = totp_cocokkan($_SESSION['admin_2fa_rahasia'], $bersih, null);
        if ($langkah !== null) {
            try {
                admin_2fa_simpan_rahasia($db, (int)$tunggu['id'], $tunggu['username'], $_SESSION['admin_2fa_rahasia'], $langkah);
                $_SESSION['admin_2fa_kode_baru'] = admin_2fa_buat_kode_cadangan($db, (int)$tunggu['id']);
                unset($_SESSION['admin_2fa_rahasia']);
                rate_limit_reset($db, $konteks);
                header('Location: setup_2fa.php');
                exit;
            } catch (PDOException $e) {
                error_log('Setup 2FA gagal: ' . get_class($e));
                $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            }
        } else {
            rate_limit_hit($db, $konteks, 5, 600);
            $error = 'Kode tidak valid. Periksa angka di aplikasi autentikator Anda.';
        }
    }
}

// Rahasia baru untuk tahap 1 (disimpan di sesi server, belum di database).
if (empty($_SESSION['admin_2fa_rahasia']) && empty($_SESSION['admin_2fa_kode_baru'])) {
    $_SESSION['admin_2fa_rahasia'] = totp_buat_rahasia();
}

$kode_baru = $_SESSION['admin_2fa_kode_baru'] ?? null;
$rahasia_tampil = $_SESSION['admin_2fa_rahasia'] ?? '';
$rahasia_kelompok = trim(chunk_split($rahasia_tampil, 4, ' '));
$uri_qr = $rahasia_tampil !== '' ? totp_uri($rahasia_tampil, $tunggu['username'], 'SIKEPEN') : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Dua Langkah - Admin</title>
    <link rel="icon" type="image/png" href="/absensi_pensiunan_dev/sikepen.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/vendor/daisyui-4.12.10.full.min.css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe">
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/css/fonts.css">
</head>
<body class="bg-slate-900 font-sans antialiased min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center rounded-xl bg-white p-2 mb-4">
                <img src="/absensi_pensiunan_dev/sikepen.png" alt="Logo Dana Pensiun Peruri" class="h-12 w-auto block">
            </div>
            <h1 class="text-2xl font-extrabold text-white">Pendaftaran Dua Langkah</h1>
            <p class="text-slate-400 text-sm mt-1">Akun <?= htmlspecialchars($tunggu['username']); ?> wajib memakai dua langkah.</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8 space-y-6">
            <?php if ($error !== ''): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm font-semibold">
                    <?= htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($kode_baru): ?>
                <div>
                    <h2 class="font-bold text-slate-800 text-lg mb-2">Simpan kode cadangan</h2>
                    <p class="text-sm text-slate-600 mb-4">Kode ini hanya tampil sekali. Setiap kode hanya bisa dipakai sekali, untuk masuk jika HP hilang. Simpan di tempat aman.</p>
                    <div class="grid grid-cols-2 gap-2 font-mono text-center text-slate-900 bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <?php foreach ($kode_baru as $k): ?>
                            <div class="py-1"><?= htmlspecialchars($k); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form method="POST" action="setup_2fa.php">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="selesai" value="1">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl">
                        Saya sudah menyimpan kode, lanjutkan
                    </button>
                </form>
            <?php else: ?>
                <div>
                    <h2 class="font-bold text-slate-800 text-lg mb-2">1. Tambahkan ke aplikasi autentikator</h2>
                    <p class="text-sm text-slate-600 mb-3">Buka Google Authenticator, Microsoft Authenticator, atau Authy, pilih tambah akun dengan kunci setup, lalu masukkan kunci berikut:</p>
                    <div class="font-mono text-lg font-bold tracking-widest text-center bg-slate-50 border border-slate-200 rounded-xl p-4 break-all">
                        <?= htmlspecialchars($rahasia_kelompok); ?>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">Jenis: berbasis waktu (TOTP). Nama akun: <?= htmlspecialchars($tunggu['username']); ?>.</p>
                    <div class="mt-4">
                        <p class="text-sm text-slate-600 mb-2">Atau pindai QR code ini dengan aplikasi autentikator:</p>
                        <div id="qr-kode" class="flex justify-center bg-white p-3 border border-slate-200 rounded-xl"></div>
                    </div>
                </div>

                <form method="POST" action="setup_2fa.php" class="space-y-4">
                    <?= csrf_field(); ?>
                    <div>
                        <h2 class="font-bold text-slate-800 text-lg mb-2">2. Masukkan kode 6 digit</h2>
                        <input type="text" name="kode" required autocomplete="one-time-code" inputmode="numeric" maxlength="8"
                               class="w-full px-4 py-3 border border-slate-300 rounded-xl text-center text-xl font-mono tracking-widest focus:ring-2 focus:ring-blue-500 outline-none"
                               placeholder="123456">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl">
                        Aktifkan dua langkah
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<script src="/absensi_pensiunan_dev/static/vendor/qrcode-generator-1.4.4.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k"></script>
<?php if (!empty($uri_qr) && !$kode_baru): ?>
<script>
  (function () {
    var qr = qrcode(0, 'M');
    qr.addData(<?= json_encode($uri_qr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    qr.make();
    document.getElementById('qr-kode').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
  })();
</script>
<?php endif; ?>
</body>
</html>
