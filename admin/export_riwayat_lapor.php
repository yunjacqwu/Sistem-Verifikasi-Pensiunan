<?php
// file: admin/export_riwayat_lapor.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

date_default_timezone_set('Asia/Jakarta');

// Parameter filter
$search         = trim($_GET['search']  ?? '');
$filter_kuartal = $_GET['kuartal']      ?? '';
$filter_tahun   = $_GET['tahun']        ?? date('Y'); // default tahun berjalan
$filter_status  = $_GET['status']       ?? '';

// Tentukan tahun yang dipakai
$tahun_export = !empty($filter_tahun) ? (int)$filter_tahun : (int)date('Y');

// Ambil semua pensiunan aktif
$stmt_pensiunan = $db->prepare("
    SELECT npk, nama FROM pensiunan WHERE status = 'Aktif' ORDER BY created_at ASC
");
$stmt_pensiunan->execute();
$semua_pensiunan = $stmt_pensiunan->fetchAll();

// Dekripsi nama
foreach ($semua_pensiunan as &$p) {
    $p['nama_plain'] = dekripsi($p['nama'], $p['npk']);
}
unset($p);

// Filter search
if (!empty($search)) {
    $search_lower = strtolower($search);
    $semua_pensiunan = array_filter($semua_pensiunan, function($p) use ($search_lower) {
        return str_contains(strtolower($p['nama_plain']), $search_lower)
            || str_contains(strtolower($p['npk']), $search_lower);
    });
    $semua_pensiunan = array_values($semua_pensiunan);
}

// Ambil data lapor diri tahun yang dipilih
$stmt_lapor = $db->prepare("
    SELECT npk, waktu, status_verifikasi, catatan_tolak
    FROM lapor_diri
    WHERE YEAR(waktu) = :tahun
    ORDER BY waktu ASC
");
$stmt_lapor->execute(['tahun' => $tahun_export]);
$semua_lapor = $stmt_lapor->fetchAll();

// Susun data lapor per NPK per kuartal
$lapor_map = []; // [npk][kuartal] = ['status'=>..., 'waktu'=>..., 'catatan'=>...]
foreach ($semua_lapor as $l) {
    $q = 'Q' . ceil(date('n', strtotime($l['waktu'])) / 3);
    $fmt = formatTanggal($l['waktu']);
    $lapor_map[$l['npk']][$q] = [
        'status'  => $l['status_verifikasi'],
        'waktu'   => $fmt['tanggal'] . ' ' . $fmt['jam'],
        'catatan' => $l['catatan_tolak'] ?? '',
    ];
}

// Filter status kalau dipilih
if (!empty($filter_status)) {
    $semua_pensiunan = array_filter($semua_pensiunan, function($p) use ($lapor_map, $filter_status) {
        foreach (['Q1','Q2','Q3','Q4'] as $q) {
            if (isset($lapor_map[$p['npk']][$q]) && $lapor_map[$p['npk']][$q]['status'] === $filter_status) {
                return true;
            }
        }
        return false;
    });
    $semua_pensiunan = array_values($semua_pensiunan);
}

// Filter kuartal — hanya tampilkan pensiunan yang lapor di kuartal tersebut
if (!empty($filter_kuartal)) {
    $q_filter = 'Q' . $filter_kuartal;
    $semua_pensiunan = array_filter($semua_pensiunan, function($p) use ($lapor_map, $q_filter) {
        return isset($lapor_map[$p['npk']][$q_filter]);
    });
    $semua_pensiunan = array_values($semua_pensiunan);
}

// ===== BUAT EXCEL =====
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Riwayat Lapor Diri ' . $tahun_export);

// Label kuartal
$kuartal_labels = [
    'Q1' => 'Q1 (Jan–Mar)',
    'Q2' => 'Q2 (Apr–Jun)',
    'Q3' => 'Q3 (Jul–Sep)',
    'Q4' => 'Q4 (Okt–Des)',
];

// Tentukan kuartal yang ditampilkan
$kuartal_tampil = !empty($filter_kuartal)
    ? ['Q' . $filter_kuartal => $kuartal_labels['Q' . $filter_kuartal]]
    : $kuartal_labels;

// Hitung total kolom: No + NPK + Nama + (kuartal × 1) 
$total_kolom = 2 + count($kuartal_tampil);
$last_col    = chr(64 + $total_kolom);

// === JUDUL ===
$sheet->mergeCells('A1:' . $last_col . '1');
$sheet->setCellValue('A1', 'REKAP LAPOR DIRI PENSIUNAN TAHUN ' . $tahun_export);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// === SUBTITLE ===
$filter_label = ['Tahun: ' . $tahun_export];
if (!empty($filter_kuartal)) {
    $ql = ['1'=>'Q1 (Jan–Mar)','2'=>'Q2 (Apr–Jun)','3'=>'Q3 (Jul–Sep)','4'=>'Q4 (Okt–Des)'];
    $filter_label[] = 'Kuartal: ' . ($ql[$filter_kuartal] ?? '');
}
if (!empty($filter_status))  $filter_label[] = 'Status: ' . $filter_status;
if (!empty($search))         $filter_label[] = 'Pencarian: ' . $search;

$sheet->mergeCells('A2:' . $last_col . '2');
$sheet->setCellValue('A2', implode(' | ', $filter_label));
$sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:' . $last_col . '3');
$sheet->setCellValue('A3', 'Diekspor pada: ' . date('d/m/Y H:i:s') . ' WIB');
$sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('94A3B8');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// === HEADER GRUP "Lapor Diri Tahun XXXX" ===
$sheet->mergeCells('C5:' . $last_col . '5');
$sheet->setCellValue('C5', 'Lapor Diri Tahun ' . $tahun_export);
$sheet->getStyle('C5:' . $last_col . '5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('D5:' . $last_col . '5')->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E40AF');
$sheet->getStyle('D5:' . $last_col . '5')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

// === HEADER KOLOM ===
$sheet->setCellValue('A6', 'NPK');
$sheet->setCellValue('B6', 'Nama');

$col_idx = 3; // mulai dari kolom D
foreach ($kuartal_tampil as $q_key => $q_label) {
    $col = chr(64 + $col_idx);
    $sheet->setCellValue($col . '6', $q_label);
    $col_idx++;
}

$sheet->getStyle('A6:' . $last_col . '6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A6:' . $last_col . '6')->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
$sheet->getStyle('A6:' . $last_col . '6')->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A6:' . $last_col . '6')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Header No, NPK, Nama merge 2 baris (row 5 dan 6)
foreach (['A', 'B'] as $col) {
    $sheet->mergeCells($col . '5:' . $col . '6');
    $sheet->getStyle($col . '5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($col . '5')->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
    $sheet->getStyle($col . '5')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
}
$sheet->setCellValue('A5', 'NPK');
$sheet->setCellValue('B5', 'Nama');

// === DATA ===
$row_num = 7;
foreach ($semua_pensiunan as $i => $p) {
    $sheet->setCellValue('A' . $row_num, $p['npk']);
    $sheet->setCellValue('B' . $row_num, $p['nama_plain']);

    $col_idx = 3;
    foreach ($kuartal_tampil as $q_key => $q_label) {
        $col = chr(64 + $col_idx);

        if (isset($lapor_map[$p['npk']][$q_key])) {
            $data   = $lapor_map[$p['npk']][$q_key];
            $status = $data['status'];
            $waktu  = $data['waktu'];

            // Isi sel: Status + waktu
            $sheet->setCellValue($col . $row_num, $status . "\n" . $waktu);
            $sheet->getStyle($col . $row_num)->getAlignment()->setWrapText(true);

            // Warna teks status
            $status_color = match($status) {
                'Disetujui'           => '16A34A',
                'Ditolak'             => 'DC2626',
                'Menunggu Verifikasi' => 'D97706',
                default               => '64748B',
            };
            $sheet->getStyle($col . $row_num)->getFont()
                ->setBold(true)->getColor()->setRGB($status_color);
        } else {
            $sheet->setCellValue($col . $row_num, '—');
            $sheet->getStyle($col . $row_num)->getFont()
                ->getColor()->setRGB('94A3B8');
            $sheet->getStyle($col . $row_num)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $col_idx++;
    }

    // Border baris
    $sheet->getStyle('A' . $row_num . ':' . $last_col . $row_num)
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Warna selang-seling
    if ($i % 2 === 1) {
        $sheet->getStyle('A' . $row_num . ':' . $last_col . $row_num)
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8FAFC');
    }

    $row_num++;
}

// === TOTAL ===
$sheet->mergeCells('A' . $row_num . ':B' . $row_num);
$sheet->setCellValue('A' . $row_num, 'Total: ' . count($semua_pensiunan) . ' pensiunan');
$sheet->getStyle('A' . $row_num)->getFont()->setBold(true)->setItalic(true);

// === AUTO SIZE ===
foreach (range('A', $last_col) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Format NPK sebagai teks
$sheet->getStyle('B:B')->getNumberFormat()->setFormatCode('@');

// === OUTPUT ===
$tanggal_file  = date('Ymd_His');
$filter_suffix = '_' . $tahun_export;
if (!empty($filter_kuartal)) $filter_suffix .= '_Q' . $filter_kuartal;
if (!empty($filter_status))  $filter_suffix .= '_' . str_replace(' ', '', $filter_status);

$filename = 'Riwayat_Lapor_Diri' . $filter_suffix . '_' . $tanggal_file . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;