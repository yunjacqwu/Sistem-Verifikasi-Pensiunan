<?php
// file: admin/riwayat_absen.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

// Parameter
$filter_status  = $_GET['status']   ?? '';
$filter_kuartal = $_GET['kuartal']  ?? '';
$filter_tahun = $_GET['tahun'] ?? date('Y'); // default tahun berjalan
$search         = trim($_GET['search'] ?? '');
$per_page       = in_array((int)($_GET['per_page'] ?? 25), [25,50,100,250,500])
                    ? (int)($_GET['per_page'] ?? 25) : 25;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $per_page;

try {
    // Ambil daftar tahun yang ada di database untuk filter
    $tahun_list = $db->query("SELECT DISTINCT YEAR(waktu) as tahun FROM lapor_diri ORDER BY tahun DESC")->fetchAll(PDO::FETCH_COLUMN);

    // Bangun WHERE untuk filter yang bisa dilakukan di SQL (search nama TIDAK di sini)
    $where  = ["1=1"];
    $params = [];

    if (!empty($filter_status)) {
        $where[]           = "l.status_verifikasi = :status";
        $params['status']  = $filter_status;
    }
    if (!empty($filter_tahun)) {
        $where[]          = "YEAR(l.waktu) = :tahun";
        $params['tahun']  = $filter_tahun;
    }
    if (!empty($filter_kuartal)) {
        $where[]            = "QUARTER(l.waktu) = :kuartal";
        $params['kuartal']  = $filter_kuartal;
    }

    $where_sql = implode(' AND ', $where);

    // Ambil SEMUA data yang lolos filter SQL dulu (tanpa pagination)
    $stmt_all = $db->prepare("
        SELECT l.id, l.npk, l.waktu, l.foto, l.status_verifikasi, l.catatan_tolak, p.nama
        FROM lapor_diri l
        JOIN pensiunan p ON l.npk = p.npk
        WHERE $where_sql
        ORDER BY l.waktu DESC
    ");
    $stmt_all->execute($params);
    $semua_data = $stmt_all->fetchAll();

    // Dekripsi nama + format waktu untuk semua data
    foreach ($semua_data as &$row) {
        $row['nama_plain'] = dekripsi($row['nama'], $row['npk']);
        $fmt = formatTanggal($row['waktu']);
        $row['kuartal_label'] = $fmt['kuartal'];
        $row['tgl_fmt']       = $fmt['tanggal'];
        $row['jam_fmt']       = $fmt['jam'];
    }
    unset($row);

    // Filter search (nama atau NPK) di PHP
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $semua_data = array_filter($semua_data, function($row) use ($search_lower) {
            return str_contains(strtolower($row['nama_plain']), $search_lower)
                || str_contains(strtolower($row['npk']), $search_lower);
        });
        $semua_data = array_values($semua_data);
    }

    // Pagination manual setelah filter PHP
    $total_data = count($semua_data);
    $total_page = max(1, ceil($total_data / $per_page));
    $page       = min($page, $total_page);
    $offset     = ($page - 1) * $per_page;
    $riwayat    = array_slice($semua_data, $offset, $per_page);
    
    // Ambil riwayat lengkap lapor diri untuk npk-npk di halaman ini (untuk modal Detail per Tahun)
    $npk_list_page  = array_unique(array_column($riwayat, 'npk'));
    $riwayat_detail = [];

    if (!empty($npk_list_page)) {
        $ph = implode(',', array_fill(0, count($npk_list_page), '?'));
        $stmt_detail = $db->prepare("
            SELECT id, npk, waktu, status_verifikasi
            FROM lapor_diri
            WHERE npk IN ($ph)
            ORDER BY waktu DESC
        ");
        $stmt_detail->execute(array_values($npk_list_page));
        foreach ($stmt_detail->fetchAll() as $d) {
            $tahun   = (int)date('Y', strtotime($d['waktu']));
            $kuartal = 'Q' . ceil(date('n', strtotime($d['waktu'])) / 3);

            // Data sudah urut DESC (terbaru dulu) — skip kalau kuartal ini sudah terisi
            if (isset($riwayat_detail[$d['npk']][$tahun][$kuartal])) {
                continue;
            }

            $fmt = formatTanggal($d['waktu']);
            $riwayat_detail[$d['npk']][$tahun][$kuartal] = [
                'id'      => (int)$d['id'],
                'tanggal' => $fmt['tanggal'],
                'jam'     => $fmt['jam'],
                'status'  => $d['status_verifikasi'],
            ];
        }
    }

    // Tahun yang ditampilkan di modal detail (sistem mulai 2026)
    $tahun_mulai_sistem  = 2026;
    $tahun_range_detail  = range((int)date('Y'), $tahun_mulai_sistem);

    // Catatan tolak preset
    $catatan_preset = [
        'Foto wajah tidak terlihat jelas',
        'KTP tidak terlihat atau tidak terbaca',
        'Foto terlalu gelap atau buram',
        'Wajah tidak menghadap kamera',
        'Foto diduga bukan live capture',
    ];

} catch (PDOException $e) {
    die("Gagal memuat data.");
}

$query_params = $_GET;
unset($query_params['page']);
$query_string = http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Riwayat Lapor Diri - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5"
              x-data="riwayatApp()">

            <?php if (isset($_GET['sukses'])): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded-r-xl font-medium">
                    <?= htmlspecialchars($_GET['sukses']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium">
                    <?= htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Riwayat Lapor Diri</h2>
                    <p class="text-slate-500 text-sm mt-1">Monitoring foto kepesertaan pensiunan.</p>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Tombol Export — di luar grid, di bawah filter -->
                    <?php
                    $export_params = http_build_query([
                        'search'  => $search,
                        'kuartal' => $filter_kuartal,
                        'tahun'   => $filter_tahun,
                        'status'  => $filter_status,
                    ]);
                    ?>
                    <a href="export_riwayat_lapor.php?<?= $export_params; ?>"
                    class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition-colors shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Export Excel (.xls)
                    </a>
                </div>
            </div>

            <!-- Filter -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">

                    <!-- Search NPK -->
                    <div class="col-span-2 relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                               placeholder="Cari NPK atau Nama..."
                               class="pl-9 pr-4 py-2 w-full border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Filter Kuartal -->
                    <select name="kuartal" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Kuartal</option>
                        <option value="1" <?= $filter_kuartal === '1' ? 'selected' : ''; ?>>Q1 (Jan–Mar)</option>
                        <option value="2" <?= $filter_kuartal === '2' ? 'selected' : ''; ?>>Q2 (Apr–Jun)</option>
                        <option value="3" <?= $filter_kuartal === '3' ? 'selected' : ''; ?>>Q3 (Jul–Sep)</option>
                        <option value="4" <?= $filter_kuartal === '4' ? 'selected' : ''; ?>>Q4 (Okt–Des)</option>
                    </select>

                    <!-- Filter Tahun -->
                    <select name="tahun" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Tahun</option>
                        <?php foreach ($tahun_list as $t): ?>
                            <option value="<?= $t; ?>" <?= $filter_tahun == $t ? 'selected' : ''; ?>>
                                <?= $t; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Filter Status -->
                    <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Status</option>
                        <option value="Menunggu Verifikasi" <?= $filter_status === 'Menunggu Verifikasi' ? 'selected' : ''; ?>>Menunggu Verifikasi</option>
                        <option value="Disetujui"           <?= $filter_status === 'Disetujui'           ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="Ditolak"             <?= $filter_status === 'Ditolak'             ? 'selected' : ''; ?>>Ditolak</option>
                    </select>

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Filter</button>
                        <a href="riwayat_absen.php" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg">Reset</a>
                    </div>
                    <!-- Tombol Export — di luar grid, di bawah filter -->
                    <?php
                    $export_params = http_build_query([
                        'search'  => $search,
                        'kuartal' => $filter_kuartal,
                        'tahun'   => $filter_tahun,
                        'status'  => $filter_status,
                    ]);
                    ?>

                </div>

                <!-- Per page -->
                <div class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                    <span>Tampilkan</span>
                    <select name="per_page" onchange="this.form.submit()"
                            class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ([25,50,100,250,500] as $n): ?>
                            <option value="<?= $n; ?>" <?= $per_page === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>baris — Total: <strong><?= number_format($total_data); ?></strong> data</span>
                </div>
                
            </form>

            <!-- Tabel -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-3 text-left font-semibold">Waktu Lapor</th>
                                <th class="px-4 py-3 text-left font-semibold">Identitas</th>
                                <th class="px-4 py-3 text-center font-semibold">Foto</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($riwayat)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-slate-400">
                                        Belum ada data lapor diri.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($riwayat as $row): ?>
                                <?php $status = $row['status_verifikasi']; ?>
                                <tr class="hover:bg-slate-50 transition-colors">

                                    <!-- Waktu -->
                                    <td class="px-4 py-3">
                                        <div class="flex items-start gap-2">
                                            <?php if ($status === 'Menunggu Verifikasi'): ?>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" title="Belum diproses">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                            </svg>
                                            <?php endif; ?>
                                            <div>
                                                <p class="font-bold text-slate-800">
                                                    <?= $row['kuartal_label']; ?> / <?= $row['tgl_fmt']; ?>
                                                </p>
                                                <p class="text-xs text-slate-400 mt-0.5"><?= $row['jam_fmt']; ?> WIB</p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Identitas -->
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= htmlspecialchars($row['nama_plain']); ?></p>
                                        <p class="text-xs text-slate-400 font-mono">NPK: <?= htmlspecialchars($row['npk']); ?></p>
                                    </td>

                                    <!-- Foto -->
                                    <td class="px-4 py-3 text-center">
                                        <button @click="bukaFoto('<?= $row['id']; ?>', '<?= htmlspecialchars($row['nama_plain'], ENT_QUOTES); ?>', '<?= $row['kuartal_label'] . ' / ' . $row['tgl_fmt']; ?>', '<?= $row['jam_fmt']; ?>')"
                                                class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-700 font-semibold bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            Lihat Foto
                                        </button>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-3">
                                        <?php
                                        // $status  = $row['status_verifikasi'];
                                        $badge   = match($status) {
                                            'Disetujui'           => 'bg-emerald-100 text-emerald-700',
                                            'Ditolak'             => 'bg-red-100 text-red-700',
                                            default               => 'bg-amber-100 text-amber-700 animate-pulse',
                                        };
                                        ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge; ?>">
                                            <?= $status; ?>
                                        </span>
                                        <?php if ($status === 'Ditolak' && !empty($row['catatan_tolak'])): ?>
                                            <p class="text-xs text-red-500 mt-1 max-w-xs"><?= htmlspecialchars($row['catatan_tolak']); ?></p>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-1">

                                            <!-- Detail per tahun -->
                                            <button @click="bukaDetailLapor('<?= $row['npk']; ?>', '<?= htmlspecialchars($row['nama_plain'], ENT_QUOTES); ?>')"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors" title="Detail Lapor Diri per Tahun">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </button>

                                            <!-- Tombol Setujui -->
                                            <form action="proses_verifikasi_absen.php" method="POST">
                                                <input type="hidden" name="id_lapor" value="<?= $row['id']; ?>">
                                                <input type="hidden" name="npk"      value="<?= $row['npk']; ?>">
                                                <input type="hidden" name="aksi"     value="Disetujui">
                                                <button type="submit"
                                                        <?= $status === 'Disetujui' ? 'disabled' : ''; ?>
                                                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors
                                                        <?= $status === 'Disetujui' ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 text-white'; ?>">
                                                    Setujui
                                                </button>
                                            </form>

                                            <!-- Tombol Tolak — buka modal -->
                                            <button @click="bukaTolak('<?= $row['id']; ?>', '<?= $row['npk']; ?>')"
                                                    <?= $status === 'Ditolak' ? 'disabled' : ''; ?>
                                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors
                                                    <?= $status === 'Ditolak' ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700 text-white'; ?>">
                                                Tolak
                                            </button>

                                        </div>
                                    </td>

                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_page > 1): ?>
                <div class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-600">
                    <p>Halaman <?= $page; ?> dari <?= $total_page; ?></p>
                    <div class="flex gap-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page-1; ?>&<?= $query_string; ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">← Prev</a>
                        <?php endif; ?>
                        <?php for ($i = max(1,$page-2); $i <= min($total_page,$page+2); $i++): ?>
                            <a href="?page=<?= $i; ?>&<?= $query_string; ?>"
                               class="px-3 py-1.5 rounded-lg border transition-colors <?= $i===$page ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 hover:bg-slate-50'; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_page): ?>
                            <a href="?page=<?= $page+1; ?>&<?= $query_string; ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Modal Detail Lapor Diri per Tahun -->
            <div x-show="modalDetailLapor" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-40 p-4 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl border border-slate-200 flex flex-col max-h-[90vh]">

                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl">
                        <div>
                            <h3 class="font-bold text-slate-800 text-lg">Detail Lapor Diri</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                <span x-text="detailNama"></span> — NPK: <span class="font-mono" x-text="detailNpk"></span>
                            </p>
                        </div>
                        <button @click="modalDetailLapor = false" class="p-2 rounded-full border border-slate-200 text-slate-400 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-6 overflow-y-auto space-y-5">
                        <template x-for="tahun in tahunRangeDetail" :key="tahun">
                            <div class="border border-slate-200 rounded-xl overflow-hidden">
                                <div class="bg-slate-50 px-4 py-2.5 border-b border-slate-200">
                                    <span class="font-bold text-slate-700 text-sm" x-text="tahun"></span>
                                </div>
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-xs uppercase text-slate-400">
                                            <th class="px-4 py-2 text-left font-semibold">Waktu</th>
                                            <th class="px-4 py-2 text-center font-semibold">Foto Wajah</th>
                                            <th class="px-4 py-2 text-left font-semibold">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <template x-for="q in ['Q1','Q2','Q3','Q4']" :key="q">
                                            <tr>
                                                <!-- Waktu -->
                                                <td class="px-4 py-2.5">
                                                    <p class="font-semibold text-slate-700">
                                                        <span x-text="q"></span> /
                                                        <span x-text="q==='Q1'?'Jan–Mar':q==='Q2'?'Apr–Jun':q==='Q3'?'Jul–Sep':'Okt–Des'"></span>
                                                    </p>
                                                    <template x-if="riwayatDetail[detailNpk]?.[tahun]?.[q]">
                                                        <p class="text-xs text-slate-400 mt-0.5">
                                                            <span x-text="riwayatDetail[detailNpk][tahun][q].tanggal"></span>
                                                            · <span x-text="riwayatDetail[detailNpk][tahun][q].jam"></span>
                                                        </p>
                                                    </template>
                                                    <template x-if="!riwayatDetail[detailNpk]?.[tahun]?.[q]">
                                                        <p class="text-xs text-slate-300 mt-0.5">—</p>
                                                    </template>
                                                </td>

                                                <!-- Foto Wajah -->
                                                <td class="px-4 py-2.5 text-center">
                                                    <template x-if="riwayatDetail[detailNpk]?.[tahun]?.[q]">
                                                        <button @click="bukaFoto(riwayatDetail[detailNpk][tahun][q].id, detailNama, q + ' / ' + tahun, riwayatDetail[detailNpk][tahun][q].jam)"
                                                                class="inline-flex items-center justify-center h-8 w-8 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-600 transition-colors">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            </svg>
                                                        </button>
                                                    </template>
                                                    <template x-if="!riwayatDetail[detailNpk]?.[tahun]?.[q]">
                                                        <span class="inline-flex items-center justify-center h-8 w-8 rounded-lg bg-slate-100 text-slate-300">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            </svg>
                                                        </span>
                                                    </template>
                                                </td>

                                                <!-- Status -->
                                                <td class="px-4 py-2.5">
                                                    <template x-if="riwayatDetail[detailNpk]?.[tahun]?.[q]?.status === 'Disetujui'">
                                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Disetujui</span>
                                                    </template>
                                                    <template x-if="riwayatDetail[detailNpk]?.[tahun]?.[q]?.status === 'Menunggu Verifikasi'">
                                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700">Menunggu</span>
                                                    </template>
                                                    <template x-if="riwayatDetail[detailNpk]?.[tahun]?.[q]?.status === 'Ditolak'">
                                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">Ditolak</span>
                                                    </template>
                                                    <template x-if="!riwayatDetail[detailNpk]?.[tahun]?.[q]">
                                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-400">Belum Lapor</span>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>

                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-end rounded-b-2xl">
                        <button @click="modalDetailLapor = false" class="bg-slate-700 hover:bg-slate-800 text-white px-5 py-2 rounded-lg text-sm font-medium">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Lihat Foto -->
            <div x-show="modalFoto" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                        <div>
                            <h3 class="font-bold text-slate-800" x-text="fotoNama"></h3>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <span x-text="fotoTgl"></span> &nbsp;|&nbsp; <span x-text="fotoJam"></span> WIB
                            </p>
                        </div>
                        <button @click="modalFoto = false" class="p-2 rounded-full border border-slate-200 text-slate-400 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="bg-slate-900 p-4 flex items-center justify-center min-h-[300px]">
                        <template x-if="fotoLoading">
                            <div class="flex flex-col items-center gap-3 text-white">
                                <svg class="animate-spin h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                                </svg>
                                <p class="text-sm">Mendekripsi foto...</p>
                            </div>
                        </template>
                        <img x-show="!fotoLoading" :src="fotoSrc"
                             class="max-w-full max-h-[60vh] object-contain rounded border border-slate-700 shadow-lg">
                    </div>
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center">
                        <a :href="'lihat_foto_lapor.php?id=' + fotoId + '&mode=download'"
                           class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Unduh Foto
                        </a>
                        <button @click="modalFoto = false" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2 rounded-lg text-sm font-medium">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Tolak -->
            <div x-show="modalTolak" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
                    <form action="proses_verifikasi_absen.php" method="POST">
                        <input type="hidden" name="id_lapor" :value="tolakId">
                        <input type="hidden" name="npk"      :value="tolakNpk">
                        <input type="hidden" name="aksi"     value="Ditolak">
                        <input type="hidden" name="catatan"  x-model="catatanFinal">

                        <h3 class="text-lg font-bold text-slate-800 mb-4">Tolak Lapor Diri</h3>

                        <div class="space-y-3">
                            <p class="text-sm font-semibold text-slate-600">Pilih alasan penolakan:</p>

                            <?php foreach ($catatan_preset as $i => $preset): ?>
                            <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition-colors"
                                   :class="catatanFinal === '<?= addslashes($preset); ?>' ? 'border-blue-500 bg-blue-50' : ''">
                                <input type="radio" name="catatan_radio" value="<?= htmlspecialchars($preset); ?>"
                                       @change="catatanFinal = $event.target.value; customCatatan = false"
                                       class="accent-blue-600">
                                <span class="text-sm text-slate-700"><?= htmlspecialchars($preset); ?></span>
                            </label>
                            <?php endforeach; ?>

                            <!-- Custom -->
                            <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition-colors"
                                   :class="customCatatan ? 'border-blue-500 bg-blue-50' : ''">
                                <input type="radio" name="catatan_radio" value="custom"
                                       @change="customCatatan = true; catatanFinal = ''"
                                       class="accent-blue-600">
                                <span class="text-sm text-slate-700">Tulis alasan sendiri...</span>
                            </label>

                            <textarea x-show="customCatatan"
                                      x-model="catatanFinal"
                                      placeholder="Tuliskan alasan penolakan..."
                                      rows="3"
                                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none mt-1"></textarea>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="button" @click="modalTolak = false"
                                    class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:bg-slate-50">
                                Batal
                            </button>
                            <button type="submit"
                                    :disabled="!catatanFinal"
                                    :class="!catatanFinal ? 'bg-slate-300 text-slate-500 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700 text-white'"
                                    class="flex-1 py-2.5 rounded-xl font-bold text-sm transition-colors">
                                Konfirmasi Tolak
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
function riwayatApp() {
    return {
        modalFoto:    false,
        modalTolak:   false,
        fotoId:       '',
        fotoSrc:      '',
        fotoNama:     '',
        fotoTgl:      '',
        fotoJam:      '',
        fotoLoading:  false,
        tolakId:      '',
        tolakNpk:     '',
        catatanFinal: '',
        customCatatan: false,
        riwayatDetail:    <?= json_encode($riwayat_detail); ?>,
        tahunRangeDetail: <?= json_encode($tahun_range_detail); ?>,
        modalDetailLapor: false,
        detailNpk:        '',
        detailNama:       '',

        bukaDetailLapor(npk, nama) {
            this.detailNpk  = npk;
            this.detailNama = nama;
            this.modalDetailLapor = true;
        },

        bukaFoto(id, nama, tgl, jam) {
            this.fotoId      = id;
            this.fotoNama    = nama;
            this.fotoTgl     = tgl;
            this.fotoJam     = jam;
            this.fotoSrc     = '';
            this.fotoLoading = true;
            this.modalFoto   = true;

            // Load foto via fetch ke endpoint dekripsi
            fetch('lihat_foto_lapor.php?id=' + id)
                .then(r => r.blob())
                .then(blob => {
                    this.fotoSrc     = URL.createObjectURL(blob);
                    this.fotoLoading = false;
                })
                .catch(() => {
                    this.fotoLoading = false;
                    this.fotoSrc     = '';
                });
        },

        bukaTolak(id, npk) {
            this.tolakId      = id;
            this.tolakNpk     = npk;
            this.catatanFinal = '';
            this.customCatatan = false;
            this.modalTolak   = true;
        },
    }
}
</script>

</body>
</html>