<?php
// file: admin/includes/topbar.php
$admin_nama = $_SESSION['admin_nama'] ?? 'Administrator';
$inisial    = strtoupper(mb_substr($admin_nama, 0, 1));
?>
<header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 z-10 flex-shrink-0">
    <div>
        <h2 class="text-base font-bold text-slate-800 leading-tight">Dana Pensiun Peruri</h2>
        <p class="text-xs text-slate-400 leading-tight">Sistem Kepesertaan Pensiunan</p>
    </div>
    <div class="flex items-center gap-3">
        <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm">
            <?= $inisial; ?>
        </div>
        <div class="text-right hidden sm:block">
            <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($admin_nama); ?></p>
            <p class="text-xs text-slate-400">Administrator</p>
        </div>
    </div>
</header>