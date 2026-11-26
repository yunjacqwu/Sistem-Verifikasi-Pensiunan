<?php
// file: admin/lihat_foto_lapor.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/audit.php';
require_once '../config/crypto.php';

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit;
}

$id   = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'inline';

if ($id <= 0) {
    http_response_code(400);
    exit;
}

try {
    $stmt = $db->prepare("SELECT foto, npk FROM lapor_diri WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row || empty($row['foto'])) {
        http_response_code(404);
        exit;
    }

    $binary = dekripsiFile($row['foto'], $row['npk']);

    if (empty($binary)) {
        http_response_code(500);
        exit;
    }

    // Deteksi tipe dari magic bytes
    if (str_starts_with($binary, "\xFF\xD8\xFF")) {
        $mime = 'image/jpeg';
        $ext  = 'jpg';
    } elseif (str_starts_with($binary, "\x89PNG")) {
        $mime = 'image/png';
        $ext  = 'png';
    } else {
        $mime = 'image/jpeg';
        $ext  = 'jpg';
    }

    // F-06: catat setiap akses foto ke audit sebelum dikirim. Jika pencatatan gagal, foto tidak dikirim.
    try {
                audit_tulis($db, $row['npk'], 'lapor_diri', 'akses_foto', null, $mode === 'download' ? 'Unduh foto lapor' : 'Lihat foto lapor', $_SESSION['admin_username']);
    } catch (PDOException $e) {
        error_log('Audit akses foto gagal: ' . get_class($e));
        http_response_code(500);
        exit;
    }

    $disposition = $mode === 'download'
        ? "attachment; filename=\"lapor_{$id}.{$ext}\""
        : "inline";

    header("Content-Type: $mime");
    header("Content-Disposition: $disposition");
    header("Content-Length: " . strlen($binary));
    header("Cache-Control: no-store");
    echo $binary;
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    exit;
}