<?php
// file: admin/proses_edit_pensiunan.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: data_pensiunan.php");
    exit;
}

csrf_require('data_pensiunan.php');

$npk      = trim($_POST['npk'] ?? '');
$nama     = trim($_POST['nama_lengkap'] ?? '');
$nik      = trim($_POST['nik'] ?? '');
$tmpt_lhr = trim($_POST['tempat_lahir'] ?? '');
$tgl_lhr  = trim($_POST['tanggal_lahir'] ?? '');
$jk       = trim($_POST['jenis_kelamin'] ?? '');
$telp     = trim($_POST['no_telp'] ?? '');
$telp2    = trim($_POST['no_telp2'] ?? '');
$alamat   = trim($_POST['alamat'] ?? '');
$tgl_msk  = trim($_POST['tanggal_masuk'] ?? '');
$tgl_psn  = trim($_POST['tanggal_pensiun'] ?? '');
$jns_mnft = trim($_POST['jenis_manfaat_pensiun'] ?? '');
$status   = $_POST['status_pensiun'] ?? 'Aktif';

if (empty($npk) || empty($nama) || empty($telp)) {
    header("Location: data_pensiunan.php?error=NPK, Nama, dan No. Telepon wajib diisi.");
    exit;
}

try {
    // Ambil data lama untuk audit log
    $stmt_lama = $db->prepare("SELECT * FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt_lama->execute(['npk' => $npk]);
    $data_lama = $stmt_lama->fetch();

    if (!$data_lama) {
        header("Location: data_pensiunan.php?error=Data pensiunan tidak ditemukan.");
        exit;
    }

    // Enkripsi nilai baru
    $nama_enc     = enkripsi($nama, $npk);
    $nik_enc      = !empty($nik)      ? enkripsi($nik, $npk)      : null;
    $tmpt_lhr_enc = !empty($tmpt_lhr) ? enkripsi($tmpt_lhr, $npk) : null;
    $telp_enc     = enkripsi($telp, $npk);
    $telp2_enc    = !empty($telp2)    ? enkripsi($telp2, $npk)    : null;
    $alamat_enc   = !empty($alamat)   ? enkripsi($alamat, $npk)   : null;

    // Update data
    $stmt = $db->prepare("
        UPDATE pensiunan SET
            nama = :nama, nik = :nik, tmpt_lhr = :tmpt_lhr,
            tgl_lhr = :tgl_lhr, jk = :jk, telp = :telp, telp2 = :telp2,
            alamat = :alamat, tgl_msk = :tgl_msk, tgl_psn = :tgl_psn,
            jns_mnft = :jns_mnft, status = :status
        WHERE npk = :npk
    ");
    $stmt->execute([
        'nama'     => $nama_enc,
        'nik'      => $nik_enc,
        'tmpt_lhr' => $tmpt_lhr_enc,
        'tgl_lhr'  => !empty($tgl_lhr) ? $tgl_lhr : null,
        'jk'       => !empty($jk) ? $jk : null,
        'telp'     => $telp_enc,
        'telp2'    => $telp2_enc,
        'alamat'   => $alamat_enc,
        'tgl_msk'  => !empty($tgl_msk) ? $tgl_msk : null,
        'tgl_psn'  => !empty($tgl_psn) ? $tgl_psn : null,
        'jns_mnft' => !empty($jns_mnft) ? $jns_mnft : null,
        'status'   => $status,
        'npk'      => $npk,
    ]);

    // Catat audit log per kolom yang berubah
    // Catat audit log per kolom yang BENAR-BENAR berubah
    $normalize = function($v) {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    };

    // Kolom terenkripsi -> bandingkan PLAINTEXT (ciphertext selalu beda karena IV random)
    $kolom_enkripsi = [
        'nama'     => ['lama_plain' => trim(dekripsi($data_lama['nama'], $npk)),     'baru_plain' => trim($nama),     'lama_enc' => $data_lama['nama'],     'baru_enc' => $nama_enc],
        'nik'      => ['lama_plain' => trim(dekripsi($data_lama['nik'], $npk)),      'baru_plain' => trim($nik),      'lama_enc' => $data_lama['nik'],      'baru_enc' => $nik_enc],
        'tmpt_lhr' => ['lama_plain' => trim(dekripsi($data_lama['tmpt_lhr'], $npk)), 'baru_plain' => trim($tmpt_lhr), 'lama_enc' => $data_lama['tmpt_lhr'], 'baru_enc' => $tmpt_lhr_enc],
        'telp'     => ['lama_plain' => trim(dekripsi($data_lama['telp'], $npk)),     'baru_plain' => trim($telp),     'lama_enc' => $data_lama['telp'],     'baru_enc' => $telp_enc],
        'telp2'    => ['lama_plain' => trim(dekripsi($data_lama['telp2'], $npk)),    'baru_plain' => trim($telp2),    'lama_enc' => $data_lama['telp2'],    'baru_enc' => $telp2_enc],
        'alamat'   => ['lama_plain' => trim(dekripsi($data_lama['alamat'], $npk)),   'baru_plain' => trim($alamat),   'lama_enc' => $data_lama['alamat'],   'baru_enc' => $alamat_enc],
    ];

    // Kolom plaintext -> normalisasi NULL/'' supaya tidak dianggap "berubah" kalau sama-sama kosong
    $kolom_plain = [
        'tgl_lhr'  => [$normalize($data_lama['tgl_lhr']),  $normalize($tgl_lhr)],
        'jk'       => [$normalize($data_lama['jk']),       $normalize($jk)],
        'tgl_msk'  => [$normalize($data_lama['tgl_msk']),  $normalize($tgl_msk)],
        'tgl_psn'  => [$normalize($data_lama['tgl_psn']),  $normalize($tgl_psn)],
        'jns_mnft' => [$normalize($data_lama['jns_mnft']), $normalize($jns_mnft)],
        'status'   => [$normalize($data_lama['status']),   $normalize($status)],
    ];

    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', :kolom, :lama, :baru, :oleh)
    ");

    // Log kolom terenkripsi (bandingkan plaintext, simpan ciphertext)
    foreach ($kolom_enkripsi as $kolom => $v) {
        if ($v['lama_plain'] !== $v['baru_plain']) {
            $stmt_log->execute([
                'npk'   => $npk,
                'kolom' => $kolom,
                'lama'  => $v['lama_enc'],
                'baru'  => $v['baru_enc'],
                'oleh'  => $_SESSION['admin_username'],
            ]);
        }
    }

    // Log kolom plaintext
    foreach ($kolom_plain as $kolom => [$lama, $baru]) {
        if ($lama !== $baru) {
            $stmt_log->execute([
                'npk'   => $npk,
                'kolom' => $kolom,
                'lama'  => $lama,
                'baru'  => $baru,
                'oleh'  => $_SESSION['admin_username'],
            ]);
        }
    }

    header("Location: data_pensiunan.php?sukses=Data pensiunan berhasil diperbarui.");
    exit;

} catch (PDOException $e) {
    header("Location: data_pensiunan.php?error=Gagal memperbarui data. Silakan coba lagi.");
    exit;
}