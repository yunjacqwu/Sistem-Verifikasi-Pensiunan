<?php
// file: admin/includes/topbar.php
$admin_nama = $_SESSION['admin_nama'] ?? 'Administrator';
$inisial    = strtoupper(mb_substr($admin_nama, 0, 1));
?>
<header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 z-10 flex-shrink-0">
    <div class="flex items-center gap-3 min-w-0">
        <img src="/absensi_pensiunan_dev/sikepen.png" alt="Logo Dana Pensiun Peruri" class="h-11 w-auto flex-shrink-0">
    <div class="min-w-0">
        <h2 class="text-base font-bold text-slate-800 leading-tight">Dana Pensiun Pegawai Perum Peruri</h2>
        <p class="text-xs text-slate-500 leading-tight">Sistem Kepesertaan Pensiunan</p>
    </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">
            <?= $inisial; ?>
        </div>
        <div class="text-right hidden sm:block">
            <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($admin_nama); ?></p>
            <p class="text-xs text-slate-500">Administrator</p>
        </div>
    </div>
</header>