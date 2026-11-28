<?php
// file: admin/audit_log.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// UPDATE SESSION DULU — sebelum include sidebar
$_SESSION['audit_log_last_seen'] = date('Y-m-d H:i:s');

date_default_timezone_set('Asia/Jakarta');

$search       = trim($_GET['search'] ?? '');
$filter_kategori = $_GET['kategori'] ?? '';

// Kategori aktivitas: label untuk dropdown dan potongan SQL tetap (tidak ada input pengguna di SQL).
$KATEGORI = [
    'pensiunan_data'      => ['label' => 'Data Pensiunan', 'sql' => "(a.tabel_terkait = 'pensiunan' AND a.kolom_diubah NOT IN ('pin_hash','pin','export_pin'))"],
    'reset_pin'           => ['label' => 'Reset PIN', 'sql' => "(a.tabel_terkait = 'pensiunan' AND a.kolom_diubah IN ('pin_hash','pin'))"],
    'ekspor_pin'          => ['label' => 'Ekspor PIN', 'sql' => "(a.kolom_diubah = 'export_pin')"],
    'lapor_diri'          => ['label' => 'Lapor Diri', 'sql' => "(a.tabel_terkait = 'lapor_diri' AND a.kolom_diubah IN ('lapor_diri','status_verifikasi'))"],
    'dokumen_upload'      => ['label' => 'Unggah Dokumen', 'sql' => "(a.kolom_diubah = 'dokumen_upload')"],
    'dokumen_verifikasi'  => ['label' => 'Verifikasi Dokumen', 'sql' => "(a.tabel_terkait = 'dokumen' AND a.kolom_diubah IN ('status_ktp','status_kk'))"],
    'akses_berkas'        => ['label' => 'Akses Berkas, Foto, dan Profil', 'sql' => "(a.kolom_diubah IN ('akses_berkas','akses_foto','akses_profil'))"],
    'login_admin'         => ['label' => 'Login Admin', 'sql' => "(a.tabel_terkait = 'admin_sistem' AND a.kolom_diubah IN ('login','logout'))"],
];
// Kategori 'lainnya' = semua baris yang tidak termasuk kategori di atas, supaya tidak ada baris yang hilang dari filter.
$KATEGORI['lainnya'] = ['label' => 'Lainnya', 'sql' => 'NOT (' . implode(' OR ', array_map(fn($k) => $KATEGORI[$k]['sql'], array_keys($KATEGORI))) . ')'];
$date_from    = $_GET['date_from'] ?? '';
$date_to      = $_GET['date_to'] ?? '';
$per_page     = in_array((int)($_GET['per_page'] ?? 25), [25,50,100,250,500])
                  ? (int)($_GET['per_page'] ?? 25) : 25;
$page         = max(1, (int)($_GET['page'] ?? 1));

function isCiphertext(?string $val): bool {
    if (empty($val)) return false;
    return strlen($val) > 50 && str_contains($val, ':');
}

// Label kolom yang lebih manusiawi
$kolom_label_map = [
    'telp'              => 'No. Telepon Utama',
    'telp2'             => 'No. Telepon Cadangan',
    'alamat'            => 'Alamat',
    'nama'              => 'Nama',
    'nik'               => 'NIK',
    'tmpt_lhr'          => 'Tempat Lahir',
    'jk'                => 'Jenis Kelamin',
    'jns_mnft'          => 'Jenis Manfaat',
    'status'            => 'Status',
    'status_verifikasi' => 'Status Verifikasi',
    'catatan_tolak'     => 'Catatan Penolakan',
    'pin_hash'          => 'PIN',
    'pin'               => 'PIN',
    'status_ktp'        => 'Status KTP',
    'status_kk'         => 'Status KK',
    'tgl_lhr'           => 'Tanggal Lahir',
    'tgl_msk'           => 'Tanggal Masuk Kerja',
    'tgl_psn'           => 'Tanggal Pensiun',
    'dokumen_upload'    => 'Dokumen Diunggah',
    'lapor_diri'        => 'Lapor Diri',
    'alih_status'       => 'Alih Status Penerima',
    'jns_penerima'      => 'Status Penerima',
    'akses_berkas'      => 'Akses Berkas',
    'akses_foto'        => 'Akses Foto',
    'akses_profil'      => 'Profil Pensiunan',
    'export_pin'        => 'Ekspor PIN',
    'login'             => 'Login',
    'logout'            => 'Logout',
];

