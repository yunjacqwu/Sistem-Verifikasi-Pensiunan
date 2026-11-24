<?php
// file: admin/proses_reset_pin.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/audit.php';
require_once '../config/csrf.php';
require_once '../config/security.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('data_pensiunan.php');
    $npk = trim($_POST['npk'] ?? '');

    if (empty($npk)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Data tidak valid.']);
        exit;
    }

    $pin_baru = generatePinAcak($npk); // F-03: PIN acak, bukan NPK

    try {
        $pin_hash = password_hash($pin_baru, PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            UPDATE pensiunan 
            SET pin_hash = :pin_hash, is_first_login = 1 
            WHERE npk = :npk
        ");
        $stmt->execute([
            'pin_hash' => $pin_hash,
            'npk'      => $npk,
        ]);

        // Catat aktivitas reset PIN ke audit log
                audit_tulis($db, $npk, 'pensiunan', 'pin_hash', null, null, $_SESSION['admin_username']);

        // PIN dikembalikan sebagai JSON dan ditampilkan di modal. Tidak disimpan di session atau log.
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['npk' => $npk, 'pin' => $pin_baru]);
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Gagal memperbarui PIN di database.']);
        exit;
    }
} else {
    header("Location: data_pensiunan.php");
    exit;
}