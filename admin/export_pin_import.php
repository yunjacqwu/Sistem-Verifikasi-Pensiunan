<?php
// file: admin/export_pin_import.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$batch_id = $_GET['batch'] ?? '';
$key = 'pin_import_' . $batch_id;

if (empty($batch_id) || !isset($_SESSION[$key])) {
    header("Location: import_pensiunan.php?error=Data PIN tidak ditemukan atau sudah pernah diunduh.");
    exit;
}

$daftar = $_SESSION[$key];
unset($_SESSION[$key]); // hapus setelah diakses sekali — tidak bisa didownload ulang

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Daftar PIN');

$sheet->setCellValue('A1', 'NPK');
$sheet->setCellValue('B1', 'Nama');
$sheet->setCellValue('C1', 'PIN Awal');

$sheet->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');

foreach ($daftar as $i => $d) {
    $baris = $i + 2;
    $sheet->setCellValue('A' . $baris, $d['npk']);
    $sheet->setCellValue('B' . $baris, $d['nama']);
    $sheet->setCellValue('C' . $baris, $d['pin']);
}

$sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
$sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');

foreach (['A', 'B', 'C'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Daftar_PIN_Import_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;