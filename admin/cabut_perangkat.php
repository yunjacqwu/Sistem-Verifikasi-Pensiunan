<?php
// file: admin/cabut_perangkat.php
// Mencabut semua perangkat tepercaya milik admin yang sedang login.
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/csrf.php';
require_once '../config/audit.php';
require_once '../config/admin_2fa.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kelola_admin.php');
    exit;
}

csrf_require('kelola_admin.php');

try {
    $admin_id = (int)$_SESSION['admin_id'];
    if (isset($_POST['perangkat_id'])) {
        $perangkat_id = (int)$_POST['perangkat_id'];
        $ok = $perangkat_id > 0 && admin_perangkat_cabut($db, $admin_id, $perangkat_id);
        audit_tulis($db, null, 'admin_sistem', 'cabut_perangkat', null,
            'Cabut 1 perangkat tepercaya (id ' . $perangkat_id . ')', $_SESSION['admin_username']);
        $pesan = $ok ? 'Perangkat tepercaya sudah dicabut.' : 'Perangkat tidak ditemukan.';
        header('Location: kelola_admin.php?' . ($ok ? 'sukses=' : 'error=') . urlencode($pesan));
    } else {
        admin_perangkat_cabut_semua($db, $admin_id);
        audit_tulis($db, null, 'admin_sistem', 'cabut_perangkat', null,
            'Cabut semua perangkat tepercaya', $_SESSION['admin_username']);
        header('Location: kelola_admin.php?sukses=' . urlencode('Semua perangkat tepercaya Anda sudah dicabut.'));
    }
} catch (PDOException $e) {
    error_log('Cabut perangkat gagal: ' . get_class($e));
    header('Location: kelola_admin.php?error=' . urlencode('Gagal mencabut perangkat. Coba lagi.'));
}
exit;
