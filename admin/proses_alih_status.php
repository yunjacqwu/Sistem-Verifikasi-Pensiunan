<?php
// file: admin/proses_alih_status.php
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

$npk            = trim($_POST['npk']            ?? '');
$jenis_penerima = trim($_POST['jenis_penerima'] ?? '');
$nama_baru      = trim($_POST['nama_baru']      ?? '');
$tgl_lhr_baru   = trim($_POST['tgl_lhr_baru']   ?? '');
$jk_baru        = trim($_POST['jk_baru']        ?? '');
$telp_baru      = trim($_POST['telp_baru']      ?? '');
$telp2_baru     = trim($_POST['telp2_baru']     ?? '');
$alamat_baru    = trim($_POST['alamat_baru']    ?? '');

if (empty($npk) || empty($jenis_penerima) || empty($nama_baru) || empty($telp_baru)) {
    header("Location: data_pensiunan.php?error=Data tidak lengkap.");
    exit;
}

if (!in_array($jenis_penerima, ['Janda/Duda', 'Anak'])) {
    header("Location: data_pensiunan.php?error=Jenis penerima tidak valid.");
    exit;
}

// Validasi umur untuk penerima Anak
if ($jenis_penerima === 'Anak' && !empty($tgl_lhr_baru)) {
    $umur = (int)date_diff(date_create($tgl_lhr_baru), date_create('today'))->y;
    if ($umur >= 25) {
        header("Location: data_pensiunan.php?error=Penerima anak tidak memenuhi syarat (usia $umur tahun ≥ 25 tahun).");
        exit;
    }
}

try {
    // Ambil data pensiunan lama untuk disimpan sebagai histori
    $stmt_lama = $db->prepare("SELECT * FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt_lama->execute(['npk' => $npk]);
    $data_lama = $stmt_lama->fetch();

    if (!$data_lama) {
        header("Location: data_pensiunan.php?error=Data pensiunan tidak ditemukan.");
        exit;
    }

    // Enkripsi data baru
    $tmpt_lhr_baru     = trim($_POST['tmpt_lhr_baru'] ?? '');
    $nama_baru_enc     = enkripsi($nama_baru, $npk);
    $tmpt_lhr_baru_enc = !empty($tmpt_lhr_baru) ? enkripsi($tmpt_lhr_baru, $npk) : $data_lama['tmpt_lhr'];
    $telp_baru_enc     = enkripsi($telp_baru, $npk);
    $telp2_baru_enc    = !empty($telp2_baru) ? enkripsi($telp2_baru, $npk) : null;
    $alamat_baru_enc   = !empty($alamat_baru) ? enkripsi($alamat_baru, $npk) : $data_lama['alamat'];

    // Tentukan jenis manfaat otomatis
    $jns_mnft_baru = match($jenis_penerima) {
        'Janda/Duda' => 'Pensiun Janda/Duda',
        'Anak'       => 'Pensiun Anak',
        default      => $data_lama['jns_mnft'],
    };

    // Update data pensiunan
    $stmt = $db->prepare("
        UPDATE pensiunan SET
            nama           = :nama,
            nama_asli      = :nama_asli,
            tgl_lhr        = :tgl_lhr,
            jk             = :jk,
            telp           = :telp,
            telp2          = :telp2,
            alamat         = :alamat,
            tmpt_lhr       = :tmpt_lhr,
            jns_mnft       = :jns_mnft,
            jns_penerima   = :jns_penerima,
            tgl_alih       = CURDATE()
        WHERE npk = :npk
    ");
    $stmt->execute([
        'nama'         => $nama_baru_enc,
        'nama_asli'    => $data_lama['nama'],
        'tgl_lhr'      => !empty($tgl_lhr_baru) ? $tgl_lhr_baru : null,
        'jk'           => $jk_baru,
        'telp'         => $telp_baru_enc,
        'telp2'        => $telp2_baru_enc,
        'alamat'       => $alamat_baru_enc,
        'tmpt_lhr'     => $tmpt_lhr_baru_enc,
        'jns_mnft'     => $jns_mnft_baru,
        'jns_penerima' => $jenis_penerima,
        'npk'          => $npk,
    ]);

    // Catat ke audit log
    $nama_lama_plain = dekripsi($data_lama['nama'], $npk);
    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', :kolom, :lama, :baru, :oleh)
    ");
    
    // Log nama
    $stmt_log->execute([
        'npk'   => $npk,
        'kolom' => 'nama',
        'lama'  => $data_lama['nama'],
        'baru'  => $nama_baru_enc,
        'oleh'  => $_SESSION['admin_username'],
    ]);

    // Log jenis manfaat
    $stmt_log->execute([
        'npk'   => $npk,
        'kolom' => 'jns_mnft',
        'lama'  => $data_lama['jns_mnft'],
        'baru'  => $jns_mnft_baru,
        'oleh'  => $_SESSION['admin_username'],
    ]);

    // Log khusus alih status — sebagai penanda event utama
    $stmt_log->execute([
        'npk'   => $npk,
        'kolom' => 'alih_status',
        'lama'  => $data_lama['jns_penerima'] ?? 'Pensiunan Asli',
        'baru'  => $jenis_penerima,
        'oleh'  => $_SESSION['admin_username'],
    ]);

    header("Location: data_pensiunan.php?sukses=" . urlencode("Alih status penerima berhasil. Manfaat pensiun NPK $npk kini diterima oleh $nama_baru ($jenis_penerima)."));
    exit;

} catch (PDOException $e) {
    header("Location: data_pensiunan.php?error=Gagal memproses alih status.");
    exit;
}