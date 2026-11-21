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

// Lebar kolom tetap, disesuaikan dengan jenis isi kolom (bukan hanya panjang header)
$lebar_kolom = ['A' => 14, 'B' => 36, 'C' => 22, 'D' => 20, 'E' => 20, 'F' => 28,
    'G' => 18, 'H' => 45, 'I' => 28, 'J' => 28, 'K' => 24, 'L' => 28];
foreach ($lebar_kolom as $col => $lebar) {
    $sheet->getColumnDimension($col)->setWidth($lebar);
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