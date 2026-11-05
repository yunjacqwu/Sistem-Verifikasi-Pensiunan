<?php
// file: admin/generate_template.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Data Pensiunan');

$header = [
    'NPK',
    'Nama',
    'NIK',
    'Jenis Kelamin (L/P)',
    'Tempat Lahir',
    'Tanggal Lahir (YYYY-MM-DD)',
    'No. Telp Utama',
    'Alamat',
    'Tanggal Masuk (YYYY-MM-DD)',
    'Tanggal Pensiun (YYYY-MM-DD)',
    'Jenis Manfaat',
    'Status (Aktif/Tidak Aktif)',
];

// Tulis header — kolom A sampai O (15 kolom)
foreach ($header as $i => $label) {
    $col = $i < 26
        ? chr(65 + $i)
        : 'A' . chr(65 + ($i - 26)); // fallback kalau lebih dari 26 kolom
    $sheet->setCellValue($col . '1', $label);
}

// Styling header A1:L1
$sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A1:L1')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('1E293B');
$sheet->getStyle('A1:L1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Baris contoh 1 — pensiunan asli (baris 2)
$contoh = [
    '0099', 'Contoh Nama Pensiunan', '3201234567890099', 'L', 'Jakarta',
    '1960-05-10', '081234567890',
    'Jl. Contoh No. 1, Jakarta', '1990-01-01', '2020-01-01',
    'Pensiun Normal', 'Aktif',
];

foreach ($contoh as $i => $val) {
    $col = $i < 26 ? chr(65 + $i) : 'A' . chr(65 + ($i - 26));
    $sheet->setCellValue($col . '2', $val);
}

// Baris contoh 2 — janda/duda (baris 3)
$contoh2 = [
    '0100', 'Contoh Nama Janda', '3201234567890100', 'P', 'Bandung',
    '1965-03-15', '081234567891',
    'Jl. Contoh No. 2, Bandung', '1992-06-01', '2022-06-01',
    'Pensiun Janda/Duda', 'Aktif',
];

foreach ($contoh2 as $i => $val) {
    $col = $i < 26 ? chr(65 + $i) : 'A' . chr(65 + ($i - 26));
    $sheet->setCellValue($col . '3', $val);
}

// Styling kedua baris contoh
$sheet->getStyle('A2:L3')->getFont()->setItalic(true)->getColor()->setRGB('94A3B8');
// Lebar kolom otomatis A sampai O
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Format teks untuk kolom yang rawan notasi ilmiah
$sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@'); // NPK
$sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@'); // NIK
$sheet->getStyle('G:H')->getNumberFormat()->setFormatCode('@'); // No. Telp
$sheet->getStyle('N:N')->getNumberFormat()->setFormatCode('@'); // Jenis Penerima

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Template_Import_Pensiunan.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;