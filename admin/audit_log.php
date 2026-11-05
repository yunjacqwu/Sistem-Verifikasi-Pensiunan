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
$filter_tabel = $_GET['tabel'] ?? '';
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
];

// === Fungsi mapping aksi -> verb/objek/icon/warna ===
function getActivityInfo(string $tabel, bool $is_user, array $kolom_list, array $details): array {

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
            return ['verb' => 'memperbarui', 'objek' => 'Data Diri', 'icon' => 'pencil', 'color' => 'amber', 'show_detail' => true];
        }

        // Admin: reset PIN — cek INI DULUAN, karena nilai_lama=NULL bisa salah terdeteksi sebagai "Pensiunan Baru"
        if (in_array('pin_hash', $kolom_list) || in_array('pin', $kolom_list)) {
            return ['verb' => 'mereset', 'objek' => 'PIN Pensiunan', 'icon' => 'key', 'color' => 'orange', 'show_detail' => false];
        }

        // Admin: cek apakah ini INSERT baru (semua nilai_lama kosong)
        $semua_baru = true;
        foreach ($details as $d) {
            if (!empty($d['nilai_lama'])) { $semua_baru = false; break; }
        }
        if ($semua_baru) {
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

    return ['verb' => 'melakukan perubahan pada', 'objek' => ucfirst(str_replace('_', ' ', $tabel)), 'icon' => 'document', 'color' => 'slate', 'show_detail' => true];
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

    if (!empty($filter_tabel)) {
        $where[]          = "a.tabel_terkait = :tabel";
        $params['tabel']  = $filter_tabel;
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
                || str_contains(strtolower($e['npk']), $search_lower);
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

    // Pagination manual setelah grouping + filter
    $total_data  = count($events);
    $total_page  = max(1, ceil($total_data / $per_page));
    $page        = min($page, $total_page);
    $offset      = ($page - 1) * $per_page;
    $events_page = array_slice($events, $offset, $per_page);

    // Kelompokkan events_page per tanggal — SETELAH pagination
    $events_grouped = [];
    foreach ($events_page as $e) {
        $tgl_key = date('Y-m-d', strtotime($e['waktu']));
        $events_grouped[$tgl_key][] = $e;
    }

    $tabel_list = $db->query("SELECT DISTINCT tabel_terkait FROM audit_logs ORDER BY tabel_terkait")->fetchAll(PDO::FETCH_COLUMN);

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
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Audit Log - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5">

            <!-- Header -->
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Audit Log</h2>
                <p class="text-slate-500 text-sm mt-1">
                    Riwayat aktivitas pengguna dan admin dalam sistem. Hanya dapat dibaca, tidak dapat diedit atau dihapus.
                </p>
            </div>

            <!-- Filter -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">

                    <!-- Search -->
                    <div class="col-span-2 relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                               placeholder="Cari NPK atau Nama..."
                               class="pl-9 pr-4 py-2 w-full border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Filter Tabel -->
                    <select name="tabel" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Jenis Aktivitas</option>
                        <?php foreach ($tabel_list as $t): ?>
                            <option value="<?= htmlspecialchars($t); ?>" <?= $filter_tabel === $t ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($t); ?>
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
                    <select name="per_page" onchange="this.form.submit()"
                            class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ([25,50,100,250,500] as $n): ?>
                            <option value="<?= $n; ?>" <?= $per_page === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>aktivitas — Total: <strong><?= number_format($total_data); ?></strong></span>
                </div>
            </form>

            <!-- Activity Feed -->
            <div class="space-y-3">
                <?php if (empty($events_page)): ?>
                    <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-400">
                        Tidak ada aktivitas yang sesuai filter.
                    </div>
                <?php else: ?>
                    <?php
                    $hari_ini = date('Y-m-d');
                    $kemarin  = date('Y-m-d', strtotime('-1 day'));

                    foreach ($events_grouped as $tgl => $group_events):
                        if ($tgl === $hari_ini) {
                            $label_tgl = 'Hari ini';
                        } elseif ($tgl === $kemarin) {
                            $label_tgl = 'Kemarin';
                        } else {
                            $fmt = formatTanggal($tgl . ' 00:00:00');
                            $label_tgl = $fmt['tanggal'];
                        }
                    ?>

                    <!-- Separator tanggal -->
                    <div class="flex items-center gap-3 my-1">
                        <div class="flex-1 h-px bg-slate-200"></div>
                        <span class="text-xs font-semibold text-slate-400 bg-slate-50 px-3 py-1 rounded-full border border-slate-200 whitespace-nowrap">
                            <?= $label_tgl; ?>
                        </span>
                        <div class="flex-1 h-px bg-slate-200"></div>
                    </div>

                    <?php foreach ($group_events as $e): ?>
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden w-full" x-data="{ open: false }">

                        <div class="px-4 py-3 flex items-center gap-3 <?= $e['has_detail'] ? 'cursor-pointer hover:bg-slate-50' : ''; ?> transition-colors"
                            <?= $e['has_detail'] ? '@click="open = !open"' : ''; ?>>

                            <!-- Icon -->
                            <div class="h-10 w-10 rounded-full flex items-center justify-center bg-<?= $e['color']; ?>-100 text-<?= $e['color']; ?>-600 flex-shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <?= activityIconPath($e['icon']); ?>
                                </svg>
                            </div>

                            <!-- Teks -->
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-slate-800 text-sm truncate">
                                    <?php if ($e['is_user']): ?>
                                        <span class="font-mono text-slate-500"><?= htmlspecialchars($e['npk']); ?></span> /
                                        <?= htmlspecialchars($e['nama_plain']); ?>
                                        <?= $e['verb']; ?> <span class="font-bold"><?= $e['objek']; ?></span>
                                    <?php else: ?>
                                        <span class="text-blue-600">Admin</span>
                                        <span class="text-slate-400">(<?= htmlspecialchars($e['diubah_oleh']); ?>)</span>
                                        <?= $e['verb']; ?> <span class="font-bold"><?= $e['objek']; ?></span>
                                        <?php if (!empty($e['npk'])): ?>
                                            <?php $connector = ($e['objek'] === 'Pensiunan Baru') ? ':' : 'untuk'; ?>
                                            <?= $connector; ?> <span class="font-mono text-slate-500"><?= htmlspecialchars($e['npk']); ?></span> / <?= htmlspecialchars($e['nama_plain']); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5"><?= $e['tanggal_fmt']; ?> · <?= $e['jam_fmt']; ?></p>
                            </div>

                            <!-- Chevron -->
                            <?php if ($e['has_detail']): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400 flex-shrink-0 ml-auto transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            <?php endif; ?>
                        </div>

                        <!-- Detail -->
                        <?php if ($e['has_detail']): ?>
                        <div x-show="open" x-transition class="px-4 pb-4 pt-1 border-t border-slate-100 space-y-2 overflow-x-hidden" style="display:none;">
                            <?php
                            if ($e['tabel_terkait'] === 'dokumen' && $e['is_user']):
                                $jenis_list = array_column($e['details'], 'nilai_baru');
                            ?>
                                <p class="text-sm text-slate-600 pl-1">
                                    <span class="font-bold text-slate-700">01.</span> — Mengunggah <span class="font-bold"><?= implode(' dan ', $jenis_list); ?></span>
                                </p>
                            <?php else: ?>
                                <?php
                                $kolom_terenkripsi = ['nama', 'nik', 'tmpt_lhr', 'telp', 'telp2', 'alamat'];
                                foreach ($e['details'] as $i => $d):
                                    $label = $kolom_label_map[$d['kolom_diubah']] ?? $d['kolom_diubah'];
                                    if (in_array($d['kolom_diubah'], $kolom_terenkripsi)) {
                                        $lama = !empty($d['nilai_lama']) ? dekripsi($d['nilai_lama'], $e['npk']) : '—';
                                        $baru = !empty($d['nilai_baru']) ? dekripsi($d['nilai_baru'], $e['npk']) : '—';
                                    } else {
                                        $lama = ($d['nilai_lama'] !== null && $d['nilai_lama'] !== '') ? $d['nilai_lama'] : '—';
                                        $baru = $d['nilai_baru'] ?? '—';
                                    }
                                ?>
                                    <p class="text-sm text-slate-600 pl-1 leading-relaxed break-words">
                                        <span class="font-bold text-slate-700"><?= sprintf('%02d', $i+1); ?>.</span> — Mengubah <span class="font-bold"><?= htmlspecialchars($label); ?></span> dari
                                        <span class="font-mono text-xs bg-red-50 text-red-600 px-1.5 py-0.5 rounded break-all inline-block max-w-full align-bottom"><?= htmlspecialchars($lama); ?></span>
                                        jadi
                                        <span class="font-mono text-xs bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded break-all inline-block max-w-full align-bottom"><?= htmlspecialchars($baru); ?></span>
                                    </p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_page > 1): ?>
            <?php
                $query_params = $_GET;
                unset($query_params['page']);
                $query_string = http_build_query($query_params);
            ?>
            <div class="bg-white rounded-xl border border-slate-200 px-4 py-3 flex items-center justify-between text-sm text-slate-600">
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

        </main>
    </div>
</div>
</body>
</html>