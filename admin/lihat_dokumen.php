<?php
// file: admin/lihat_dokumen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit;
}

$id   = (int)($_GET['id']   ?? 0);
$mode = $_GET['mode'] ?? 'inline';

if ($id <= 0) {
    http_response_code(400);
    exit;
}

try {
    $stmt = $db->prepare("SELECT file, npk, jns_dok FROM dokumen WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row || empty($row['file'])) {
        http_response_code(404);
        exit;
    }

    $binary = dekripsiFile($row['file'], $row['npk']);

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
    } elseif (str_starts_with($binary, "%PDF")) {
        $mime = 'application/pdf';
        $ext  = 'pdf';
    } else {
        $mime = 'application/octet-stream';
        $ext  = 'bin';
    }

    $disposition = $mode === 'download'
        ? "attachment; filename=\"{$row['npk']}_{$row['jns_dok']}.{$ext}\""
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