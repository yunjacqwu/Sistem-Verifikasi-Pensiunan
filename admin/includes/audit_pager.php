<?php
// Paginasi satu sisi. Variabel: $pager_param ('pa' atau 'pu'), $pager_page, $pager_total, $page_a, $page_u.
$qp = $_GET; unset($qp['pa'], $qp['pu'], $qp['page']); $qp['pa'] = $page_a; $qp['pu'] = $page_u;
$url = function (int $p) use ($qp, $pager_param): string { $q = $qp; $q[$pager_param] = $p; return '?' . http_build_query($q); };
?>
<?php if ($pager_total > 1): ?>
<div class="mt-3 bg-white rounded-xl border border-slate-200 px-4 py-3 flex items-center justify-between text-sm text-slate-600">
    <p>Halaman <?= $pager_page; ?> dari <?= $pager_total; ?></p>
    <div class="flex gap-1">
        <?php if ($pager_page > 1): ?><a href="<?= $url($pager_page - 1); ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">← Prev</a><?php endif; ?>
        <?php for ($i = max(1, $pager_page - 2); $i <= min($pager_total, $pager_page + 2); $i++): ?>
            <a href="<?= $url($i); ?>" class="px-3 py-1.5 rounded-lg border transition-colors <?= $i === $pager_page ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 hover:bg-slate-50'; ?>"><?= $i; ?></a>
        <?php endfor; ?>
        <?php if ($pager_page < $pager_total): ?><a href="<?= $url($pager_page + 1); ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">Next →</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