// === Fungsi mapping aksi -> verb/objek/icon/warna ===
function getActivityInfo(string $tabel, bool $is_user, array $kolom_list, array $details): array {

    // Aksi akses dan ekspor (dicatat sejak F-06 dan ekspor PIN)
    $nilai_akses = '';
    foreach ($details as $d) {
        if (in_array($d['kolom_diubah'], ['akses_berkas', 'akses_foto'], true)) { $nilai_akses = (string)$d['nilai_baru']; }
    }
    if (in_array('akses_berkas', $kolom_list, true)) {
        $jenis = trim((string)preg_replace('/^(Unduh|Lihat)\s*/', '', $nilai_akses));
        return ['verb' => str_starts_with($nilai_akses, 'Unduh') ? 'mengunduh' : 'melihat', 'objek' => 'Dokumen ' . $jenis, 'icon' => 'document', 'color' => 'blue', 'show_detail' => false];
    }
    if (in_array('akses_foto', $kolom_list, true)) {
        return ['verb' => str_starts_with($nilai_akses, 'Unduh') ? 'mengunduh' : 'melihat', 'objek' => 'Foto Lapor Diri', 'icon' => 'camera', 'color' => 'blue', 'show_detail' => false];
    }
    if (in_array('export_pin', $kolom_list, true)) {
        return ['verb' => 'mengekspor', 'objek' => 'PIN', 'icon' => 'key', 'color' => 'orange', 'show_detail' => false];
    }
    if ($tabel === 'admin_sistem' && in_array('login', $kolom_list, true)) {
        return ['verb' => 'masuk ke', 'objek' => 'Aplikasi', 'icon' => 'power', 'color' => 'slate', 'show_detail' => false];
    }
    if ($tabel === 'admin_sistem' && in_array('logout', $kolom_list, true)) {
        return ['verb' => 'keluar dari', 'objek' => 'Aplikasi', 'icon' => 'power', 'color' => 'slate', 'show_detail' => false];
    }


    if ($tabel === 'lapor_diri') {
        if ($is_user) {
            return ['verb' => 'melakukan', 'objek' => 'Lapor Diri', 'icon' => 'camera', 'color' => 'blue', 'show_detail' => false];
        }
        $status_baru = '';
        foreach ($details as $d) {
            if ($d['kolom_diubah'] === 'status_verifikasi') $status_baru = $d['nilai_baru'];
        }
        if ($status_baru === 'Disetujui') {
            return ['verb' => 'menyetujui', 'objek' => 'Lapor Diri', 'icon' => 'check', 'color' => 'emerald', 'show_detail' => false];
        }
        if ($status_baru === 'Ditolak') {
            return ['verb' => 'menolak', 'objek' => 'Lapor Diri', 'icon' => 'x', 'color' => 'red', 'show_detail' => true];
        }
        return ['verb' => 'memperbarui', 'objek' => 'Data Lapor Diri', 'icon' => 'document', 'color' => 'slate', 'show_detail' => true];
    }

    if ($tabel === 'dokumen') {
        if ($is_user) {
            return ['verb' => 'mengunggah', 'objek' => 'Dokumen Berkas', 'icon' => 'upload', 'color' => 'purple', 'show_detail' => true];
        }
        $status_baru = '';
        foreach ($details as $d) {
            if ($d['kolom_diubah'] === 'status') $status_baru = $d['nilai_baru'];
        }
        if ($status_baru === 'Disetujui') {
            return ['verb' => 'menyetujui', 'objek' => 'Dokumen', 'icon' => 'check', 'color' => 'emerald', 'show_detail' => false];
        }
        if ($status_baru === 'Ditolak') {
            return ['verb' => 'menolak', 'objek' => 'Dokumen', 'icon' => 'x', 'color' => 'red', 'show_detail' => true];
        }
        return ['verb' => 'memperbarui', 'objek' => 'Status Dokumen', 'icon' => 'document', 'color' => 'slate', 'show_detail' => true];
    }

    if ($tabel === 'pensiunan') {
        if ($is_user) {
            if (in_array('pin_hash', $kolom_list) || in_array('pin', $kolom_list)) {
                return ['verb' => 'mengubah', 'objek' => 'PIN', 'icon' => 'key', 'color' => 'orange', 'show_detail' => false];
            }
            return ['verb' => 'memperbarui', 'objek' => 'Data Diri', 'icon' => 'pencil', 'color' => 'amber', 'show_detail' => true];
        }

        // Admin: lihat profil pensiunan (dicatat saat modal Detail dibuka)
        if (in_array('akses_profil', $kolom_list)) {
            return ['verb' => 'melihat', 'objek' => 'Profil Pensiunan', 'icon' => 'document', 'color' => 'blue', 'show_detail' => false];
        }

        // Admin: reset PIN — cek INI DULUAN, karena nilai_lama=NULL bisa salah terdeteksi sebagai "Pensiunan Baru"
        if (in_array('pin_hash', $kolom_list) || in_array('pin', $kolom_list)) {
            return ['verb' => 'mereset', 'objek' => 'PIN Pensiunan', 'icon' => 'key', 'color' => 'orange', 'show_detail' => false];
        }

        // Admin: pensiunan baru ditandai kolom INSERT. Perubahan biasa dengan nilai lama kosong bukan pensiunan baru.
        if (in_array('INSERT', $kolom_list, true)) {
            return ['verb' => 'menambahkan', 'objek' => 'Pensiunan Baru', 'icon' => 'user-plus', 'color' => 'emerald', 'show_detail' => false];
        }

        // Admin: ubah status keaktifan
        if (count($kolom_list) === 1 && $kolom_list[0] === 'status') {
            return ['verb' => 'mengubah', 'objek' => 'Status Keaktifan Pensiunan', 'icon' => 'power', 'color' => 'slate', 'show_detail' => true];
        }

        // Alih status penerima
        if (in_array('alih_status', $kolom_list)) {
            return ['verb' => 'mengalihkan', 'objek' => 'Status Penerima Manfaat', 'icon' => 'switch', 'color' => 'purple', 'show_detail' => true];
        }

        return ['verb' => 'memperbarui', 'objek' => 'Data Pensiunan', 'icon' => 'pencil', 'color' => 'amber', 'show_detail' => true];
    }

    return ['verb' => 'mencatat aksi pada', 'objek' => ucfirst(str_replace('_', ' ', $tabel)), 'icon' => 'document', 'color' => 'slate', 'show_detail' => true];
}

