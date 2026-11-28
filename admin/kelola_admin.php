<?php
// file: admin/kelola_admin.php
// Daftar admin dan reset 2FA oleh admin lain. Reset butuh password dan kode 2FA milik admin yang mereset.
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/security.php';
require_once '../config/audit.php';
require_once '../config/totp.php';
require_once '../config/admin_2fa.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$pesan_sukses = '';
$pesan_error  = '';
$me_id = (int)$_SESSION['admin_id'];
$me_username = $_SESSION['admin_username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('kelola_admin.php');

    $target_id = (int)($_POST['target_id'] ?? 0);
    $alasan    = trim($_POST['alasan'] ?? '');
    $password  = (string)($_POST['password'] ?? '');
    $kode      = trim($_POST['kode'] ?? '');
    $konteks   = 'reset2fa_' . $me_id;

    if (rate_limit_check($db, $konteks, 5, 600) > 0) {
        $pesan_error = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
    } elseif ($target_id === $me_id) {
        $pesan_error = 'Anda tidak bisa mereset 2FA akun sendiri. Gunakan kode cadangan.';
    } elseif (mb_strlen($alasan) < 5) {
        $pesan_error = 'Isi alasan reset (minimal 5 karakter).';
    } else {
        try {
            $st = $db->prepare("SELECT id, username, pass_hash, totp_secret, totp_langkah_terakhir, totp_aktif FROM admin_sistem WHERE id = :id");
            $st->execute(['id' => $me_id]);
            $saya = $st->fetch(PDO::FETCH_ASSOC);

            $st = $db->prepare("SELECT id, username FROM admin_sistem WHERE id = :id");
            $st->execute(['id' => $target_id]);
            $target = $st->fetch(PDO::FETCH_ASSOC);

            $password_ok = $saya && password_verify($password, $saya['pass_hash']);
            $kode_ok = false;
            if ($password_ok) {
                $bersih = preg_replace('/[^0-9]/', '', $kode);
                if (preg_match('/^[0-9]{6}$/', $bersih) && (int)$saya['totp_aktif'] === 1 && $saya['totp_secret']) {
                    $rahasia = admin_2fa_baca_rahasia($saya['username'], $saya['totp_secret']);
                    $langkah = totp_cocokkan($rahasia, $bersih, $saya['totp_langkah_terakhir'] !== null ? (int)$saya['totp_langkah_terakhir'] : null);
                    if ($langkah !== null) {
                        $kode_ok = admin_2fa_catat_langkah($db, $me_id, $langkah);
                    }
                } elseif (!preg_match('/^[0-9]{6}$/', $bersih) && $kode !== '') {
                    $kode_ok = admin_2fa_pakai_kode_cadangan($db, $me_id, $kode);
                }
            }

            if (!$target) {
                $pesan_error = 'Admin yang dipilih tidak ditemukan.';
            } elseif (!$password_ok || !$kode_ok) {
                rate_limit_hit($db, $konteks, 5, 600);
                $pesan_error = 'Password atau kode verifikasi tidak valid.';
            } else {
                admin_2fa_reset($db, (int)$target['id']);
                audit_tulis($db, null, 'admin_sistem', 'reset_2fa', null,
                    'Reset 2FA untuk ' . $target['username'] . ' (alasan: ' . $alasan . ')', $me_username);
                rate_limit_reset($db, $konteks);
                $pesan_sukses = 'Dua langkah untuk ' . $target['username'] . ' sudah direset. Akun itu harus mendaftar ulang saat login berikutnya.';
            }
        } catch (PDOException $e) {
            error_log('Reset 2FA gagal: ' . get_class($e));
            $pesan_error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        }
    }
}

$daftar = $db->query("SELECT id, username, nama, totp_aktif FROM admin_sistem ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$current_page = 'kelola_admin.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Admin - Admin</title>
    <link rel="icon" type="image/png" href="/absensi_pensiunan_dev/sikepen.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/vendor/daisyui-4.12.10.full.min.css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe">
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/css/fonts.css">
    <script defer src="/absensi_pensiunan_dev/static/js/modal-focus.js"></script>
    <script defer src="/absensi_pensiunan_dev/static/vendor/alpine-3.14.1.min.js" integrity="sha384-l8f0VcPi/M1iHPv8egOnY/15TDwqgbOR1anMIJWvU6nLRgZVLTLSaNqi/TOoT5Fh"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>
        <main class="flex-1 overflow-y-auto bg-slate-50 p-6 space-y-5">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Kelola Admin</h2>
                <p class="text-slate-500 text-sm mt-1">Status dua langkah setiap admin. Reset hanya untuk admin yang kehilangan akses, dan tercatat di audit.</p>
            </div>

            <?php if ($pesan_sukses !== ''): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded-r-xl font-medium"><?= htmlspecialchars($pesan_sukses); ?></div>
            <?php endif; ?>
            <?php if ($pesan_error !== ''): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium"><?= htmlspecialchars($pesan_error); ?></div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Username</th>
                            <th class="px-4 py-3 text-left">Nama</th>
                            <th class="px-4 py-3 text-left">Dua langkah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($daftar as $a): ?>
                            <tr>
                                <td class="px-4 py-3 font-mono"><?= htmlspecialchars($a['username']); ?><?= (int)$a['id'] === $me_id ? ' (Anda)' : ''; ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($a['nama']); ?></td>
                                <td class="px-4 py-3">
                                    <?php if ((int)$a['totp_aktif'] === 1): ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Aktif</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">Belum daftar</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 max-w-2xl">
                <h3 class="font-bold text-slate-800 mb-1">Reset dua langkah admin lain</h3>
                <p class="text-sm text-slate-500 mb-4">Untuk memastikan Anda benar-benar admin yang sah, masukkan password dan kode dua langkah Anda sendiri.</p>
                <form method="POST" action="kelola_admin.php" class="space-y-4">
                    <?= csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Admin yang direset</label>
                        <select name="target_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white">
                            <option value="">Pilih admin...</option>
                            <?php foreach ($daftar as $a): if ((int)$a['id'] === $me_id) continue; ?>
                                <option value="<?= (int)$a['id']; ?>"><?= htmlspecialchars($a['username']); ?> (<?= htmlspecialchars($a['nama']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Alasan reset</label>
                        <input type="text" name="alasan" required minlength="5" maxlength="200" placeholder="Contoh: HP hilang, sudah diverifikasi langsung"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Password Anda</label>
                            <input type="password" name="password" required autocomplete="current-password"
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Kode 2FA Anda</label>
                            <input type="text" name="kode" required autocomplete="one-time-code" inputmode="text"
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono">
                        </div>
                    </div>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-5 py-2.5 rounded-lg text-sm">
                        Reset dua langkah
                    </button>
                </form>
            </div>
        </main>
    </div>
</div>
</body>
</html>
