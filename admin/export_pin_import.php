<?php
// file: admin/export_pin_import.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/audit.php';
require_once '../config/crypto.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$batch_id = $_GET['batch'] ?? '';
$key = 'pin_import_' . $batch_id;

if (!preg_match('/^[a-f0-9]{16}$/', $batch_id) || !isset($_SESSION[$key])) {
    header("Location: import_pensiunan.php?error=Data PIN tidak ditemukan atau sudah pernah diunduh.");
    exit;
}

// Sekali pakai: kunci dihapus sebelum dibaca, sehingga unduhan kedua tidak mungkin.
$blob = $_SESSION[$key];
unset($_SESSION[$key]);

if (time() > (int)($blob['expires'] ?? 0)) {
    header("Location: import_pensiunan.php?error=Data PIN sudah kedaluwarsa. Lakukan reset PIN untuk pensiunan tersebut.");
    exit;
}

$daftar = json_decode((string)dekripsi($blob['data'] ?? '', $batch_id), true);
if (!is_array($daftar) || empty($daftar)) {
    header("Location: import_pensiunan.php?error=Data PIN tidak dapat dibaca.");
    exit;
}

// Catat ekspor per pensiunan ke audit. PIN tidak ditulis ke log.
foreach ($daftar as $d) {
    audit_tulis($db, $d['npk'], 'pensiunan', 'export_pin', null, 'Ekspor PIN batch import', $_SESSION['admin_username']);
}

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
    $sheet->setCellValueExplicit('A' . $baris, $d['npk'], DataType::TYPE_STRING);
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