// SVG path per ikon
function activityIconPath(string $icon): string {
    $icons = [
        'camera'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'pencil'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
        'upload'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"/>',
        'check'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        'x'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        'document'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'user-plus' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3M9 11a4 4 0 100-8 4 4 0 000 8zm-6 9v-1a6 6 0 0112 0v1H3z"/>',
        'key' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
        'power'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"/>',
        'switch' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
    ];
    return $icons[$icon] ?? $icons['document'];
}

try {
    $where  = ["1=1"];
    $params = [];

    if (isset($KATEGORI[$filter_kategori])) {
        $where[] = $KATEGORI[$filter_kategori]['sql'];
    }
    if (!empty($date_from)) {
        $where[]              = "DATE(a.waktu) >= :date_from";
        $params['date_from']  = $date_from;
    }
    if (!empty($date_to)) {
        $where[]            = "DATE(a.waktu) <= :date_to";
        $params['date_to']  = $date_to;
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT a.id, a.npk, a.tabel_terkait, a.kolom_diubah,
               a.nilai_lama, a.nilai_baru, a.diubah_oleh, a.waktu, p.nama
        FROM audit_logs a
        LEFT JOIN pensiunan p ON a.npk = p.npk
        WHERE $where_sql
        ORDER BY a.waktu DESC, a.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Dekripsi nama
    foreach ($rows as &$r) {
        $r['nama_plain'] = !empty($r['nama']) ? dekripsi($r['nama'], $r['npk']) : 'Tidak diketahui';
    }
    unset($r);

    // === GROUPING: npk + tabel_terkait + diubah_oleh + waktu yang sama -> 1 event ===
    $events = [];
    foreach ($rows as $r) {
        $key = $r['npk'] . '|' . $r['tabel_terkait'] . '|' . $r['diubah_oleh'] . '|' . $r['waktu'];
        if (!isset($events[$key])) {
            $events[$key] = [
                'npk'           => $r['npk'],
                'nama_plain'    => $r['nama_plain'],
                'tabel_terkait' => $r['tabel_terkait'],
                'diubah_oleh'   => $r['diubah_oleh'],
                'waktu'         => $r['waktu'],
                'details'       => [],
            ];
        }
        $events[$key]['details'][] = $r;
    }
    $events = array_values($events);

    // Filter search (npk atau nama)
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $events = array_filter($events, function($e) use ($search_lower) {
            return str_contains(strtolower($e['nama_plain']), $search_lower)
                || str_contains(strtolower($e['npk'] ?? ''), $search_lower);
        });
        $events = array_values($events);
    }

    // Mapping verb/icon per event
    foreach ($events as &$e) {
        $kolom_list = array_column($e['details'], 'kolom_diubah');
        $is_user    = ($e['diubah_oleh'] === $e['npk']);
        $info       = getActivityInfo($e['tabel_terkait'], $is_user, $kolom_list, $e['details']);

        $e['verb']       = $info['verb'];
        $e['objek']      = $info['objek'];
        $e['icon']       = $info['icon'];
        $e['color']      = $info['color'];
        $e['is_user']    = $is_user;
        $e['has_detail'] = $info['show_detail'];

        $fmt = formatTanggal($e['waktu']);
        $e['tanggal_fmt'] = $fmt['tanggal'];
        $e['jam_fmt']     = $fmt['jam'];
    }
    unset($e);

    // Pisahkan aksi admin dan pensiunan. Masing-masing punya paginasi sendiri (parameter pa dan pu).
    $total_data   = count($events);
    $events_admin = array_values(array_filter($events, fn($e) => !$e['is_user']));
    $events_user  = array_values(array_filter($events, fn($e) => $e['is_user']));
    $total_admin  = count($events_admin);
    $total_user   = count($events_user);

    $total_page_a = max(1, (int)ceil($total_admin / $per_page));
    $total_page_u = max(1, (int)ceil($total_user / $per_page));
    $page_a = min(max(1, (int)($_GET['pa'] ?? 1)), $total_page_a);
    $page_u = min(max(1, (int)($_GET['pu'] ?? 1)), $total_page_u);

    $events_page_admin = array_slice($events_admin, ($page_a - 1) * $per_page, $per_page);
    $events_page_user  = array_slice($events_user,  ($page_u - 1) * $per_page, $per_page);

    $kelompok_per_tanggal = function (array $list): array {
        $g = [];
        foreach ($list as $e) { $g[date('Y-m-d', strtotime($e['waktu']))][] = $e; }
        return $g;
    };
    $grouped_admin = $kelompok_per_tanggal($events_page_admin);
    $grouped_user  = $kelompok_per_tanggal($events_page_user);


} catch (PDOException $e) {
    die("Gagal memuat data audit log.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan_dev/sikepen.png">
    <title>Audit Log - Admin</title>
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5">

            <!-- Header -->
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Log Aktivitas dan Audit Data</h2>
                <p class="text-slate-500 text-sm mt-1">
                    Riwayat perubahan data dan akses berkas. Kiri: aksi admin. Kanan: aksi pensiunan. Hanya dapat dibaca, tidak dapat diedit atau dihapus.
                </p>
            </div>

            <!-- Filter -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">

                    <!-- Search -->
                    <div class="col-span-2 relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                               placeholder="Cari NPK atau nama"
                               class="pl-9 pr-4 py-2 w-full border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-500 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Filter Tabel -->
                    <select name="kategori" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Aktivitas</option>
                        <?php foreach ($KATEGORI as $key => $kat): ?>
                            <option value="<?= htmlspecialchars($key); ?>" <?= $filter_kategori === $key ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($kat['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Date From -->
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from); ?>"
                           class="px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">

                    <!-- Date To -->
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to); ?>"
                           class="px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">

                    <!-- Tombol -->
                    <div class="col-span-1 flex gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors">Filter</button>
                        <a href="audit_log.php" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition-colors">Reset</a>
                    </div>

                </div>

                <!-- Per page -->
                <div class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                    <span>Tampilkan</span>
                    <select name="per_page" data-auto-submit
                            class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ([25,50,100,250,500] as $n): ?>
                            <option value="<?= $n; ?>" <?= $per_page === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>Total aktivitas: <strong><?= number_format($total_data); ?></strong></span>
                </div>
            </form>

            <!-- Dua sisi: kiri aksi admin, kanan aksi pensiunan. Masing-masing punya scroll dan paginasi sendiri. -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
                <section class="min-w-0">
                    <div class="sticky top-0 z-10 bg-slate-50 pb-2">
                        <h3 class="font-bold text-slate-700">Aksi Admin <span class="text-sm font-normal text-slate-500">(<?= number_format($total_admin); ?>)</span></h3>
                    </div>
                    <div class="overflow-y-auto pr-1 space-y-3" style="max-height: 70vh;">
                        <?php
                        $events_page    = $events_page_admin;
                        $events_grouped = $grouped_admin;
                        include __DIR__ . '/includes/audit_feed.php';
                        ?>
                    </div>
                    <?php $pager_param = 'pa'; $pager_page = $page_a; $pager_total = $total_page_a; include __DIR__ . '/includes/audit_pager.php'; ?>
                </section>

                <section class="min-w-0">
                    <div class="sticky top-0 z-10 bg-slate-50 pb-2">
                        <h3 class="font-bold text-slate-700">Aksi Pensiunan <span class="text-sm font-normal text-slate-500">(<?= number_format($total_user); ?>)</span></h3>
                    </div>
                    <div class="overflow-y-auto pr-1 space-y-3" style="max-height: 70vh;">
                        <?php
                        $events_page    = $events_page_user;
                        $events_grouped = $grouped_user;
                        include __DIR__ . '/includes/audit_feed.php';
                        ?>
                    </div>
                    <?php $pager_param = 'pu'; $pager_page = $page_u; $pager_total = $total_page_u; include __DIR__ . '/includes/audit_pager.php'; ?>
                </section>
            </div>

        </main>
    </div>
</div>
</body>
</html>