<?php
// file: admin/lihat_profil.php
// Mengembalikan data profil satu pensiunan (didekripsi) untuk modal Detail.
// Setiap pembukaan dicatat ke audit sebelum data dikirim.
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/audit.php';
require_once '../config/crypto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$npk = trim($_GET['npk'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $npk)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$fmt_tgl = function (?string $tgl) use ($bulan): string {
    if (empty($tgl)) return '-';
    return (int)date('j', strtotime($tgl)) . ' ' . $bulan[(int)date('n', strtotime($tgl))] . ' ' . date('Y', strtotime($tgl));
};

try {
    $stmt = $db->prepare("SELECT * FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    // F-06 / audit: setiap pembukaan profil dicatat. Jika pencatatan gagal, data tidak dikirim.
    try {
        audit_tulis($db, $npk, 'pensiunan', 'akses_profil', null, 'Lihat profil', $_SESSION['admin_username']);
    } catch (PDOException $e) {
        error_log('Audit lihat profil gagal: ' . get_class($e));
        http_response_code(500);
        echo json_encode(['error' => 'audit_gagal']);
        exit;
    }

    echo json_encode([
        'npk'               => $p['npk'],
        'nama_plain'        => dekripsi($p['nama'], $npk),
        'nik_plain'         => dekripsi($p['nik'], $npk),
        'tmpt_lhr_plain'    => dekripsi($p['tmpt_lhr'], $npk),
        'telp_plain'        => dekripsi($p['telp'], $npk),
        'telp2_plain'       => dekripsi($p['telp2'], $npk),
        'alamat_plain'      => dekripsi($p['alamat'], $npk),
        'nama_asli_plain'   => !empty($p['nama_asli']) ? dekripsi($p['nama_asli'], $npk) : '',
        'jns_penerima'      => $p['jns_penerima'] ?? 'Pensiunan Asli',
        'jk'                => $p['jk'],
        'jk_label'          => $p['jk'] === 'L' ? 'Laki-laki' : ($p['jk'] === 'P' ? 'Perempuan' : '-'),
        'status'            => $p['status'],
        'jns_mnft'          => $p['jns_mnft'],
        'tgl_lhr'           => $p['tgl_lhr'],
        'tgl_msk'           => $p['tgl_msk'],
        'tgl_psn'           => $p['tgl_psn'],
        'tgl_msk_fmt'       => $fmt_tgl($p['tgl_msk']),
        'tgl_psn_fmt'       => $fmt_tgl($p['tgl_psn']),
    ]);
} catch (PDOException $e) {
    error_log('Lihat profil gagal: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['error' => 'server']);
}
