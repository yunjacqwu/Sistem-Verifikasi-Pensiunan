<?php
// file: admin/includes/sidebar.php

// Query badge DULU sebelum array $menu
try {
    $stmt_badge_lapor = $db->prepare("SELECT COUNT(*) FROM lapor_diri WHERE status_verifikasi = 'Menunggu Verifikasi'");
    $stmt_badge_lapor->execute();
    $badge_lapor = (int)$stmt_badge_lapor->fetchColumn();

    $stmt_badge_dok = $db->prepare("SELECT COUNT(*) FROM dokumen WHERE status = 'Menunggu Verifikasi'");
    $stmt_badge_dok->execute();
    $badge_dok = (int)$stmt_badge_dok->fetchColumn();

    $stmt_badge_audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE DATE(waktu) = CURDATE()");
    $stmt_badge_audit->execute();
    $badge_audit = (int)$stmt_badge_audit->fetchColumn();
} catch (PDOException $e) {
    $badge_lapor = 0;
    $badge_dok   = 0;
    $badge_audit = 0;
}

$current_page  = basename($_SERVER['PHP_SELF']);
$active_class  = "flex items-center px-4 py-3 bg-blue-600 text-white rounded-lg";
$inactive_class = "flex items-center px-4 py-3 text-slate-300 hover:bg-slate-800 hover:text-white rounded-lg transition-colors";

$menu = [
    'dashboard.php'        => ['label' => 'Dashboard',          'badge' => 0,            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />'],
    'data_pensiunan.php'   => ['label' => 'Data Pensiunan',     'badge' => 0,            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />'],
    'riwayat_absen.php'    => ['label' => 'Riwayat Lapor Diri', 'badge' => $badge_lapor, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />'],
    'kelola_dokumen.php'   => ['label' => 'Kelola Dokumen',     'badge' => $badge_dok,   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />'],
    'audit_log.php'        => ['label' => 'Audit Log',          'badge' => 0,            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />'],
    'import_pensiunan.php' => ['label' => 'Import Data',        'badge' => 0,            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"/>'],
    'monitoring_lapor.php' => ['label' => 'Monitoring',         'badge' => 0,            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'],
];
?>

<aside class="w-64 bg-slate-900 text-white flex flex-col flex-shrink-0">

    <!-- Logo -->
    <div class="h-16 flex items-center gap-3 px-5 border-b border-slate-700">
        <div class="h-11 w-11 rounded-xl bg-blue-600 flex items-center justify-center flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        </div>
        <p class="text-white font-bold text-xl tracking-wide">ADM SIKEPEN</p>
    </div>

    <!-- Menu navigasi -->
    <nav class="flex-1 px-3 py-5 space-y-1 overflow-y-auto">
        <?php foreach ($menu as $file => $item): ?>
            <a href="<?= $file; ?>" class="<?= ($current_page === $file) ? $active_class : $inactive_class; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <?= $item['icon']; ?>
                </svg>
                <span class="font-medium text-sm flex-1"><?= $item['label']; ?></span>

                <?php if ($item['badge'] > 0): ?>
                    <!-- Badge angka untuk menu lain -->
                    <span class="ml-auto bg-red-500 text-white text-xs font-bold px-1.5 py-0.5 rounded-full min-w-[20px] text-center flex-shrink-0">
                        <?= $item['badge'] > 99 ? '99+' : $item['badge']; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Tombol logout -->
    <div class="p-3 border-t border-slate-700">
        <button onclick="document.getElementById('modal-logout-admin').classList.remove('hidden')"
                class="flex items-center justify-center w-full px-4 py-2.5 bg-red-500/10 text-red-400 hover:bg-red-500 hover:text-white rounded-lg transition-colors text-sm font-medium">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            Keluar
        </button>
    </div>

</aside>

<!-- Modal logout (tetap sama) -->
<div id="modal-logout-admin" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
         onclick="document.getElementById('modal-logout-admin').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col items-center text-center gap-4">
        <div class="h-16 w-16 rounded-full bg-red-100 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
        </div>
        <div>
            <h3 class="text-xl font-bold text-slate-800">Keluar dari Panel Admin?</h3>
            <p class="text-slate-500 text-sm mt-1">Pastikan semua pekerjaan sudah tersimpan sebelum keluar.</p>
        </div>
        <div class="flex gap-3 w-full mt-2">
            <button onclick="document.getElementById('modal-logout-admin').classList.add('hidden')"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 transition-colors text-sm">
                Batal
            </button>
            <a href="logout.php"
               class="flex-1 px-4 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition-colors text-sm text-center">
                Ya, Keluar
            </a>
        </div>
    </div>
</div>