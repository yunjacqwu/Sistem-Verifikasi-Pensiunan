<?php
// file: admin/dashboard.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

try {
    // Total pensiunan aktif
    $total = $db->query("SELECT COUNT(*) FROM pensiunan WHERE status = 'Aktif'")->fetchColumn();

    // Lapor diri kuartal ini menunggu verifikasi
    $menunggu = $db->query("
        SELECT COUNT(*) FROM lapor_diri
        WHERE status_verifikasi = 'Menunggu Verifikasi'
        AND QUARTER(waktu) = QUARTER(CURRENT_DATE())
        AND YEAR(waktu) = YEAR(CURRENT_DATE())
    ")->fetchColumn();

    // Lapor diri kuartal ini disetujui
    $disetujui = $db->query("
        SELECT COUNT(*) FROM lapor_diri
        WHERE status_verifikasi = 'Disetujui'
        AND QUARTER(waktu) = QUARTER(CURRENT_DATE())
        AND YEAR(waktu) = YEAR(CURRENT_DATE())
    ")->fetchColumn();

    // Dokumen menunggu verifikasi
    $dok_menunggu = $db->query("
        SELECT COUNT(*) FROM dokumen
        WHERE status = 'Menunggu Verifikasi'
    ")->fetchColumn();

    // Pensiunan belum lapor diri kuartal ini
    $belum_lapor = $db->query("
        SELECT COUNT(*) FROM pensiunan
        WHERE status = 'Aktif'
        AND npk NOT IN (
            SELECT npk FROM lapor_diri
            WHERE QUARTER(waktu) = QUARTER(CURRENT_DATE())
            AND YEAR(waktu) = YEAR(CURRENT_DATE())
            AND status_verifikasi IN ('Menunggu Verifikasi', 'Disetujui')
        )
    ")->fetchColumn();

    // Berkas tidak lengkap
    $berkas_kurang = $db->query("
        SELECT COUNT(*) FROM pensiunan p
        WHERE status = 'Aktif'
        AND (
            (SELECT COUNT(*) FROM dokumen WHERE npk = p.npk AND jns_dok = 'KTP') = 0
            OR
            (SELECT COUNT(*) FROM dokumen WHERE npk = p.npk AND jns_dok = 'KK') = 0
        )
    ")->fetchColumn();

    // 5 lapor diri terbaru
    $stmt_terbaru = $db->query("
        SELECT l.id, l.npk, l.waktu, l.status_verifikasi, p.nama
        FROM lapor_diri l
        JOIN pensiunan p ON l.npk = p.npk
        ORDER BY l.waktu DESC
        LIMIT 5
    ");
    $lapor_terbaru = $stmt_terbaru->fetchAll();

    // Query pensiunan aktif yang belum lapor kuartal ini
    $stmt_belum = $db->prepare("
        SELECT COUNT(*) as jml
        FROM pensiunan p
        WHERE p.status = 'Aktif'
        AND NOT EXISTS (
            SELECT 1 FROM lapor_diri l
            WHERE l.npk = p.npk
            AND QUARTER(l.waktu) = QUARTER(CURDATE())
            AND YEAR(l.waktu) = YEAR(CURDATE())
            AND l.status_verifikasi IN ('Menunggu Verifikasi', 'Disetujui')
        )
    ");
    $stmt_belum->execute();
    $jml_belum_lapor = $stmt_belum->fetch()['jml'];

    // Periode kuartal
    $bulan       = (int)date('n');
    $kuartal_no  = ceil($bulan / 3);
    $kuartal_map = [1=>'Januari – Maret', 2=>'April – Juni', 3=>'Juli – September', 4=>'Oktober – Desember'];
    $periode     = 'Q' . $kuartal_no . ' ' . date('Y') . ' (' . $kuartal_map[$kuartal_no] . ')';

} catch (PDOException $e) {
    die("Gagal memuat data dashboard.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Dashboard - Admin Dana Pensiun Peruri</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">

<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">

        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-6">
            
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Dashboard</h2>
                    <p class="text-slate-500 text-sm mt-1">Periode berjalan: <span class="font-semibold text-slate-700"><?= $periode; ?></span></p>
                </div>
            </div>

            <!-- Kartu statistik -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Total Pensiunan Aktif</p>
                        <div class="h-9 w-9 rounded-lg bg-blue-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-slate-800"><?= number_format($total); ?></p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Sudah Lapor Diri</p>
                        <div class="h-9 w-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-emerald-600"><?= number_format($disetujui); ?></p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Menunggu Verifikasi</p>
                        <div class="h-9 w-9 rounded-lg bg-amber-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-amber-500"><?= number_format($menunggu); ?></p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Belum Lapor Diri</p>
                        <div class="h-9 w-9 rounded-lg bg-red-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-red-500"><?= number_format($belum_lapor); ?></p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Dokumen Menunggu</p>
                        <div class="h-9 w-9 rounded-lg bg-orange-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-orange-500"><?= number_format($dok_menunggu); ?></p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-slate-500 text-sm font-medium">Berkas Tidak Lengkap</p>
                        <div class="h-9 w-9 rounded-lg bg-slate-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-slate-600"><?= number_format($berkas_kurang); ?></p>
                </div>

            </div>

            <!-- Tabel lapor diri terbaru -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-bold text-slate-800">Lapor Diri Terbaru</h3>
                    <a href="riwayat_absen.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                        Lihat Semua →
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                                <th class="px-6 py-3 text-left font-semibold">Identitas</th>
                                <th class="px-6 py-3 text-left font-semibold">Waktu Lapor</th>
                                <th class="px-6 py-3 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($lapor_terbaru)): ?>
                                <tr>
                                    <td colspan="3" class="px-6 py-8 text-center text-slate-400">
                                        Belum ada data lapor diri.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lapor_terbaru as $lapor):
                                    $nama_dec  = dekripsi($lapor['nama'], $lapor['npk']);
                                    $fmt       = formatTanggal($lapor['waktu']);
                                    $kuartal   = $fmt['kuartal'];
                                    $tgl       = $fmt['tanggal'];
                                    $jam       = date('H:i', strtotime($lapor['waktu']));
                                    $status    = $lapor['status_verifikasi'];
                                    $badge     = match($status) {
                                        'Disetujui'           => 'bg-emerald-100 text-emerald-700',
                                        'Ditolak'             => 'bg-red-100 text-red-700',
                                        default               => 'bg-amber-100 text-amber-700',
                                    };
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <p class="font-semibold text-slate-800"><?= htmlspecialchars($nama_dec); ?></p>
                                        <p class="text-slate-400 text-xs font-mono">NPK: <?= htmlspecialchars($lapor['npk']); ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-slate-700"><?= $kuartal . ' / ' . $tgl; ?></p>
                                        <p class="text-slate-400 text-xs"><?= $jam; ?> WIB</p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge; ?>">
                                            <?= $status; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($jml_belum_lapor > 0): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 rounded-xl p-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="font-bold text-amber-800">
                            <?= $jml_belum_lapor; ?> pensiunan belum lapor diri
                        </p>
                        <p class="text-sm text-amber-700">
                            Kuartal <?= 'Q' . ceil(date('n') / 3); ?> <?= date('Y'); ?> 
                            — perlu segera dihubungi
                        </p>
                    </div>
                </div>
                <a href="monitoring_lapor.php"
                class="bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold px-4 py-2 rounded-lg whitespace-nowrap">
                    Lihat Daftar
                </a>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

</body>
</html>