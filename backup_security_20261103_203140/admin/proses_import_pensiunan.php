<?php
// file: admin/proses_import_pensiunan.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once __DIR__ . '/../vendor/autoload.php';

ini_set('max_execution_time', 300); // 5 menit
ini_set('memory_limit', '256M');

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file_excel']['name'])) {
    header("Location: import_pensiunan.php?error=File tidak ditemukan.");
    exit;
}

$file = $_FILES['file_excel'];
$ekstensi_ok = ['xlsx', 'xls', 'csv'];
$ekstensi = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ekstensi, $ekstensi_ok)) {
    header("Location: import_pensiunan.php?error=Format file tidak didukung.");
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    header("Location: import_pensiunan.php?error=Ukuran file maksimal 5 MB.");
    exit;
}

function parseTanggal($val): ?string {
    if (empty($val)) return null;
    // Excel date serial number
    if (is_numeric($val)) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true); // keyed by column letter

    $batch_id = bin2hex(random_bytes(8));
    $sukses = 0;
    $gagal  = [];

    $stmt_cek = $db->prepare("SELECT npk FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt_ins = $db->prepare("
        INSERT INTO pensiunan
            (npk, nama, nama_asli, nik, jk, tmpt_lhr, tgl_lhr,
            telp, telp2, alamat, tgl_msk, tgl_psn, jns_mnft, status,
            jns_penerima, pin_hash, is_first_login)
        VALUES
            (:npk, :nama, :nama_asli, :nik, :jk, :tmpt_lhr, :tgl_lhr,
            :telp, :telp2, :alamat, :tgl_msk, :tgl_psn, :jns_mnft, :status,
            :jns_penerima, :pin_hash, 1)
    ");
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', 'nama', NULL, :nama_enc, :oleh)
    ");

    foreach ($rows as $no_baris => $row) {
        if ($no_baris == 1) continue; // skip header
        if ($no_baris == 2 && trim((string)$row['A']) === '0099') continue; // skip baris contoh

        $npk = trim((string)($row['A'] ?? ''));
        if (empty($npk)) continue; // baris kosong, skip diam-diam

        $nama     = trim((string)($row['B'] ?? ''));
        $nik      = trim((string)($row['C'] ?? ''));
        $jk_raw   = trim(strtoupper((string)($row['D'] ?? '')));
        $jk       = in_array($jk_raw, ['L', 'P']) ? $jk_raw : null;
        $tmpt_lhr = trim((string)($row['E'] ?? ''));
        $tgl_lhr  = parseTanggal($row['F'] ?? null);
        $telp     = trim((string)($row['G'] ?? ''));
        $telp2    = null; // kolom cadangan dihapus, set null
        $alamat   = trim((string)($row['H'] ?? ''));
        $tgl_msk  = parseTanggal($row['I'] ?? null);
        $tgl_psn  = parseTanggal($row['J'] ?? null);
        $jns_mnft = trim((string)($row['K'] ?? ''));
        $status   = trim((string)($row['L'] ?? 'Aktif'));
        $status   = in_array($status, ['Aktif', 'Tidak Aktif']) ? $status : 'Aktif';
        $jenis_penerima    = 'Pensiunan Asli'; // default
        $nama_asli_import  = ''; // default

        // Validasi jenis penerima
        $jenis_penerima_valid = ['Pensiunan Asli', 'Janda/Duda', 'Anak'];
        if (!in_array($jenis_penerima, $jenis_penerima_valid)) {
            $jenis_penerima = 'Pensiunan Asli';
        }

        // Validasi wajib
        if (empty($nama) || empty($telp)) {
            $gagal[] = ['baris' => $no_baris, 'npk' => $npk, 'alasan' => 'Nama atau No. Telepon kosong'];
            continue;
        }

        // Cek duplikasi NPK
        $stmt_cek->execute(['npk' => $npk]);
        if ($stmt_cek->fetch()) {
            $gagal[] = ['baris' => $no_baris, 'npk' => $npk, 'alasan' => 'NPK sudah terdaftar'];
            continue;
        }

        try {
            $pin_hash = password_hash($npk, PASSWORD_DEFAULT); // PIN = NPK

            $nama_enc     = enkripsi($nama, $npk);
            $nik_enc      = !empty($nik)      ? enkripsi($nik, $npk)      : null;
            $tmpt_lhr_enc = !empty($tmpt_lhr) ? enkripsi($tmpt_lhr, $npk) : null;
            $telp_enc     = enkripsi($telp, $npk);
            $telp2_enc    = !empty($telp2)    ? enkripsi($telp2, $npk)   : null;
            $alamat_enc   = !empty($alamat)   ? enkripsi($alamat, $npk)  : null;

            $stmt_ins->execute([
                'npk'      => $npk,
                'nama'     => $nama_enc,
                'nik'      => $nik_enc,
                'jk'       => $jk,
                'tmpt_lhr' => $tmpt_lhr_enc,
                'tgl_lhr'  => $tgl_lhr,
                'telp'     => $telp_enc,
                'telp2'    => $telp2_enc,
                'alamat'   => $alamat_enc,
                'tgl_msk'  => $tgl_msk,
                'tgl_psn'  => $tgl_psn,
                'jns_mnft' => !empty($jns_mnft) ? $jns_mnft : null,
                'status'   => $status,
                'pin_hash' => $pin_hash,
                'nama_asli'    => !empty($nama_asli_import) ? enkripsi($nama_asli_import, $npk) : null,
                'jns_penerima' => $jenis_penerima,
            ]);

            $stmt_log->execute([
                'npk'      => $npk,
                'nama_enc' => $nama_enc,
                'oleh'     => $_SESSION['admin_username'],
            ]);

            $sukses++;

        } catch (Exception $e) {
            $gagal[] = ['baris' => $no_baris, 'npk' => $npk, 'alasan' => 'Gagal memproses data baris ini'];
        }
    }


    $_SESSION['hasil_import'] = [
        'batch_id' => $batch_id,
        'sukses'   => $sukses,
        'gagal'    => $gagal,
    ];

    header("Location: import_pensiunan.php");
    exit;

} catch (Exception $e) {
    header("Location: import_pensiunan.php?error=Gagal membaca file Excel. Pastikan format sesuai template.");
    exit;
}