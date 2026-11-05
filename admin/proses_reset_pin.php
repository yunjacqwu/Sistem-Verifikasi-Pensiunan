<?php
// file: admin/proses_reset_pin.php
require_once '../config/bootstrap.php';
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

        // Tampilkan PIN satu kali pada halaman bergaya admin; tidak disimpan di session atau log.
        header('Content-Type: text/html; charset=UTF-8');
        $npk_html = htmlspecialchars($npk, ENT_QUOTES, 'UTF-8');
        $pin_html = htmlspecialchars($pin_baru, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PIN Sementara - Admin</title><script src="https://cdn.tailwindcss.com"></script></head>';
        echo '<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4"><main class="w-full max-w-lg bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">';
        echo '<div class="bg-amber-500 px-6 py-5 text-white"><p class="text-sm font-semibold text-amber-100">Dana Pensiun Perum Peruri</p><h1 class="text-2xl font-bold mt-1">PIN Berhasil Di-reset</h1></div>';
        echo '<div class="p-6 space-y-5"><div><p class="text-sm text-slate-500">NPK pensiunan</p><p class="font-mono font-semibold text-slate-800">' . $npk_html . '</p></div>';
        echo '<div class="rounded-xl border border-amber-300 bg-amber-50 p-5"><p class="text-sm font-semibold text-amber-800">PIN sementara — tampil satu kali</p><p class="mt-2 text-3xl font-mono font-bold tracking-[0.3em] text-slate-900">' . $pin_html . '</p><p class="mt-3 text-xs text-amber-800">Sampaikan melalui kanal aman. Pengguna wajib mengganti PIN saat login berikutnya.</p></div>';
        echo '<a href="data_pensiunan.php" class="inline-flex w-full justify-center rounded-xl bg-blue-600 px-5 py-3 font-semibold text-white hover:bg-blue-700">Kembali ke Data Pensiunan</a></div></main></body></html>';
        exit;

    } catch (PDOException $e) {
        header("Location: data_pensiunan.php?error=Gagal memperbarui PIN di database.");
        exit;
    }
} else {
    header("Location: data_pensiunan.php");
    exit;
}