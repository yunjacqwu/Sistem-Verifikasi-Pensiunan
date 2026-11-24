<?php
// file: admin/export_excel.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// === Ambil filter dari query string (sama seperti data_pensiunan.php) ===
$search        = trim($_GET['search'] ?? '');
$filter_jk     = $_GET['jk'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_mnft   = $_GET['mnft'] ?? '';
$filter_berkas = $_GET['berkas'] ?? '';
$filter_lapor = $_GET['lapor'] ?? '';
$umur_min      = $_GET['umur_min'] ?? '';
$umur_max      = $_GET['umur_max'] ?? '';

try {
    // === Bangun WHERE: filter dijalankan di database (sama dengan data_pensiunan.php) ===
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

    // Pencarian: NPK di SQL. Nama terenkripsi, jadi hanya kolom nama yang didekripsi saat kolom pencarian diisi.
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

    $sql_all = "
        SELECT p.*,
            TIMESTAMPDIFF(YEAR, p.tgl_lhr, CURDATE()) AS umur,
            (SELECT COUNT(*) FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KTP') AS jml_ktp,
            (SELECT COUNT(*) FROM dokumen d WHERE d.npk = p.npk AND d.jns_dok = 'KK') AS jml_kk
        FROM pensiunan p
        WHERE $where_sql
    ";
    $stmt_all = $db->prepare($sql_all);
    foreach ($params as $k => $v) {
        $stmt_all->bindValue(':' . $k, $v);
    }
    $stmt_all->execute();
    $semua_data = $stmt_all->fetchAll();

    $npk_list = array_column($semua_data, 'npk');

    // === Status dokumen (KTP/KK) per npk ===
    $dokumen_map = [];
    if (!empty($npk_list)) {
        $ph = implode(',', array_fill(0, count($npk_list), '?'));
        $stmt_dok = $db->prepare("SELECT npk, jns_dok, status FROM dokumen WHERE npk IN ($ph)");
        $stmt_dok->execute($npk_list);
        foreach ($stmt_dok->fetchAll() as $d) {
            $dokumen_map[$d['npk']][$d['jns_dok']] = $d['status'];
        }
    }

    // === Lapor diri tahun berjalan, per kuartal (status terbaru) ===
    $tahun_sekarang = (int)date('Y');
    $lapor_map = [];
    if (!empty($npk_list)) {
        $ph = implode(',', array_fill(0, count($npk_list), '?'));
        $stmt_lapor = $db->prepare("
            SELECT npk, waktu, status_verifikasi
            FROM lapor_diri
            WHERE npk IN ($ph) AND YEAR(waktu) = ?
            ORDER BY waktu DESC
        ");
        $stmt_lapor->execute([...$npk_list, $tahun_sekarang]);
        foreach ($stmt_lapor->fetchAll() as $l) {
            $kuartal = 'Q' . ceil(date('n', strtotime($l['waktu'])) / 3);
            if (isset($lapor_map[$l['npk']][$kuartal])) continue; // simpan yang terbaru saja
            $lapor_map[$l['npk']][$kuartal] = $l['status_verifikasi'];
        }
    }

    // === Dekripsi + hitung status berkas + lapor diri per kuartal ===
    foreach ($semua_data as &$row) {
        $row['nama_plain']     = dekripsi($row['nama'], $row['npk']);
        $row['nik_plain']      = dekripsi($row['nik'], $row['npk']);
        $row['tmpt_lhr_plain'] = dekripsi($row['tmpt_lhr'], $row['npk']);
        $row['telp_plain']     = dekripsi($row['telp'], $row['npk']);
        $row['telp2_plain']    = dekripsi($row['telp2'], $row['npk']);
        $row['alamat_plain']   = dekripsi($row['alamat'], $row['npk']);
        $row['jk_label']       = $row['jk'] === 'L' ? 'Laki-laki' : ($row['jk'] === 'P' ? 'Perempuan' : '-');

        $row['tgl_lhr_fmt'] = !empty($row['tgl_lhr']) ? formatTanggal($row['tgl_lhr'])['tanggal'] : '-';
        $row['tgl_msk_fmt'] = !empty($row['tgl_msk']) ? formatTanggal($row['tgl_msk'])['tanggal'] : '-';
        $row['tgl_psn_fmt'] = !empty($row['tgl_psn']) ? formatTanggal($row['tgl_psn'])['tanggal'] : '-';

        // Status dokumen individual
        $ktp_status = $dokumen_map[$row['npk']]['KTP'] ?? null;
        $kk_status  = $dokumen_map[$row['npk']]['KK']  ?? null;
        $row['status_ktp'] = $ktp_status ?? 'Belum Ada';
        $row['status_kk']  = $kk_status  ?? 'Belum Ada';

        // Status berkas keseluruhan (untuk filter, sama seperti data_pensiunan.php)
        $ktp = (int)$row['jml_ktp'];
        $kk  = (int)$row['jml_kk'];
        if ($ktp === 0 && $kk === 0) {
            $row['status_berkas'] = 'Belum Ada';
        } elseif ($ktp > 0 && $kk === 0) {
            $row['status_berkas'] = 'Hanya KTP';
        } elseif ($ktp === 0 && $kk > 0) {
            $row['status_berkas'] = 'Hanya KK';
        } elseif ($ktp_status === 'Disetujui' && $kk_status === 'Disetujui') {
            $row['status_berkas'] = 'Lengkap & Disetujui';
        } elseif ($ktp_status === 'Ditolak' || $kk_status === 'Ditolak') {
            $row['status_berkas'] = 'Ada yang Ditolak';
        } else {
            $row['status_berkas'] = 'Menunggu Verifikasi';
        }

        // Lapor diri Q1-Q4 tahun berjalan
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            $row['lapor_' . $q] = $lapor_map[$row['npk']][$q] ?? 'Belum Lapor';
        }
    }
    unset($row);

    // Urutkan berdasarkan nama setelah didekripsi (kolom nama terenkripsi tidak bisa diurutkan di SQL)
    usort($semua_data, fn($a, $b) => strcasecmp((string)dekripsi($a['nama'], $a['npk']), (string)dekripsi($b['nama'], $b['npk'])));

} catch (PDOException $e) {
    die("Gagal memproses data laporan.");
}

// === Output sebagai Excel ===
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Laporan_Pensiunan_Peruri_" . date('Y-m-d') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        .th-utama {
            background-color: #1E293B !important;
            color: #FFFFFF !important;
            font-family: Arial, sans-serif;
            font-size: 10pt;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            border: 1px solid #CBD5E1;
        }
        .td-data {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            vertical-align: middle;
            border: 1px solid #E2E8F0;
        }
    </style>
    <link rel="stylesheet" href="/absensi_pensiunan_dev/static/css/fonts.css">
    <script defer src="/absensi_pensiunan_dev/static/js/modal-focus.js"></script>
</head>
<body>

    <h2>LAPORAN DATA PENSIUNAN — DANA PENSIUN PERURI</h2>
    <p style="font-style: italic; font-size: 10pt; color: #64748B;">
        Sistem Verifikasi Kelangsungan Hidup Pensiunan<br>
        Tanggal Cetak: <?= date('d-m-Y H:i'); ?> WIB &nbsp;|&nbsp; Total Data: <?= count($semua_data); ?>
    </p>

    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr>
                <th rowspan="2" class="th-utama">No</th>
                <th rowspan="2" class="th-utama">NPK</th>
                <th colspan="8" class="th-utama">Identitas Dasar Pensiunan</th>
                <th colspan="6" class="th-utama">Detail Kepensiunan</th>
                <th colspan="2" class="th-utama">Status Berkas</th>
                <th colspan="4" class="th-utama">Lapor Diri Tahun <?= $tahun_sekarang; ?></th>
            </tr>
            <tr>
                <th class="th-utama">Nama Lengkap</th>
                <th class="th-utama">NIK</th>
                <th class="th-utama">Jenis Kelamin</th>
                <th class="th-utama">Tempat Lahir</th>
                <th class="th-utama">Tanggal Lahir</th>
                <th class="th-utama">No. Telp Utama</th>
                <th class="th-utama">No. Telp Cadangan</th>
                <th class="th-utama">Alamat Lengkap</th>

                <th class="th-utama">Tgl Masuk Kerja</th>
                <th class="th-utama">Tgl Pensiun</th>
                <th class="th-utama">Jenis Manfaat</th>
                <th class="th-utama">Jenis Penerima</th>
                <th class="th-utama">Nama Pensiunan Asli</th>
                <th class="th-utama">Status Akun</th>

                <th class="th-utama">KTP</th>
                <th class="th-utama">KK</th>

                <th class="th-utama">Q1</th>
                <th class="th-utama">Q2</th>
                <th class="th-utama">Q3</th>
                <th class="th-utama">Q4</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $status_color = function ($status) {
                return match ($status) {
                    'Disetujui'           => '#15803D',
                    'Ditolak'             => '#B91C1C',
                    'Menunggu Verifikasi' => '#B45309',
                    'Belum Ada', 'Belum Lapor' => '#64748B',
                    default               => '#000000',
                };
            };

            foreach ($semua_data as $row):
                $bg_warna = ($no % 2 == 0) ? '#F8FAFC' : '#FFFFFF';
            ?>
            <tr style="background-color: <?= $bg_warna; ?>;">
                <td class="td-data" style="text-align:center; font-weight:bold;"><?= $no++; ?></td>
                <td class="td-data" style="mso-number-format:'\@'; text-align:center; white-space:nowrap;"><?= htmlspecialchars($row['npk']); ?></td>

                <td class="td-data" style="padding:6px; white-space:nowrap;"><?= htmlspecialchars($row['nama_plain']); ?></td>
                <td class="td-data" style="mso-number-format:'\@'; text-align:center; white-space:nowrap;"><?= htmlspecialchars($row['nik_plain'] ?: '-'); ?></td>
                <td class="td-data" style="text-align:center; white-space:nowrap;"><?= $row['jk_label']; ?></td>
                <td class="td-data" style="padding:6px; white-space:nowrap;"><?= htmlspecialchars($row['tmpt_lhr_plain'] ?: '-'); ?></td>
                <td class="td-data" style="text-align:center; white-space:nowrap;"><?= $row['tgl_lhr_fmt']; ?></td>
                <td class="td-data" style="mso-number-format:'\@'; text-align:left; white-space:nowrap;"><?= htmlspecialchars($row['telp_plain']); ?></td>
                <td class="td-data" style="mso-number-format:'\@'; text-align:left; white-space:nowrap;"><?= htmlspecialchars($row['telp2_plain'] ?: '-'); ?></td>
                <td class="td-data" style="padding:6px; text-align:left; min-width:250px;"><?= htmlspecialchars($row['alamat_plain'] ?: '-'); ?></td>

                <td class="td-data" style="text-align:center; white-space:nowrap;"><?= $row['tgl_msk_fmt']; ?></td>
                <td class="td-data" style="text-align:center; white-space:nowrap;"><?= $row['tgl_psn_fmt']; ?></td>
                <td class="td-data" style="padding:6px; white-space:nowrap;"><?= htmlspecialchars($row['jns_mnft'] ?: '-'); ?></td>
                <td class="td-data" style="padding:6px; white-space:nowrap;"><?= htmlspecialchars($row['jns_penerima'] ?? 'Pensiunan Asli'); ?></td>
                <td class="td-data" style="padding:6px; white-space:nowrap;">
                    <?php
                    // Dekripsi nama pensiunan asli jika ada
                    $nama_asli = '';
                    if (!empty($row['nama_asli'])) {
                        $nama_asli = dekripsi($row['nama_asli'], $row['npk']);
                    }
                    echo htmlspecialchars($nama_asli ?: '—');
                    ?>
                </td>
                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $row['status'] === 'Aktif' ? '#1D4ED8' : '#64748B'; ?>; white-space:nowrap;"><?= $row['status']; ?></td>

                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['status_ktp']); ?>; white-space:nowrap;"><?= $row['status_ktp']; ?></td>
                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['status_kk']); ?>; white-space:nowrap;"><?= $row['status_kk']; ?></td>

                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['lapor_Q1']); ?>; white-space:nowrap;"><?= $row['lapor_Q1']; ?></td>
                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['lapor_Q2']); ?>; white-space:nowrap;"><?= $row['lapor_Q2']; ?></td>
                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['lapor_Q3']); ?>; white-space:nowrap;"><?= $row['lapor_Q3']; ?></td>
                <td class="td-data" style="text-align:center; font-weight:bold; color:<?= $status_color($row['lapor_Q4']); ?>; white-space:nowrap;"><?= $row['lapor_Q4']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>