<?php
// file: admin/login.php
session_start();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
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
    <title>Login Admin - Dana Pensiun Perum Peruri</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-blue-600 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold text-white">Administrator Kepesertaan</h1>
            <p class="text-slate-400 text-sm mt-1">Dana Pensiun Perum Peruri</p>
        </div>

        <!-- Card Form -->
        <div class="bg-white rounded-2xl shadow-2xl p-8">

            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-sm mb-6 font-semibold flex items-start gap-2.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-red-500 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($_GET['error']); ?></span>
                </div>
            <?php endif; ?>

            <form action="proses_login_admin.php" method="POST" class="space-y-5">

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Username</label>
                    <div class="relative">
                        <input type="text" name="username" required placeholder="Masukkan username..."
                               class="pl-10 pr-4 py-3 w-full border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 absolute left-3 top-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" required placeholder="Masukkan password..."
                               class="pl-10 pr-4 py-3 w-full border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 absolute left-3 top-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-colors shadow-md flex justify-center items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Masuk ke Panel Admin
                    </button>
                </div>

            </form>

        </div>

        <p class="text-center text-slate-500 text-xs mt-6">
            &copy; <?= date('Y'); ?> Dana Pensiun Perum Peruri
        </p>

    </div>

</body>
</html>