<?php
// file: admin/import_pensiunan.php
session_start();
require_once '../config/koneksi.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
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
    <title>Import Data Pensiunan - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5">

            <div>
                <h2 class="text-2xl font-bold text-slate-800">Import Data Pensiunan</h2>
                <p class="text-slate-500 text-sm mt-1">Unggah data pensiunan secara massal melalui file Excel.</p>
            </div>

            <?php if (isset($_SESSION['hasil_import'])): ?>
                <?php $hasil = $_SESSION['hasil_import']; unset($_SESSION['hasil_import']); ?>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-lg">Import Selesai</h3>
                            <p class="text-sm text-slate-500"><?= $hasil['sukses']; ?> berhasil ditambahkan, <?= count($hasil['gagal']); ?> dilewati.</p>
                        </div>
                    </div>

                    <?php if (!empty($hasil['gagal'])): ?>
                        <div class="border border-red-200 rounded-lg overflow-hidden">
                            <div class="bg-red-50 px-4 py-2 text-sm font-bold text-red-700">Baris yang Dilewati</div>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-slate-50 text-xs uppercase text-slate-400">
                                        <th class="px-4 py-2 text-left">Baris</th>
                                        <th class="px-4 py-2 text-left">NPK</th>
                                        <th class="px-4 py-2 text-left">Alasan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($hasil['gagal'] as $g): ?>
                                        <tr>
                                            <td class="px-4 py-2"><?= $g['baris']; ?></td>
                                            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($g['npk']); ?></td>
                                            <td class="px-4 py-2 text-red-600"><?= htmlspecialchars($g['alasan']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium">
                    <?= htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Langkah 1: Download Template -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                <div class="flex items-start gap-4">
                    <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold flex-shrink-0">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800">Unduh Template Excel</h3>
                        <p class="text-sm text-slate-500 mt-1 mb-3">Gunakan template ini agar kolom data sesuai dengan format sistem.</p>
                        <a href="generate_template.php" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Unduh Template
                        </a>
                    </div>
                </div>
            </div>

            <!-- Langkah 2: Upload -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                <div class="flex items-start gap-4">
                    <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold flex-shrink-0">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800">Unggah File Excel</h3>
                        <p class="text-sm text-slate-500 mt-1 mb-3">Format yang didukung: .xlsx, .xls, .csv. Maksimal 5 MB.</p>

                        <form id="form-import" action="proses_import_pensiunan.php" method="POST" enctype="multipart/form-data" class="space-y-3">
                            <?= csrf_field(); ?>
                            <input type="file" name="file_excel" required accept=".xlsx,.xls,.csv"
                                class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-slate-300 rounded-lg p-1.5 bg-slate-50 outline-none">
                            <button type="submit" id="btn-import" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg flex items-center gap-2">
                                <span id="btn-import-text">Proses Import</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Overlay Loading Import -->
            <div id="overlay-import" class="fixed inset-0 bg-slate-900/80 flex items-center justify-center z-50 p-0 !mt-0 hidden">
                <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-sm w-full text-center">
                    <span class="loading loading-spinner loading-lg text-primary mx-auto mb-4"></span>
                    <h3 class="font-bold text-slate-800 text-lg mb-1">Memproses Data...</h3>
                    <p id="overlay-progress-text" class="text-sm text-slate-500">Mengenkripsi data pensiunan</p>

                    <div class="w-full bg-slate-100 rounded-full h-2 mt-4 overflow-hidden">
                        <div id="overlay-progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width: 5%"></div>
                    </div>

                    <p class="text-xs text-slate-400 mt-3">Proses ini bisa memakan waktu beberapa menit untuk data dalam jumlah besar. Jangan tutup atau muat ulang halaman ini.</p>
                </div>
            </div>

            <script>
            document.getElementById('form-import').addEventListener('submit', function(e) {
                const overlay = document.getElementById('overlay-import');
                const progressBar = document.getElementById('overlay-progress-bar');
                const progressText = document.getElementById('overlay-progress-text');

                overlay.classList.remove('hidden');

                const pesan = [
                    'Membaca file Excel...',
                    'Memvalidasi data...',
                    'Mengenkripsi data pensiunan...',
                    'Menyimpan ke database...',
                    'Data dalam jumlah besar bisa memakan waktu lebih lama, mohon tunggu...',
                ];

                let progress = 5;
                let pesanIndex = 0;

                const interval = setInterval(() => {
                    if (progress < 88) {
                        progress += (88 - progress) * 0.08;
                        progressBar.style.width = progress + '%';
                    }
                    if (pesanIndex < pesan.length - 1 && progress > (pesanIndex + 1) * 18) {
                        pesanIndex++;
                        progressText.textContent = pesan[pesanIndex];
                    }
                }, 400);
            });
            </script>

        </main>
    </div>
</div>
</body>
</html>