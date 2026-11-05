<?php
// file: admin/monitoring_lapor.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

$kuartal_ini = (int)ceil(date('n') / 3);
$tahun_ini   = (int)date('Y');

$filter_kuartal = (int)($_GET['kuartal'] ?? $kuartal_ini);
$filter_tahun   = (int)($_GET['tahun']   ?? $tahun_ini);

// Ambil pensiunan aktif yang belum lapor di kuartal yang dipilih
$stmt = $db->prepare("
    SELECT p.npk, p.nama, p.telp, p.telp2, p.jns_mnft, p.jns_penerima
    FROM pensiunan p
    WHERE p.status = 'Aktif'
    AND NOT EXISTS (
        SELECT 1 FROM lapor_diri l
        WHERE l.npk = p.npk
        AND QUARTER(l.waktu) = :kuartal
        AND YEAR(l.waktu)    = :tahun
        AND l.status_verifikasi IN ('Menunggu Verifikasi', 'Disetujui')
    )
    ORDER BY p.created_at ASC
");
$stmt->execute(['kuartal' => $filter_kuartal, 'tahun' => $filter_tahun]);
$semua = $stmt->fetchAll();

// Dekripsi nama dan telepon
foreach ($semua as &$row) {
    $row['nama_plain'] = dekripsi($row['nama'], $row['npk']);
    $row['telp_plain'] = dekripsi($row['telp'], $row['npk']);
    $row['telp2_plain'] = !empty($row['telp2'])
                            ? dekripsi($row['telp2'], $row['npk']) : '—';
}
unset($row);

$total = count($semua);
$label_kuartal = [1=>'Q1 (Jan–Mar)', 2=>'Q2 (Apr–Jun)', 3=>'Q3 (Jul–Sep)', 4=>'Q4 (Okt–Des)'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Monitoring Lapor Diri - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5">

            <div>
                <h2 class="text-2xl font-bold text-slate-800">Monitoring Lapor Diri</h2>
                <p class="text-slate-500 text-sm mt-1">
                    Daftar pensiunan aktif yang belum melakukan lapor diri pada periode yang dipilih.
                </p>
            </div>

            <!-- Filter kuartal -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="flex flex-wrap gap-3 items-center">
                    <select name="kuartal" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($label_kuartal as $k => $l): ?>
                            <option value="<?= $k; ?>" <?= $filter_kuartal === $k ? 'selected' : ''; ?>><?= $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tahun" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php for ($y = $tahun_ini; $y >= 2026; $y--): ?>
                            <option value="<?= $y; ?>" <?= $filter_tahun === $y ? 'selected' : ''; ?>><?= $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">
                        Tampilkan
                    </button>
                </div>
            </form>

            <!-- Alert summary -->
            <?php if ($total > 0): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 rounded-r-xl p-4">
                <p class="font-bold text-amber-800">
                    <?= $total; ?> pensiunan belum lapor diri pada
                    <?= $label_kuartal[$filter_kuartal]; ?> <?= $filter_tahun; ?>
                </p>
                <p class="text-sm text-amber-700 mt-0.5">
                    Hubungi pensiunan berikut melalui nomor telepon yang tercantum.
                </p>
            </div>
            <?php else: ?>
            <div class="bg-emerald-50 border-l-4 border-emerald-500 rounded-r-xl p-4">
                <p class="font-bold text-emerald-800">
                    Semua pensiunan aktif sudah lapor diri pada
                    <?= $label_kuartal[$filter_kuartal]; ?> <?= $filter_tahun; ?> ✓
                </p>
            </div>
            <?php endif; ?>

            <!-- Tabel -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-3 text-left font-semibold">No</th>
                                <th class="px-4 py-3 text-left font-semibold">NPK</th>
                                <th class="px-4 py-3 text-left font-semibold">Nama</th>
                                <th class="px-4 py-3 text-left font-semibold">Jenis Manfaat</th>
                                <th class="px-4 py-3 text-left font-semibold">Penerima</th>
                                <th class="px-4 py-3 text-left font-semibold">No. Telepon Utama</th>
                                <th class="px-4 py-3 text-left font-semibold">No. Telepon Cadangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($semua)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-slate-400">
                                        Semua pensiunan sudah lapor diri pada periode ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($semua as $i => $row): ?>
                                <tr class="hover:bg-amber-50 transition-colors">
                                    <td class="px-4 py-3 text-slate-400"><?= $i + 1; ?></td>
                                    <td class="px-4 py-3 font-mono text-xs font-semibold">
                                        <?= htmlspecialchars($row['npk']); ?>
                                    </td>
                                    <td class="px-4 py-3 font-semibold">
                                        <?= htmlspecialchars($row['nama_plain']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 text-xs">
                                        <?= htmlspecialchars($row['jns_mnft'] ?? '—'); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $penerima = $row['jns_penerima'] ?? 'Pensiunan Asli';
                                        $badge = match($penerima) {
                                            'Janda/Duda' => 'bg-purple-100 text-purple-700',
                                            'Anak'       => 'bg-blue-100 text-blue-700',
                                            default      => 'bg-slate-100 text-slate-600',
                                        };
                                        ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold <?= $badge; ?>">
                                            <?= $penerima; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        <?= htmlspecialchars($row['telp_plain']); ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                        <?= htmlspecialchars($row['telp2_plain']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>
</body>
</html>