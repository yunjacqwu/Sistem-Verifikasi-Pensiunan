<?php
// file: admin/data_pensiunan.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

// Parameter filter dan pagination
$filter_jk      = $_GET['jk']       ?? '';
$filter_status  = $_GET['status']   ?? '';
$filter_berkas  = $_GET['berkas']   ?? '';
$filter_lapor   = $_GET['lapor']    ?? '';
$filter_mnft    = $_GET['mnft']     ?? '';
$umur_min       = $_GET['umur_min'] ?? '';
$umur_max       = $_GET['umur_max'] ?? '';
$search         = trim($_GET['search'] ?? '');
$per_page_raw   = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$per_page       = in_array($per_page_raw, [25,50,100,250,500]) ? $per_page_raw : 25;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $per_page;

try {
    // Ambil semua jenis manfaat unik
    $jns_mnft_list = $db->query("SELECT DISTINCT jns_mnft FROM pensiunan WHERE jns_mnft IS NOT NULL AND jns_mnft != '' ORDER BY jns_mnft")->fetchAll(PDO::FETCH_COLUMN);

    // Filter yang dijalankan di database (kelengkapan berkas, status lapor, jenis, status, umur, NPK)
    $where  = ["1=1"];
    $params = [];

    if (!empty($filter_jk)) {
        $where[]       = "p.jk = :jk";
        $params['jk']  = $filter_jk;
    }
    if (!empty($filter_status)) {
        $where[]           = "p.status = :status";
        $params['status']  = $filter_status;
    }
    if (!empty($filter_mnft)) {
        $where[]        = "p.jns_mnft = :mnft";
        $params['mnft'] = $filter_mnft;
    }
    if ($umur_min !== '') {
        $where[]            = "TIMESTAMPDIFF(YEAR, p.tgl_lhr, CURDATE()) >= :umur_min";
        $params['umur_min'] = (int)$umur_min;
    }
    if ($umur_max !== '') {
        $where[]            = "TIMESTAMPDIFF(YEAR, p.tgl_lhr, CURDATE()) <= :umur_max";
        $params['umur_max'] = (int)$umur_max;
    }

    // Potongan SQL untuk kelengkapan berkas dan status lapor kuartal ini
    $ada_dok  = function ($jns) { return "EXISTS (SELECT 1 FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = '$jns')"; };
    $dok_st   = function ($jns, $st) { return "EXISTS (SELECT 1 FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = '$jns' AND d.status = '$st')"; };
    $lapor_st = function ($st) { return "EXISTS (SELECT 1 FROM lapor_diri l WHERE l.npk = p.npk AND l.status_verifikasi = '$st' AND QUARTER(l.waktu) = QUARTER(CURRENT_DATE()) AND YEAR(l.waktu) = YEAR(CURRENT_DATE()))"; };
    $dok_lengkap = "(" . $ada_dok('KTP') . " AND " . $ada_dok('KK') . ")";
    $dok_disetuj = "(" . $dok_st('KTP', 'Disetujui') . " AND " . $dok_st('KK', 'Disetujui') . ")";
    $dok_tolak   = "(" . $dok_st('KTP', 'Ditolak') . " OR " . $dok_st('KK', 'Ditolak') . ")";

    switch ($filter_berkas) {
        case 'lengkap':       $where[] = $dok_lengkap; break;
        case 'belum':         $where[] = "(NOT " . $ada_dok('KTP') . " AND NOT " . $ada_dok('KK') . ")"; break;
        case 'hanya_ktp':     $where[] = "(" . $ada_dok('KTP') . " AND NOT " . $ada_dok('KK') . ")"; break;
        case 'hanya_kk':      $where[] = "(NOT " . $ada_dok('KTP') . " AND " . $ada_dok('KK') . ")"; break;
        case 'tidak_lengkap': $where[] = "(NOT " . $dok_lengkap . ")"; break;
        case 'disetujui':     $where[] = $dok_disetuj; break;
        case 'ditolak':       $where[] = "($dok_lengkap AND NOT $dok_disetuj AND $dok_tolak)"; break;
        case 'menunggu':      $where[] = "($dok_lengkap AND NOT $dok_disetuj AND NOT $dok_tolak)"; break;
    }

    switch ($filter_lapor) {
        case 'disetujui': $where[] = "(p.status = 'Aktif' AND " . $lapor_st('Disetujui') . ")"; break;
        case 'menunggu':  $where[] = "(p.status = 'Aktif' AND " . $lapor_st('Menunggu Verifikasi') . " AND NOT " . $lapor_st('Disetujui') . ")"; break;
        case 'belum':     $where[] = "(p.status = 'Aktif' AND NOT " . $lapor_st('Menunggu Verifikasi') . " AND NOT " . $lapor_st('Disetujui') . ")"; break;
    }

    // Pencarian: NPK dicocokkan di SQL. Nama terenkripsi, jadi hanya kolom nama yang didekripsi,
    // dan hanya saat kolom pencarian diisi.
    if ($search !== '') {
        $kandidat = $db->prepare("SELECT p.npk, p.nama FROM pensiunan p WHERE " . implode(' AND ', $where));
        foreach ($params as $k => $v) {
            $kandidat->bindValue(':' . $k, $v);
        }
        $kandidat->execute();
        $cari = strtolower($search);
        $npk_cocok = [];
        foreach ($kandidat->fetchAll() as $k) {
            $nama = strtolower((string)dekripsi($k['nama'], $k['npk']));
            if (str_contains($nama, $cari) || str_contains(strtolower($k['npk']), $cari)) {
                $npk_cocok[] = $k['npk'];
            }
        }
        if (empty($npk_cocok)) {
            $where[] = "1=0";
        } else {
            $nama_param = [];
            foreach ($npk_cocok as $i => $npk_c) {
                $params['npk' . $i] = $npk_c;
                $nama_param[] = ':npk' . $i;
            }
            $where[] = "p.npk IN (" . implode(',', $nama_param) . ")";
        }
    }

    $where_sql = implode(' AND ', $where);

    // Jumlah total dan paginasi dilakukan di database
    $stmt_count = $db->prepare("SELECT COUNT(*) FROM pensiunan p WHERE $where_sql");
    foreach ($params as $k => $v) {
        $stmt_count->bindValue(':' . $k, $v);
    }
    $stmt_count->execute();
    $total_data = (int)$stmt_count->fetchColumn();
    $total_page = max(1, (int)ceil($total_data / $per_page));
    $page       = min($page, $total_page);
    $offset     = ($page - 1) * $per_page;

    // Hanya baris halaman ini yang diambil dan didekripsi
    $stmt_all = $db->prepare("
        SELECT p.*,
            TIMESTAMPDIFF(YEAR, p.tgl_lhr, CURDATE()) AS umur,
            (SELECT COUNT(*) FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KTP') AS jml_ktp,
            (SELECT COUNT(*) FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KK') AS jml_kk,
            (SELECT d.status FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KTP' LIMIT 1) AS ktp_status,
            (SELECT d.status FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KK' LIMIT 1) AS kk_status,
            (SELECT l.status_verifikasi FROM lapor_diri l
                WHERE l.npk = p.npk AND l.status_verifikasi IN ('Menunggu Verifikasi','Disetujui')
                AND QUARTER(l.waktu) = QUARTER(CURRENT_DATE()) AND YEAR(l.waktu) = YEAR(CURRENT_DATE())
                ORDER BY (l.status_verifikasi = 'Disetujui') DESC LIMIT 1) AS lapor_status
        FROM pensiunan p
        WHERE $where_sql
        ORDER BY CASE WHEN p.status = 'Aktif' THEN 0 ELSE 1 END ASC, p.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) {
        $stmt_all->bindValue(':' . $k, $v);
    }
    $stmt_all->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt_all->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt_all->execute();
    $daftar = $stmt_all->fetchAll();

    // Status lapor kuartal ini untuk baris halaman ini (dipakai kolom Status Lapor)
    $lapor_kuartal = [];
    foreach ($daftar as $r) {
        if (!empty($r['lapor_status'])) {
            $lapor_kuartal[$r['npk']] = $r['lapor_status'];
        }
    }

    // Dokumen (id dan status) untuk baris halaman ini, dipakai preview di modal
    $npk_page = array_column($daftar, 'npk');
    $dokumen_map_page = [];
    if (!empty($npk_page)) {
        $ph = implode(',', array_fill(0, count($npk_page), '?'));
        $stmt_dok_map = $db->prepare("SELECT npk, jns_dok, id, status FROM dokumen WHERE npk IN ($ph)");
        $stmt_dok_map->execute($npk_page);
        foreach ($stmt_dok_map->fetchAll() as $d) {
            $dokumen_map_page[$d['npk']][$d['jns_dok']] = ['id' => (int)$d['id'], 'status' => $d['status']];
        }
    }

    foreach ($daftar as &$row) {
        $row['nama_plain']     = dekripsi($row['nama'], $row['npk']);
        $row['telp_plain']     = dekripsi($row['telp'], $row['npk']);
        $row['telp2_plain']    = dekripsi($row['telp2'], $row['npk']);
        $row['nik_plain']      = dekripsi($row['nik'], $row['npk']);
        $row['alamat_plain']   = dekripsi($row['alamat'], $row['npk']);
        $row['tmpt_lhr_plain'] = dekripsi($row['tmpt_lhr'], $row['npk']);
        // Tambahkan di dalam foreach setelah baris dekripsi nama_plain
        $row['nama_asli_plain'] = !empty($row['nama_asli'])
            ? dekripsi($row['nama_asli'], $row['npk'])
            : '';
        $row['jns_penerima'] = $row['jns_penerima'] ?? 'Pensiunan Asli';
        $row['tgl_lhr_fmt']    = $row['tgl_lhr'] ? date('j', strtotime($row['tgl_lhr'])) . ' ' . ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n', strtotime($row['tgl_lhr']))] . ' ' . date('Y', strtotime($row['tgl_lhr'])) : '-';
        $row['tgl_msk_fmt']    = $row['tgl_msk'] ? (function($d){ $b=['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; return date('j', strtotime($d)).' '.$b[(int)date('n',strtotime($d))].' '.date('Y',strtotime($d)); })($row['tgl_msk']) : '-';
        $row['tgl_psn_fmt']    = $row['tgl_psn'] ? (function($d){ $b=['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; return date('j', strtotime($d)).' '.$b[(int)date('n',strtotime($d))].' '.date('Y',strtotime($d)); })($row['tgl_psn']) : '-';
        $row['jk_label']       = $row['jk'] === 'L' ? 'Laki-laki' : ($row['jk'] === 'P' ? 'Perempuan' : '-');
        $ktp = (int)$row['jml_ktp'];
        $kk  = (int)$row['jml_kk'];

        if ($ktp === 0 && $kk === 0) {
            $row['status_berkas']       = 'Belum Ada';
            $row['status_berkas_class'] = 'bg-red-100 text-red-700';
        } elseif ($ktp > 0 && $kk === 0) {
            $row['status_berkas']       = 'Hanya KTP';
            $row['status_berkas_class'] = 'bg-amber-100 text-amber-800';
        } elseif ($ktp === 0 && $kk > 0) {
            $row['status_berkas']       = 'Hanya KK';
            $row['status_berkas_class'] = 'bg-amber-100 text-amber-800';
        } elseif (($row['ktp_status'] ?? '') === 'Disetujui' && ($row['kk_status'] ?? '') === 'Disetujui') {
            $row['status_berkas']       = 'Lengkap & Disetujui';
            $row['status_berkas_class'] = 'bg-emerald-100 text-emerald-700';
        } elseif (($row['ktp_status'] ?? '') === 'Ditolak' || ($row['kk_status'] ?? '') === 'Ditolak') {
            $row['status_berkas']       = 'Ada yang Ditolak';
            $row['status_berkas_class'] = 'bg-red-100 text-red-700';
        } else {
            $row['status_berkas']       = 'Menunggu Verifikasi';
            $row['status_berkas_class'] = 'bg-amber-100 text-amber-800';
        }
    }
    unset($row);

    // Ambil riwayat lapor diri semua pensiunan yang ditampilkan
    $npk_list = array_column($daftar, 'npk');
    $riwayat_lapor = [];

    if (!empty($npk_list)) {
        $placeholders = implode(',', array_fill(0, count($npk_list), '?'));
        $stmt_lapor = $db->prepare("
            SELECT npk, waktu, status_verifikasi
            FROM lapor_diri
            WHERE npk IN ($placeholders)
            ORDER BY waktu DESC
        ");
        $stmt_lapor->execute($npk_list);
        foreach ($stmt_lapor->fetchAll() as $lapor) {
            $tahun   = date('Y', strtotime($lapor['waktu']));
            $kuartal = 'Q' . ceil(date('n', strtotime($lapor['waktu'])) / 3);

            // Data sudah urut DESC (terbaru dulu) — skip kalau kuartal ini sudah terisi
            if (isset($riwayat_lapor[$lapor['npk']][$tahun][$kuartal])) {
                continue;
            }

            $riwayat_lapor[$lapor['npk']][$tahun][$kuartal] = $lapor['status_verifikasi'];
        }
    }

// Tahun mulai sistem berjalan
$tahun_mulai_sistem = 2026;
$tahun_sekarang     = (int)date('Y');

// Buat range dari tahun sekarang turun ke tahun mulai sistem
$tahun_range = range($tahun_sekarang, $tahun_mulai_sistem);
} catch (PDOException $e) {
    die("Gagal memuat data.");
}

// Bangun query string untuk pagination
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
    <title>Data Pensiunan - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="/absensi_pensiunan_dev/static/vendor/alpine-3.14.1.min.js" integrity="sha384-l8f0VcPi/M1iHPv8egOnY/15TDwqgbOR1anMIJWvU6nLRgZVLTLSaNqi/TOoT5Fh"></script>
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/css/fonts.css">
    <script defer src="/absensi_pensiunan_dev/static/js/modal-focus.js"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5"
              x-data="pensiunanApp()">

            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium">
                    <?= htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['sukses'])): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded-r-xl font-medium">
                    <?= htmlspecialchars($_GET['sukses']); ?>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">Data Pensiunan</h2>
                    <p class="text-slate-500 text-sm mt-1">Kelola data identitas dan akses akun pensiunan.</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php
                    $export_params = $_GET;
                    unset($export_params['page'], $export_params['per_page']);
                    $export_query = http_build_query($export_params);
                    ?>
                    <a href="export_excel.php?<?= $export_query; ?>"
                       class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition-colors shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export Excel (.xls)
                    </a>
                    <button @click="modalTambah = true"
                            class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition-colors shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Tambah Pensiunan
                    </button>
                </div>
            </div>

            <!-- Filter -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">

                    <!-- Umur -->
                    <div class="col-span-2 md:col-span-1 flex gap-2 items-center">
                        <input type="number" name="umur_min" value="<?= htmlspecialchars($umur_min); ?>"
                               placeholder="Umur min" min="0" max="120"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">
                        <span class="text-slate-500 text-sm">–</span>
                        <input type="number" name="umur_max" value="<?= htmlspecialchars($umur_max); ?>"
                               placeholder="Umur max" min="0" max="120"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Jenis Kelamin -->
                    <select name="jk" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Jenis Kelamin</option>
                        <option value="L" <?= $filter_jk === 'L' ? 'selected' : ''; ?>>Laki-laki</option>
                        <option value="P" <?= $filter_jk === 'P' ? 'selected' : ''; ?>>Perempuan</option>
                    </select>

                    <!-- Jenis Manfaat -->
                    <select name="mnft" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Jenis Pensiunan</option>
                        <?php foreach ($jns_mnft_list as $mnft): ?>
                            <option value="<?= htmlspecialchars($mnft); ?>" <?= $filter_mnft === $mnft ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($mnft); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Status Lapor Kuartal Ini -->
                    <select name="lapor" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Status Lapor</option>
                        <option value="disetujui" <?= $filter_lapor === 'disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="menunggu" <?= $filter_lapor === 'menunggu' ? 'selected' : ''; ?>>Menunggu Verifikasi</option>
                        <option value="belum" <?= $filter_lapor === 'belum' ? 'selected' : ''; ?>>Belum Lapor</option>
                    </select>

                    <!-- Kelengkapan Berkas -->
                    <select name="berkas" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Kelengkapan Berkas</option>
                        <option value="lengkap"   <?= $filter_berkas === 'lengkap'   ? 'selected' : ''; ?>>Lengkap (KTP & KK)</option>
                        <option value="belum"     <?= $filter_berkas === 'belum'     ? 'selected' : ''; ?>>Belum Ada Dokumen</option>
                        <option value="hanya_ktp" <?= $filter_berkas === 'hanya_ktp' ? 'selected' : ''; ?>>Hanya KTP</option>
                        <option value="hanya_kk"  <?= $filter_berkas === 'hanya_kk'  ? 'selected' : ''; ?>>Hanya KK</option>
                        <option value="tidak_lengkap" <?= $filter_berkas === 'tidak_lengkap' ? 'selected' : ''; ?>>Belum Lengkap (KTP atau KK kurang)</option>
                        <option value="menunggu"  <?= $filter_berkas === 'menunggu'  ? 'selected' : ''; ?>>Menunggu Verifikasi</option>
                        <option value="ditolak"   <?= $filter_berkas === 'ditolak'   ? 'selected' : ''; ?>>Ada yang Ditolak</option>
                        <option value="disetujui" <?= $filter_berkas === 'disetujui' ? 'selected' : ''; ?>>Lengkap & Disetujui</option>
                    </select>

                    <!-- Tombol filter -->
                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors">
                            Filter
                        </button>
                        <a href="data_pensiunan.php"
                           class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition-colors">
                            Reset
                        </a>
                    </div>

                </div>

                <!-- Search -->
                <div class="mt-3 flex gap-3 items-center">
                    <div class="relative flex-1 max-w-md">
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                               placeholder="Cari NPK atau Nama..."
                               class="pl-10 pr-4 py-2 w-full border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-500 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <!-- Jumlah baris -->
                    <div class="flex items-center gap-2 text-sm text-slate-600">
                        <span>Tampilkan</span>
                        <select name="per_page" onchange="this.form.submit()"
                                class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ([25,50,100,250,500] as $n): ?>
                                <option value="<?= $n; ?>" <?= $per_page === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span>baris — Total: <strong><?= number_format($total_data); ?></strong> data</span>
                    </div>
                </div>
            </form>

            <!-- Tabel -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-3 text-left font-semibold">NPK / Nama</th>
                                <th class="px-4 py-3 text-left font-semibold">Umur</th>
                                <th class="px-4 py-3 text-left font-semibold">Jenis Pensiunan</th>
                                <th class="px-4 py-3 text-left font-semibold">Status Lapor</th>
                                <th class="px-4 py-3 text-left font-semibold">Kelengkapan Berkas</th>
                                <th class="px-4 py-3 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($daftar)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-10 text-center text-slate-500">
                                        Tidak ada data yang sesuai filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($daftar as $row): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= htmlspecialchars($row['nama_plain']); ?></p>
                                        <p class="text-xs text-slate-500 font-mono">NPK: <?= htmlspecialchars($row['npk']); ?></p>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">
                                        <?= $row['umur'] !== null ? $row['umur'] . ' thn' : '-'; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">
                                        <?= htmlspecialchars($row['jns_mnft'] ?: '-'); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($row['status'] !== 'Aktif'): ?>
                                            <span class="bg-slate-100 text-slate-600 px-2.5 py-1 rounded-full text-xs font-bold">Tidak Aktif</span>
                                        <?php elseif (($lapor_kuartal[$row['npk']] ?? '') === 'Disetujui'): ?>
                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Disetujui</span>
                                        <?php elseif (isset($lapor_kuartal[$row['npk']])): ?>
                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">Menunggu Verifikasi</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">Belum Lapor</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $row['status_berkas_class']; ?>">
                                            <?= $row['status_berkas']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1">

                                            <!-- Detail -->
                                            <button @click="bukaDetail(<?= htmlspecialchars(json_encode($row['npk']), ENT_QUOTES); ?>)"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors" title="Detail">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </button>

                                            <!-- Reset PIN -->
                                            <button @click="bukaResetPin(<?= htmlspecialchars(json_encode($row['npk'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>)"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Reset PIN">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                                </svg>
                                            </button>

                                            <!-- Nonaktifkan -->
                                            <button @click="bukaNonaktif(<?= htmlspecialchars(json_encode($row['npk'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?>,'<?= $row['status']; ?>')"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                    title="<?= $row['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                </svg>
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
                    <p>Halaman <?= $page; ?> dari <?= $total_page; ?> (<?= number_format($total_data); ?> data)</p>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page-1; ?>&<?= $query_string; ?>"
                               class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 transition-colors">← Prev</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($total_page, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?page=<?= $i; ?>&<?= $query_string; ?>"
                               class="px-3 py-1.5 rounded-lg border transition-colors <?= $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 hover:bg-slate-50'; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_page): ?>
                            <a href="?page=<?= $page+1; ?>&<?= $query_string; ?>"
                               class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 transition-colors">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Modal Detail -->
            <div x-show="modalDetail" @keydown.escape.window="modalDetail = false; editMode = false" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl flex flex-col max-h-[90vh] border border-slate-200">

                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl">
                        <div>
                            <h3 class="font-bold text-slate-800 text-xl">Profil Pensiunan</h3>
                            <p class="text-sm text-slate-500 font-mono" x-text="'NPK: ' + aktif.npk"></p>
                        </div>
                        <button @click="modalDetail = false"
                                class="p-2 rounded-full border border-slate-200 text-slate-500 hover:text-red-500 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Tab -->
                    <div class="flex border-b border-slate-200 bg-slate-50 px-6 gap-1">
                        <button @click="tab = 'identitas'"
                                :class="tab==='identitas' ? 'border-blue-600 text-blue-600 font-bold' : 'border-transparent text-slate-500'"
                                class="py-3 px-4 border-b-2 text-sm transition-all">
                            Data Diri
                        </button>
                        <button @click="tab = 'berkas'"
                                :class="tab==='berkas' ? 'border-blue-600 text-blue-600 font-bold' : 'border-transparent text-slate-500'"
                                class="py-3 px-4 border-b-2 text-sm transition-all">
                            Berkas (KTP/KK)
                        </button>
                        <button @click="tab = 'riwayat'"
                                :class="tab==='riwayat' ? 'border-blue-600 text-blue-600 font-bold' : 'border-transparent text-slate-500'"
                                class="py-3 px-4 border-b-2 text-sm transition-all">
                            Riwayat Lapor Diri
                        </button>
                    </div>

                    <div class="p-6 overflow-y-auto flex-1">

                        <!-- Tab Identitas -->
                        <div x-show="tab === 'identitas'">
                            <form action="proses_edit_pensiunan.php" method="POST" class="space-y-5">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="npk" :value="aktif.npk">

                                <div class="flex justify-end">
                                    <button type="button" @click="editMode = !editMode"
                                            :class="editMode ? 'bg-amber-500 hover:bg-amber-600' : 'bg-slate-600 hover:bg-slate-700'"
                                            class="text-white text-xs font-semibold px-4 py-2 rounded-lg transition-colors flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        <span x-text="editMode ? 'Batal Edit' : 'Edit Data'"></span>
                                    </button>
                                </div>

                                <!-- Identitas -->
                                <div>
                                    <h4 class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-3">Identitas</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-100">
                                        <?php
                                        $fields_identitas = [
                                            ['label'=>'Nama Lengkap', 'key'=>'nama_plain', 'name'=>'nama_lengkap', 'type'=>'text', 'required'=>true],
                                            ['label'=>'NIK', 'key'=>'nik_plain', 'name'=>'nik', 'type'=>'text'],
                                            ['label'=>'Tempat Lahir', 'key'=>'tmpt_lhr_plain', 'name'=>'tempat_lahir', 'type'=>'text'],
                                            ['label'=>'Tanggal Lahir', 'key'=>'tgl_lhr_fmt', 'name'=>'tanggal_lahir', 'type'=>'date', 'raw_key'=>'tgl_lhr'],
                                            ['label'=>'Jenis Kelamin', 'key'=>'jk_label', 'name'=>'jenis_kelamin', 'type'=>'select_jk', 'raw_key'=>'jk'],
                                            ['label'=>'No. Telepon Utama', 'key'=>'telp_plain', 'name'=>'no_telp', 'type'=>'tel', 'required'=>true],
                                            ['label'=>'No. Telepon Cadangan', 'key'=>'telp2_plain', 'name'=>'no_telp2', 'type'=>'tel'],
                                        ];
                                        ?>
                                        <?php foreach ($fields_identitas as $f): ?>
                                        <div <?= ($f['name'] === 'nama_lengkap' || $f['name'] === 'alamat') ? 'class="md:col-span-2"' : ''; ?>>
                                            <label class="text-xs text-slate-500 font-semibold block mb-1"><?= $f['label']; ?></label>
                                            <span x-show="!editMode" x-text="aktif.<?= $f['key']; ?> || '—'" class="text-slate-800 block py-1 text-sm"></span>
                                            <?php if ($f['type'] === 'select_jk'): ?>
                                                <select x-show="editMode" name="<?= $f['name']; ?>" class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                                    <option value="">— Pilih —</option>
                                                    <option value="L" :selected="aktif.jk === 'L'">Laki-laki</option>
                                                    <option value="P" :selected="aktif.jk === 'P'">Perempuan</option>
                                                </select>
                                            <?php else: ?>
                                                <input x-show="editMode" type="<?= $f['type']; ?>" name="<?= $f['name']; ?>"
                                                       :value="aktif.<?= isset($f['raw_key']) ? $f['raw_key'] : $f['key']; ?>"
                                                       <?= !empty($f['required']) ? 'required' : ''; ?>
                                                       class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="md:col-span-2">
                                            <label class="text-xs text-slate-500 font-semibold block mb-1">Alamat</label>
                                            <span x-show="!editMode" x-text="aktif.alamat_plain || '—'" class="text-slate-800 block py-1 text-sm"></span>
                                            <textarea x-show="editMode" name="alamat" rows="2"
                                                      class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white"
                                                      x-text="aktif.alamat_plain"></textarea>
                                        </div>
                                        <!-- Nama Pensiunan Asli — hanya tampil jika sudah pernah alih status -->
                                        <template x-if="aktif.jns_penerima && aktif.jns_penerima !== 'Pensiunan Asli'">
                                            <div class="md:col-span-2">
                                                <label class="text-xs text-slate-500 font-semibold block mb-1">
                                                    Nama Pensiunan Asli
                                                </label>
                                                <div class="flex items-center gap-2">
                                                    <span x-text="aktif.nama_penerima_asli || '—'"
                                                        class="text-slate-800 block py-1 text-sm flex-1"></span>
                                                    <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-semibold">
                                                        Read-only
                                                    </span>
                                                </div>
                                                <p class="text-xs text-slate-500 mt-0.5">
                                                    Nama pensiunan asli sebelum alih status ke
                                                    <span class="font-semibold" x-text="aktif.jns_penerima"></span>
                                                </p>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <!-- Kepensiunan -->
                                <div>
                                    <h4 class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-3">Kepensiunan</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-100">
                                        <div>
                                            <label class="text-xs text-slate-500 font-semibold block mb-1">Tanggal Masuk</label>
                                            <span x-show="!editMode" x-text="aktif.tgl_msk_fmt || '—'" class="text-slate-800 block py-1 text-sm"></span>
                                            <input x-show="editMode" type="date" name="tanggal_masuk" :value="aktif.tgl_msk"
                                                   class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                        </div>
                                        <div>
                                            <label class="text-xs text-slate-500 font-semibold block mb-1">Tanggal Pensiun</label>
                                            <span x-show="!editMode" x-text="aktif.tgl_psn_fmt || '—'" class="text-slate-800 block py-1 text-sm"></span>
                                            <input x-show="editMode" type="date" name="tanggal_pensiun" :value="aktif.tgl_psn"
                                                   class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                        </div>
                                        <div>
                                            <label class="text-xs text-slate-500 font-semibold block mb-1">Jenis Manfaat</label>
                                            <span x-show="!editMode" x-text="aktif.jns_mnft || '—'" class="text-slate-800 block py-1 text-sm"></span>
                                            <input x-show="editMode" type="text" name="jenis_manfaat_pensiun" :value="aktif.jns_mnft"
                                                   class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                        </div>
                                        <div>
                                            <label class="text-xs text-slate-500 font-semibold block mb-1">Status</label>
                                            <span x-show="!editMode" x-text="aktif.status" class="font-bold text-blue-700 block py-1 text-sm"></span>
                                            <select x-show="editMode" name="status_pensiun" required
                                                    class="w-full px-3 py-1.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                                                <option value="Aktif"       :selected="aktif.status === 'Aktif'">Aktif</option>
                                                <option value="Tidak Aktif" :selected="aktif.status === 'Tidak Aktif'">Tidak Aktif</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div x-show="editMode" class="flex justify-end">
                                    <button type="submit"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm py-2.5 px-6 rounded-xl shadow-md flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Simpan Perubahan
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Tab Berkas -->
                        <div x-show="tab === 'berkas'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <template x-for="jenis in ['KTP','KK']" :key="jenis">
                                <div class="border border-slate-200 rounded-xl p-4 flex flex-col items-center bg-slate-50">
                                    <p class="text-sm font-bold text-slate-700 mb-3"
                                    x-text="jenis === 'KTP' ? 'Kartu Tanda Penduduk' : 'Kartu Keluarga'"></p>

                                    <template x-if="dokumenMap[aktif.npk]?.[jenis]">
                                        <div class="w-full flex flex-col items-center gap-3">
                                            <div class="w-full h-32 flex flex-col items-center justify-center border border-slate-200 rounded-lg bg-white text-slate-500">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                                </svg>
                                                <p class="text-xs text-center">Terenkripsi</p>
                                            </div>

                                            <span class="text-xs font-bold px-3 py-1 rounded-full"
                                                :class="{
                                                    'bg-emerald-100 text-emerald-700': dokumenMap[aktif.npk][jenis].status === 'Disetujui',
                                                    'bg-red-100 text-red-700': dokumenMap[aktif.npk][jenis].status === 'Ditolak',
                                                    'bg-amber-100 text-amber-800': dokumenMap[aktif.npk][jenis].status === 'Menunggu Verifikasi'
                                                }"
                                                x-text="dokumenMap[aktif.npk][jenis].status"></span>

                                            <button @click="bukaDokumen(dokumenMap[aktif.npk][jenis].id, aktif.nama_plain, jenis)"
                                                    class="text-xs bg-blue-100 text-blue-700 font-bold px-3 py-1.5 rounded-full border border-blue-200 hover:bg-blue-200 transition-colors">
                                                Lihat Dokumen
                                            </button>
                                        </div>
                                    </template>

                                    <template x-if="!dokumenMap[aktif.npk]?.[jenis]">
                                        <div class="w-full flex flex-col items-center gap-3">
                                            <div class="w-full h-32 flex flex-col items-center justify-center border border-dashed border-slate-300 rounded-lg bg-white text-slate-500">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-slate-300 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                                <p class="text-xs text-center">Belum diunggah</p>
                                            </div>
                                            <span class="text-xs font-bold px-3 py-1 rounded-full bg-slate-100 text-slate-600">Belum Ada</span>
                                        </div>
                                    </template>

                                </div>
                            </template>
                        </div>

                        <!-- Tab Riwayat Lapor Diri -->
                        <div x-show="tab === 'riwayat'">

                            <div class="space-y-4">
                                <template x-for="tahun in tahunRange" :key="tahun">
                                    <div class="border border-slate-200 rounded-xl overflow-hidden">

                                        <!-- Header tahun -->
                                        <div class="bg-slate-50 px-4 py-2.5 border-b border-slate-200 flex items-center justify-between">
                                            <span class="font-bold text-slate-700 text-sm" x-text="tahun"></span>
                                            <span class="text-xs text-slate-500">
                                                <span x-text="Object.keys(riwayatLapor[aktif.npk]?.[tahun] || {}).length"></span>/4 laporan
                                            </span>
                                        </div>

                                        <!-- Grid 4 kuartal -->
                                        <div class="grid grid-cols-4 divide-x divide-slate-200">
                                            <template x-for="q in ['Q1','Q2','Q3','Q4']" :key="q">
                                                <div class="p-3 flex flex-col items-center gap-2">

                                                    <span class="text-xs font-bold text-slate-500" x-text="q"></span>
                                                    <span class="text-xs text-slate-500 text-center leading-tight"
                                                        x-text="q === 'Q1' ? 'Jan–Mar' : q === 'Q2' ? 'Apr–Jun' : q === 'Q3' ? 'Jul–Sep' : 'Okt–Des'">
                                                    </span>

                                                    <template x-if="riwayatLapor[aktif.npk]?.[tahun]?.[q] === 'Disetujui'">
                                                        <div class="flex flex-col items-center gap-1">
                                                            <div class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-emerald-600" viewBox="0 0 20 20" fill="currentColor">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                                                </svg>
                                                            </div>
                                                            <span class="text-xs font-bold text-emerald-600">Disetujui</span>
                                                        </div>
                                                    </template>

                                                    <template x-if="riwayatLapor[aktif.npk]?.[tahun]?.[q] === 'Menunggu Verifikasi'">
                                                        <div class="flex flex-col items-center gap-1">
                                                            <div class="h-8 w-8 rounded-full bg-amber-100 flex items-center justify-center">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                                                </svg>
                                                            </div>
                                                            <span class="text-xs font-bold text-amber-500">Menunggu</span>
                                                        </div>
                                                    </template>

                                                    <template x-if="riwayatLapor[aktif.npk]?.[tahun]?.[q] === 'Ditolak'">
                                                        <div class="flex flex-col items-center gap-1">
                                                            <div class="h-8 w-8 rounded-full bg-red-100 flex items-center justify-center">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                                                </svg>
                                                            </div>
                                                            <span class="text-xs font-bold text-red-500">Ditolak</span>
                                                        </div>
                                                    </template>

                                                    <template x-if="!riwayatLapor[aktif.npk]?.[tahun]?.[q]">
                                                        <div class="flex flex-col items-center gap-1">
                                                            <div class="h-8 w-8 rounded-full bg-slate-100 flex items-center justify-center">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                                                </svg>
                                                            </div>
                                                            <span class="text-xs text-slate-300">Belum</span>
                                                        </div>
                                                    </template>

                                                </div>
                                            </template>
                                        </div>

                                    </div>
                                </template>
                            </div>

                        </div>

                    </div>

                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center rounded-b-2xl">
                        <!-- Tombol Alih Status — hanya tampil di tab identitas -->
                        <button x-show="tab === 'identitas'"
                                @click="bukaAlihStatus(aktif.npk, aktif.nama_plain)"
                                class="bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold px-4 py-2 rounded-lg flex items-center gap-2 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            Alih Status Penerima
                        </button>
                        <div x-show="tab !== 'identitas'"></div>
                        <button @click="modalDetail = false; editMode = false"
                                class="bg-slate-700 hover:bg-slate-800 text-white px-5 py-2 rounded-lg font-medium text-sm">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Alih Status Penerima -->
            <div x-show="modalAlihStatus" @keydown.escape.window="modalAlihStatus = false" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-[60] p-4 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200 flex flex-col max-h-[90vh]">

                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl">
                        <div>
                            <h3 class="font-bold text-slate-800 text-lg">Alih Status Penerima</h3>
                            <p class="text-xs text-slate-500 font-mono mt-0.5" x-text="'NPK: ' + alihNpk + ' — ' + alihNama"></p>
                        </div>
                        <button @click="modalAlihStatus = false" class="p-2 rounded-full border border-slate-200 text-slate-500 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="proses_alih_status.php" method="POST" class="p-6 overflow-y-auto space-y-4">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="npk" :value="alihNpk">

                        <!-- Info -->
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm text-amber-700">
                            <p class="font-semibold mb-1">⚠️ Perhatian</p>
                            <p>Data pensiunan asli (nama, tanggal lahir, dll) akan digantikan dengan data penerima baru. Nama pensiunan asli akan disimpan sebagai catatan histori.</p>
                        </div>

                        <!-- Jenis Penerima -->
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-1.5">Jenis Penerima Baru <span class="text-red-500">*</span></label>
                            <select name="jenis_penerima" x-model="alihJenisPenerima" required
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-amber-500">
                                <option value="">— Pilih Jenis Penerima —</option>
                                <option value="Janda/Duda">Janda / Duda</option>
                                <option value="Anak">Anak</option>
                            </select>
                        </div>

                        <!-- Form fields -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">Nama Penerima Baru <span class="text-red-500">*</span></label>
                                <input type="text" name="nama_baru" required
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500"
                                    placeholder="Nama lengkap penerima manfaat">
                            </div>
                            
                            <div>
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">Tempat Lahir</label>
                                <input type="text" name="tmpt_lhr_baru"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500"
                                    placeholder="Kota tempat lahir penerima">
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">Tanggal Lahir Penerima <span class="text-red-500">*</span></label>
                                <input type="date" name="tgl_lhr_baru" required
                                    @change="hitungUmur($event.target.value)"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500">
                                <!-- Validasi umur untuk penerima Anak -->
                                <p x-show="alihValidasiUmur" x-text="alihValidasiUmur" class="text-xs text-red-500 mt-1"></p>
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">Jenis Kelamin <span class="text-red-500">*</span></label>
                                <select name="jk_baru" required
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-amber-500">
                                    <option value="">— Pilih —</option>
                                    <option value="L">Laki-laki</option>
                                    <option value="P">Perempuan</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">No. Telepon Utama <span class="text-red-500">*</span></label>
                                <input type="tel" name="telp_baru" required
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500"
                                    placeholder="08xxxxxxxxxx">
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">No. Telepon Cadangan</label>
                                <input type="tel" name="telp2_baru"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500"
                                    placeholder="Opsional">
                            </div>

                            <div class="md:col-span-2">
                                <label class="text-xs font-bold text-slate-600 block mb-1.5">Alamat</label>
                                <textarea name="alamat_baru" rows="2"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-amber-500"
                                        placeholder="Alamat penerima manfaat (kosongkan jika sama dengan pensiunan asli)"></textarea>
                            </div>
                        </div>

                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="modalAlihStatus = false"
                                    class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:bg-slate-50">
                                Batal
                            </button>
                            <button type="submit"
                                    :disabled="!alihJenisPenerima || !!alihValidasiUmur"
                                    :class="(!alihJenisPenerima || !!alihValidasiUmur) ? 'bg-slate-300 text-slate-700 cursor-not-allowed' : 'bg-amber-500 hover:bg-amber-600 text-white'"
                                    class="flex-1 py-2.5 rounded-xl font-bold text-sm transition-colors">
                                Konfirmasi Alih Status
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Preview Dokumen (dari tab Berkas) -->
            <div x-show="modalDok" @keydown.escape.window="modalDok = false" class="fixed inset-0 bg-slate-900/80 flex items-center justify-center z-[60] p-4" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                        <div>
                            <h3 class="font-bold text-slate-800" x-text="dokNama + ' — ' + dokJenis"></h3>
                            <p class="text-xs text-slate-500 mt-0.5">Dokumen Terenkripsi</p>
                        </div>
                        <button @click="modalDok = false" class="p-2 rounded-full border border-slate-200 text-slate-500 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="bg-slate-900 p-6 flex items-center justify-center min-h-[300px]">
                        <template x-if="dokLoading">
                            <div class="flex flex-col items-center gap-3 text-white">
                                <svg class="animate-spin h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                                </svg>
                                <p class="text-sm">Mendekripsi dokumen...</p>
                            </div>
                        </template>
                        <template x-if="!dokLoading && dokSrc">
                            <img :src="dokSrc" class="max-w-full max-h-[60vh] object-contain rounded border border-slate-700 shadow-lg">
                        </template>
                        <template x-if="!dokLoading && dokPdf">
                            <div class="flex flex-col items-center gap-3 text-white text-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm">Dokumen PDF — gunakan tombol Unduh untuk membuka.</p>
                            </div>
                        </template>
                    </div>
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center">
                        <a :href="'lihat_dokumen.php?id=' + dokId + '&mode=download'"
                        class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Unduh Dokumen
                        </a>
                        <button @click="modalDok = false" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2 rounded-lg text-sm font-medium">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>
            <!-- Modal Reset PIN -->
            <div x-show="modalReset" x-effect="if (!modalReset) { resetPinHasil = ''; resetPinError = ''; }" @keydown.escape.window="modalReset = false" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">

                    <!-- Tahap 1: konfirmasi -->
                    <form x-show="!resetPinHasil" @submit.prevent="resetPinKirim($el)" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="npk" :value="resetNpk">
                        <div class="text-center">
                            <div class="mx-auto h-12 w-12 rounded-full bg-amber-100 flex items-center justify-center mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-slate-800">Reset PIN</h3>
                            <p class="text-sm text-slate-500 mt-1">NPK: <span class="font-bold" x-text="resetNpk"></span></p>

                            <div class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-xl text-left">
                                <p class="text-sm text-amber-800 font-semibold mb-1">⚠️ Konfirmasi Reset PIN</p>
                                <p class="text-xs text-amber-700">
                                    PIN sementara baru akan dibuat acak. Pensiunan wajib mengganti PIN saat login berikutnya.
                                </p>
                            </div>
                            <p x-show="resetPinError" x-text="resetPinError" class="mt-3 text-sm text-red-600 font-semibold"></p>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="button" @click="modalReset = false"
                                    class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 text-sm">
                                Batal
                            </button>
                            <button type="submit" :disabled="resetPinLoading"
                                    class="flex-1 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 disabled:opacity-60 text-white font-bold text-sm">
                                <span x-text="resetPinLoading ? 'Memproses...' : 'Konfirmasi Reset'"></span>
                            </button>
                        </div>
                    </form>

                    <!-- Tahap 2: PIN sementara (tampil satu kali) -->
                    <div x-show="resetPinHasil" class="text-center">
                        <div class="mx-auto h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800">PIN Berhasil Di-reset</h3>
                        <p class="text-sm text-slate-500 mt-1">NPK: <span class="font-bold" x-text="resetNpk"></span></p>

                        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-5">
                            <p class="text-sm font-semibold text-amber-800">PIN sementara — tampil satu kali</p>
                            <p class="mt-2 text-3xl font-mono font-bold tracking-[0.3em] text-slate-900" x-text="resetPinHasil"></p>
                        </div>
                        <p class="text-xs text-slate-500 mt-3">Sampaikan PIN ini ke pensiunan. PIN tidak akan ditampilkan lagi setelah modal ditutup.</p>

                        <button type="button" @click="modalReset = false"
                                class="mt-5 w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm">
                            Selesai
                        </button>
                    </div>

                </div>
            </div>

            <!-- Modal Nonaktifkan -->
            <div x-show="modalNonaktif" @keydown.escape.window="modalNonaktif = false" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
                    <form action="proses_nonaktifkan_pensiunan.php" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="npk" :value="nonaktifNpk">
                        <div class="text-center">
                            <div class="mx-auto h-12 w-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-slate-800" x-text="nonaktifStatus === 'Aktif' ? 'Nonaktifkan Pensiunan?' : 'Aktifkan Kembali?'"></h3>
                            <p class="text-sm text-slate-500 mt-1">NPK: <span class="font-bold" x-text="nonaktifNpk"></span></p>
                            <p class="text-sm text-slate-500 mt-1" x-text="nonaktifStatus === 'Aktif' ? 'Pensiunan tidak dapat login setelah dinonaktifkan.' : 'Pensiunan dapat login kembali setelah diaktifkan.'"></p>
                        </div>
                        <div class="flex gap-3 mt-6">
                            <button type="button" @click="modalNonaktif = false"
                                    class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 text-sm">
                                Batal
                            </button>
                            <button type="submit"
                                    :class="nonaktifStatus === 'Aktif' ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                                    class="flex-1 py-2.5 rounded-xl text-white font-bold text-sm"
                                    x-text="nonaktifStatus === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan Kembali'">
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Tambah Pensiunan -->
            <div x-show="modalTambah" @keydown.escape.window="modalTambah = false" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col max-h-[90vh]">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl">
                        <div>
                            <h3 class="font-bold text-slate-800 text-xl">Tambah Pensiunan Baru</h3>
                            <p class="text-sm text-slate-500 mt-0.5">PIN awal akan dibuat otomatis oleh sistem.</p>
                        </div>
                        <button @click="modalTambah = false" class="p-2 rounded-full border border-slate-200 text-slate-500 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form action="proses_tambah_pensiunan.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-5">
                        <?= csrf_field(); ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">NPK <span class="text-red-500">*</span></label>
                                <input type="text" name="npk" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">NIK</label>
                                <input type="text" name="nik" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                                <input type="text" name="nama_lengkap" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Tempat Lahir</label>
                                <input type="text" name="tempat_lahir" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Tanggal Lahir</label>
                                <input type="date" name="tanggal_lahir" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Jenis Kelamin</label>
                                <select name="jenis_kelamin" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                                    <option value="">— Pilih —</option>
                                    <option value="L">Laki-laki</option>
                                    <option value="P">Perempuan</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">No. Telepon Utama <span class="text-red-500">*</span></label>
                                <input type="tel" name="no_telp" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">No. Telepon Cadangan</label>
                                <input type="tel" name="no_telp2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Alamat</label>
                                <textarea name="alamat" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Tanggal Masuk</label>
                                <input type="date" name="tanggal_masuk" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Tanggal Pensiun</label>
                                <input type="date" name="tanggal_pensiun" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Jenis Manfaat Pensiun</label>
                                <input type="text" name="jenis_manfaat_pensiun" placeholder="Contoh: Pensiun Normal" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-4 border-t border-slate-200">
                            <button type="button" @click="modalTambah = false"
                                    class="px-5 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50">
                                Batal
                            </button>
                            <button type="submit"
                                    class="px-5 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold shadow-sm">
                                Simpan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
function pensiunanApp() {
    return {
        modalDetail:  false,
        modalReset:   false,
        modalNonaktif: false,
        modalTambah:  false,
        tab:          'identitas',
        editMode:     false,
        aktif:        {},
        resetNpk:     '',
        resetPinHasil: '',
        resetPinError: '',
        resetPinLoading: false,
        nonaktifNpk:  '',
        nonaktifStatus: '',
        riwayatLapor: <?= json_encode($riwayat_lapor); ?>,
        tahunRange:   <?= json_encode($tahun_range); ?>,
        dokumenMap: <?= json_encode($dokumen_map_page); ?>,
        modalDok:   false,
        dokId:      '',
        dokSrc:     '',
        dokPdf:     false,
        dokNama:    '',
        dokJenis:   '',
        dokLoading: false,
        modalAlihStatus: false,
        alihNpk:         '',
        alihNama:        '',
        alihJenisPenerima: '',
        alihValidasiUmur:  '',

        bukaAlihStatus(npk, nama) {
            this.alihNpk             = npk;
            this.alihNama            = nama;
            this.alihJenisPenerima   = '';
            this.alihValidasiUmur    = '';
            this.modalAlihStatus     = true;
        },

        hitungUmur(tgl_lahir) {
            if (!tgl_lahir) return;
            const today = new Date();
            const lahir = new Date(tgl_lahir);
            const umur  = today.getFullYear() - lahir.getFullYear()
                        - (today < new Date(today.getFullYear(), lahir.getMonth(), lahir.getDate()) ? 1 : 0);

            if (this.alihJenisPenerima === 'Anak' && umur >= 25) {
                this.alihValidasiUmur = 'Penerima anak tidak memenuhi syarat (usia ' + umur + ' tahun ≥ 25 tahun).';
            } else {
                this.alihValidasiUmur = '';
            }
        },

        bukaDokumen(id, nama, jenis) {
            this.dokId      = id;
            this.dokNama    = nama;
            this.dokJenis   = jenis;
            this.dokSrc     = '';
            this.dokPdf     = false;
            this.dokLoading = true;
            this.modalDok   = true;

            fetch('lihat_dokumen.php?id=' + id)
                .then(r => {
                    const ct = r.headers.get('Content-Type') || '';
                    if (ct.includes('pdf')) {
                        this.dokPdf     = true;
                        this.dokLoading = false;
                        return null;
                    }
                    return r.blob();
                })
                .then(blob => {
                    if (blob) {
                        this.dokSrc     = URL.createObjectURL(blob);
                        this.dokLoading = false;
                    }
                })
                .catch(() => { this.dokLoading = false; });
        },

        async bukaDetail(npk) {
            try {
                const r = await fetch('lihat_profil.php?npk=' + encodeURIComponent(npk));
                if (!r.ok) throw new Error('HTTP ' + r.status);
                this.aktif       = await r.json();
                this.aktif.nama_penerima_asli = this.aktif.nama_asli_plain || '';
                this.tab         = 'identitas';
                this.editMode    = false;
                this.modalDetail = true;
            } catch (e) {
                alert('Data profil tidak bisa dibuka. Coba lagi.');
            }
        },
        async resetPinKirim(form) {
            this.resetPinLoading = true;
            this.resetPinError = '';
            try {
                const r = await fetch('proses_reset_pin.php', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json' },
                });
                const data = await r.json().catch(() => null);
                if (!r.ok || !data || !data.pin) {
                    throw new Error((data && data.error) || 'Gagal reset PIN. Coba lagi.');
                }
                this.resetPinHasil = data.pin;
            } catch (e) {
                this.resetPinError = e.message;
            } finally {
                this.resetPinLoading = false;
            }
        },

        bukaResetPin(npk) {
            this.resetNpk = npk;
            this.resetPinHasil = '';
            this.resetPinError = '';
            this.modalReset = true;
        },
        bukaNonaktif(npk, status) {
            this.nonaktifNpk    = npk;
            this.nonaktifStatus = status;
            this.modalNonaktif  = true;
        },
    }
}
</script>

</body>
</html>