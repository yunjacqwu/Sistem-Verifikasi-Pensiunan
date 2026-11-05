<?php
// includes/header.php
// Pastikan session sudah di-start di file pemanggil sebelum include ini
$is_admin    = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_user     = isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
$logout_url  = $is_admin ? '../admin/logout.php' : '../users/logout.php';

// Hitung notifikasi belum dibaca (khusus user)
$jml_notif = 0;
if ($is_user && isset($db)) {
    try {
        $npk_nav = $_SESSION['user_npk'];
        $stmt_nav = $db->prepare("
            SELECT COUNT(*) as jml FROM notifikasi
            WHERE npk = :npk AND status_baca = 'Belum Dibaca'
        ");
        $stmt_nav->execute(['npk' => $npk_nav]);
        $jml_notif = $stmt_nav->fetch()['jml'];
    } catch (PDOException $e) {
        $jml_notif = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?>Dana Pensiun Perum Peruri</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1d4ed8'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slateate-100 font-sans antialiased text-slate-800 min-h-screen flex flex-col">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm p-4 sticky top-0 z-50">
        <div class="max-w-4xl mx-auto flex justify-between items-center gap-4">

            <h1 class="text-base md:text-xl font-bold text-blue-600 tracking-tight">
                Dana Pensiun Perum Peruri
            </h1>

            <?php if ($is_user || $is_admin): ?>
            <div class="flex items-center gap-3">

                <?php if ($is_user): ?>
                <!-- Ikon lonceng notifikasi -->
                <a href="../users/notifikasi.php" class="relative p-2 rounded-full hover:bg-slate-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($jml_notif > 0): ?>
                        <span class="absolute top-1 right-1 h-4 w-4 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center leading-none">
                            <?= $jml_notif > 9 ? '9+' : $jml_notif; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <!-- Tombol logout — trigger modal -->
                <button onclick="document.getElementById('modal-logout').classList.remove('hidden')"
                        class="inline-flex items-center gap-2 px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-sm font-semibold border border-red-200 transition-colors shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Keluar
                </button>

            </div>
            <?php endif; ?>

        </div>
    </nav>

    <!-- Modal Konfirmasi Logout -->
    <?php if ($is_user || $is_admin): ?>
    <div id="modal-logout" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
             onclick="document.getElementById('modal-logout').classList.add('hidden')"></div>

        <!-- Modal box -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col items-center text-center gap-4 animate-fade-in">

            <!-- Ikon -->
            <div class="h-16 w-16 rounded-full bg-red-100 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
            </div>

            <!-- Teks -->
            <div>
                <h3 class="text-xl font-bold text-slate-800">Keluar dari Sistem?</h3>
                <p class="text-slate-500 text-sm mt-1">
                    Anda akan keluar dari portal. Pastikan semua aktivitas sudah selesai sebelum keluar.
                </p>
            </div>

            <!-- Tombol aksi -->
            <div class="flex gap-3 w-full mt-2">
                <button onclick="document.getElementById('modal-logout').classList.add('hidden')"
                        class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 transition-colors text-sm">
                    Batal
                </button>
                <a href="<?= $logout_url; ?>"
                   class="flex-1 px-4 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition-colors text-sm text-center">
                    Ya, Keluar
                </a>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <!-- Container konten utama -->
    <div class="flex-grow max-w-4xl w-full mx-auto p-4 md:p-6">