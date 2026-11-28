<?php
// file: admin/verifikasi_2fa.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/security.php';
require_once '../config/admin_2fa.php';

$tunggu = $_SESSION['admin_2fa_tunggu'] ?? null;
if (!$tunggu || (int)$tunggu['aktif'] !== 1 || time() - (int)$tunggu['waktu'] > 300) {
    unset($_SESSION['admin_2fa_tunggu']);
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('verifikasi_2fa.php');

    $konteks = 'admin2fa_' . (int)$tunggu['id'];
    if (rate_limit_check($db, $konteks, 5, 600) > 0) {
        $error = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
    } else {
        $kode = trim($_POST['kode'] ?? '');
        $bersih = preg_replace('/[^0-9]/', '', $kode);
        $ok = false;

        try {
            if (preg_match('/^[0-9]{6}$/', $kode) || (preg_match('/^[0-9 ]{6,7}$/', $kode) && strlen($bersih) === 6)) {
                // Kode 6 digit dari aplikasi autentikator
                $st = $db->prepare("SELECT totp_secret, totp_langkah_terakhir FROM admin_sistem WHERE id = :id");
                $st->execute(['id' => $tunggu['id']]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['totp_secret'])) {
                    $rahasia = admin_2fa_baca_rahasia($tunggu['username'], $r['totp_secret']);
                    $langkah = totp_cocokkan(
                        $rahasia,
                        $bersih,
                        $r['totp_langkah_terakhir'] !== null ? (int)$r['totp_langkah_terakhir'] : null
                    );
                    if ($langkah !== null) {
                        $ok = admin_2fa_catat_langkah($db, (int)$tunggu['id'], $langkah);
                    }
                }
            } else {
                // Kode cadangan sekali pakai (XXXX-XXXX)
                $ok = admin_2fa_pakai_kode_cadangan($db, (int)$tunggu['id'], $kode);
            }

            if ($ok) {
                rate_limit_reset($db, $konteks);
                $st = $db->prepare("SELECT id, username, nama FROM admin_sistem WHERE id = :id");
                $st->execute(['id' => $tunggu['id']]);
                $admin = $st->fetch(PDO::FETCH_ASSOC);
                if (!$admin) {
                    throw new PDOException('admin hilang');
                }
                admin_2fa_selesai_login($db, $admin);
                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Verifikasi 2FA gagal: ' . get_class($e));
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        }

        if ($error === '') {
            rate_limit_hit($db, $konteks, 5, 600);
            $error = 'Kode tidak valid. Periksa kembali kode di aplikasi autentikator atau kode cadangan Anda.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Dua Langkah - Admin</title>
    <link rel="icon" type="image/png" href="/absensi_pensiunan_dev/sikepen.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/vendor/daisyui-4.12.10.full.min.css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe">
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/css/fonts.css">
</head>
<body class="bg-slate-900 font-sans antialiased min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center rounded-xl bg-white p-2 mb-4">
                <img src="/absensi_pensiunan_dev/sikepen.png" alt="Logo Dana Pensiun Peruri" class="h-12 w-auto block">
            </div>
            <h1 class="text-2xl font-extrabold text-white">Verifikasi Dua Langkah</h1>
            <p class="text-slate-400 text-sm mt-1">Masuk sebagai <?= htmlspecialchars($tunggu['username']); ?></p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <?php if ($error !== ''): ?>
                <div class="mb-5 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm font-semibold">
                    <?= htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="verifikasi_2fa.php" class="space-y-5">
                <?= csrf_field(); ?>
                <div>
                    <label for="kode" class="block text-sm font-semibold text-slate-700 mb-1">Kode verifikasi</label>
                    <input type="text" id="kode" name="kode" required autofocus autocomplete="one-time-code" inputmode="text"
                           placeholder="6 digit dari aplikasi, atau kode cadangan"
                           class="w-full px-4 py-3 border border-slate-300 rounded-xl text-center text-xl font-mono tracking-widest focus:ring-2 focus:ring-blue-500 outline-none">
                    <p class="text-xs text-slate-500 mt-2">Kode berubah setiap 30 detik. Kode cadangan berbentuk XXXX-XXXX dan hanya bisa dipakai sekali.</p>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl">
                    Verifikasi
                </button>
            </form>

            <p class="text-center text-sm text-slate-500 mt-6">
                <a href="login.php" class="text-blue-600 hover:underline">Kembali ke halaman login</a>
            </p>
        </div>
    </div>
</body>
</html>
