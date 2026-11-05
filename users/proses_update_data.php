<?php
// file: users/proses_update_data.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: data_diri.php");
    exit;
}

csrf_require('data_diri.php');

$npk    = $_SESSION['user_npk'];
$telp   = trim($_POST['telp'] ?? '');
$telp2  = trim($_POST['telp2'] ?? '');
$alamat = trim($_POST['alamat'] ?? '');

if (empty($telp) || empty($alamat)) {
    header("Location: data_diri.php?error=Nomor telepon dan alamat tidak boleh kosong.");
    exit;
}

try {
    // Ambil data lama untuk audit log
    $stmt_lama = $db->prepare("SELECT telp, telp2, alamat FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt_lama->execute(['npk' => $npk]);
    $data_lama = $stmt_lama->fetch();

    // Dekripsi nilai lama untuk perbandingan plaintext
    $telp_lama_plain   = trim(dekripsi($data_lama['telp'], $npk));
    $telp2_lama_plain  = !empty($data_lama['telp2']) ? trim(dekripsi($data_lama['telp2'], $npk)) : '';
    $alamat_lama_plain = trim(dekripsi($data_lama['alamat'], $npk));

    $telp2_baru_plain  = $telp2; // sudah trim di atas

    // Enkripsi nilai baru
    $telp_enc   = enkripsi($telp, $npk);
    $telp2_enc  = !empty($telp2) ? enkripsi($telp2, $npk) : null;
    $alamat_enc = enkripsi($alamat, $npk);

    // Update data
    $stmt = $db->prepare("
        UPDATE pensiunan
        SET telp = :telp, telp2 = :telp2, alamat = :alamat
        WHERE npk = :npk
    ");
    $stmt->execute([
        'telp'   => $telp_enc,
        'telp2'  => $telp2_enc,
        'alamat' => $alamat_enc,
        'npk'    => $npk,
    ]);

    // Catat audit log HANYA untuk kolom yang BENAR-BENAR berubah (bandingkan plaintext)
    $perubahan = [
        'telp'   => ['lama_plain' => $telp_lama_plain,   'baru_plain' => $telp,           'lama_enc' => $data_lama['telp'],   'baru_enc' => $telp_enc],
        'telp2'  => ['lama_plain' => $telp2_lama_plain,  'baru_plain' => $telp2_baru_plain, 'lama_enc' => $data_lama['telp2'],  'baru_enc' => $telp2_enc],
        'alamat' => ['lama_plain' => $alamat_lama_plain, 'baru_plain' => $alamat,          'lama_enc' => $data_lama['alamat'], 'baru_enc' => $alamat_enc],
    ];

    $stmt_log = $db->prepare("
        INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
        VALUES (:npk, 'pensiunan', :kolom, :lama, :baru, :oleh)
    ");

    foreach ($perubahan as $kolom => $v) {
        if ($v['lama_plain'] !== $v['baru_plain']) {
            $stmt_log->execute([
                'npk'   => $npk,
                'kolom' => $kolom,
                'lama'  => $v['lama_enc'],
                'baru'  => $v['baru_enc'],
                'oleh'  => $npk,
            ]);
        }
    }

    header("Location: data_diri.php?sukses=Data berhasil diperbarui.");
    exit;

} catch (PDOException $e) {
    header("Location: data_diri.php?error=Gagal menyimpan perubahan. Silakan coba lagi.");
    exit;
}