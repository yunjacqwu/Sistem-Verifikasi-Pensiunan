<?php
// file: users/login.php
session_start();
require_once '../config/csrf.php';

if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Portal Pensiunan - Dana Pensiun Perum Peruri</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-4xl flex rounded-2xl shadow-2xl overflow-hidden border border-slate-200">

        <!-- Panel kiri — branding -->
        <div class="hidden md:flex flex-col justify-between w-1/2 bg-blue-700 p-10 text-white">

            <!-- Logo & nama instansi -->
            <div>
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-12 w-12 rounded-xl bg-white/20 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-white/70 text-xs font-medium uppercase tracking-widest">Dana Pensiun</p>
                        <p class="text-white font-bold text-lg leading-tight">Perum Peruri</p>
                    </div>
                </div>

                <h2 class="text-3xl font-extrabold leading-snug mb-4">
                    Sistem Verifikasi<br>Kelangsungan Hidup<br>Pensiunan
                </h2>
                <p class="text-blue-200 text-sm leading-relaxed">
                    Portal lapor diri berkala bagi penerima dana pensiun Perum Peruri. Silakan masuk menggunakan NPK dan PIN yang telah diberikan oleh administrator.
                </p>
            </div>

            <!-- Info fitur -->
            <div class="space-y-3 mt-8">
                <div class="flex items-center gap-3 text-sm text-blue-100">
                    <div class="h-8 w-8 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    Lapor diri via foto live capture memegang KTP
                </div>
                <div class="flex items-center gap-3 text-sm text-blue-100">
                    <div class="h-8 w-8 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    Data dienkripsi untuk keamanan privasi
                </div>
                <div class="flex items-center gap-3 text-sm text-blue-100">
                    <div class="h-8 w-8 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    Unggah dan kelola berkas KTP & Kartu Keluarga
                </div>
            </div>

            <!-- Footer panel kiri -->
            <p class="text-blue-300 text-xs mt-10">
                &copy; <?= date('Y'); ?> Dana Pensiun Perum Peruri
            </p>

        </div>

        <!-- Panel kanan — form login -->
        <div class="w-full md:w-1/2 bg-white p-8 md:p-10 flex flex-col justify-center">

            <!-- Header mobile (hanya muncul di layar kecil) -->
            <div class="flex items-center gap-3 mb-6 md:hidden">
                <div class="h-10 w-10 rounded-xl bg-blue-600 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <p class="text-slate-400 text-xs">Dana Pensiun Perum Peruri</p>
                    <p class="font-bold text-slate-800 text-sm">Kepesertaan Pensiunan</p>
                </div>
            </div>

            <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Selamat Datang</h1>
            <p class="text-slate-500 text-sm mb-8">Masukkan NPK dan PIN untuk mengakses portal.</p>

            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-sm mb-6 font-semibold flex items-start gap-2.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-red-500 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($_GET['error']); ?></span>
                </div>
            <?php endif; ?>

            <form action="proses_login_user.php" method="POST" class="space-y-5">
                <?= csrf_field(); ?>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nomor Pokok Karyawan (NPK)</label>
                    <input type="text" name="npk" required placeholder="Masukkan NPK Anda..." autocomplete="off"
                           class="w-full px-4 py-3 border border-slate-300 rounded-xl text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all shadow-sm">
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">PIN Akses (6 Digit)</label>
                    <input type="password" name="pin" required maxlength="6" inputmode="numeric" placeholder="••••••" autocomplete="off"
                           class="w-full px-4 py-3 border border-slate-300 rounded-xl text-center text-2xl font-mono tracking-[1em] focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all shadow-sm">
                    <p class="text-slate-400 text-xs mt-2 text-center">Masukkan 6 digit PIN sementara yang diberikan oleh Admin</p>
                </div>

                <div class="pt-2">
                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-xl transition-all shadow-md hover:shadow-lg flex justify-center items-center gap-2 text-base">
                        Masuk ke Portal
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </div>

            </form>

        </div>
    </div>

</body>
</html>