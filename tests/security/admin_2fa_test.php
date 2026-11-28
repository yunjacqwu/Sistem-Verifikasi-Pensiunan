<?php
// file: tests/security/admin_2fa_test.php
// Tes kode cadangan, ingat perangkat, anti-replay TOTP, dan transaksi, terhadap db_absensi_test.
// Jalankan: php tests/security/admin_2fa_test.php
// Variabel (diset di terminal): TEST_DB_DSN, TEST_DB_USER, TEST_DB_PASS.
// Tidak membaca .env: kunci hanya dibutuhkan untuk rahasia TOTP terenkripsi, yang tidak diuji di sini.

ob_start();
require_once __DIR__ . '/../../config/admin_2fa.php';

$dsn  = (string)getenv('TEST_DB_DSN');
$user = (string)getenv('TEST_DB_USER');
$pass = (string)getenv('TEST_DB_PASS');

if (!str_contains($dsn, 'dbname=db_absensi_test')) {
    ob_end_clean();
    fwrite(STDERR, "TEST_DB_DSN harus mengarah ke dbname=db_absensi_test. Tes dibatalkan.\n");
    exit(2);
}

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$A = 9001;
$B = 9002;
$hasil = [];

function cek(bool $kondisi, string $nama, array &$hasil): void {
    $hasil[] = [$kondisi, $nama];
}

function bersihkan_admin(PDO $db, array $ids): void {
    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM admin_sistem WHERE id IN ($in)");
}

function buat_admin(PDO $db, int $id, string $username): void {
    $db->prepare("INSERT INTO admin_sistem (id, username, totp_aktif) VALUES (:id, :u, 1)")
       ->execute(['id' => $id, 'u' => $username]);
}

function hitung(PDO $db, string $sql, array $param): int {
    $st = $db->prepare($sql);
    $st->execute($param);
    return (int)$st->fetchColumn();
}

bersihkan_admin($db, [$A, $B]);
buat_admin($db, $A, 'tes2fa_a');
buat_admin($db, $B, 'tes2fa_b');

// ---- ringkasan user agent (fungsi murni) ----
cek(admin_ringkas_ua('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36 Edg/120.0') === 'Edge di Windows', 'UA Edge di Windows', $hasil);
cek(admin_ringkas_ua('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Version/17.0 Safari/604.1') === 'Safari di iOS', 'UA Safari di iOS', $hasil);
cek(admin_ringkas_ua('Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36') === 'Chrome di Android', 'UA Chrome di Android', $hasil);
cek(admin_ringkas_ua(null) === 'Browser lain di OS lain', 'UA kosong tidak error', $hasil);

// ---- kode cadangan ----
$kode = admin_2fa_buat_kode_cadangan($db, $A);
cek(count($kode) === 8, 'delapan kode cadangan dibuat', $hasil);
$pola = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/';
cek(count(array_filter($kode, fn($k) => preg_match($pola, $k))) === 8, 'format XXXX-XXXX tanpa huruf membingungkan', $hasil);
cek(count(array_unique($kode)) === 8, 'delapan kode berbeda', $hasil);

$hash = $db->prepare("SELECT kode_hash FROM admin_kode_cadangan WHERE admin_id = :id");
$hash->execute(['id' => $A]);
$baris = $hash->fetchAll(PDO::FETCH_COLUMN);
cek(count($baris) === 8, 'database menyimpan delapan baris', $hasil);
cek(count(array_filter($baris, fn($h) => str_starts_with($h, '$2y$'))) === 8, 'database hanya menyimpan hash bcrypt', $hasil);
cek(count(array_filter($baris, fn($h) => in_array($h, $kode, true))) === 0, 'kode asli tidak tersimpan di database', $hasil);

cek(admin_2fa_pakai_kode_cadangan($db, $A, strtolower($kode[0]) . ' ') === true, 'kode benar diterima (huruf kecil, spasi)', $hasil);
cek(admin_2fa_pakai_kode_cadangan($db, $A, $kode[0]) === false, 'kode yang sudah dipakai ditolak', $hasil);
cek(admin_2fa_pakai_kode_cadangan($db, $A, $kode[1]) === true, 'kode kedua tetap berlaku', $hasil);
cek(admin_2fa_pakai_kode_cadangan($db, $A, 'ZZZZ-ZZZZ') === false, 'kode salah ditolak', $hasil);
cek(admin_2fa_pakai_kode_cadangan($db, $B, $kode[2]) === false, 'kode admin lain ditolak', $hasil);

$kode_b = admin_2fa_buat_kode_cadangan($db, $B);
cek(admin_2fa_pakai_kode_cadangan($db, $A, $kode_b[0]) === false, 'kode admin B tidak bisa dipakai untuk A', $hasil);

// ---- anti-replay TOTP di database ----
cek(admin_2fa_catat_langkah($db, $A, 100) === true, 'langkah 100 dicatat', $hasil);
cek(admin_2fa_catat_langkah($db, $A, 100) === false, 'langkah 100 yang sama ditolak', $hasil);
cek(admin_2fa_catat_langkah($db, $A, 101) === true, 'langkah 101 diterima', $hasil);

// Transaksi dibatalkan: langkah tidak boleh tersimpan.
$db->beginTransaction();
admin_2fa_catat_langkah($db, $A, 200);
$db->rollBack();
$langkah = hitung($db, "SELECT COALESCE(totp_langkah_terakhir, 0) FROM admin_sistem WHERE id = :id", ['id' => $A]);
cek($langkah === 101, 'langkah dalam transaksi yang dibatalkan tidak tersimpan', $hasil);

// ---- ingat perangkat ----
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36';
admin_perangkat_buat($db, $A);
cek(hitung($db, "SELECT COUNT(*) FROM admin_perangkat_dipercaya WHERE admin_id = :id", ['id' => $A]) === 1, 'buat perangkat menambah satu baris', $hasil);
$ua = hitung($db, "SELECT COUNT(*) FROM admin_perangkat_dipercaya WHERE admin_id = :id AND user_agent LIKE 'Mozilla%'", ['id' => $A]);
cek($ua === 1, 'user agent tersimpan', $hasil);

// Token yang diketahui untuk tes cek, cabut, dan daftar.
$token = bin2hex(random_bytes(32));
$hash_token = hash('sha256', $token);
$db->prepare("INSERT INTO admin_perangkat_dipercaya (admin_id, token_hash, user_agent, kedaluwarsa_pada) VALUES (:id, :h, 'tes', DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute(['id' => $A, 'h' => $hash_token]);
$id_token = (int)$db->lastInsertId();

$_COOKIE[admin_ingat_nama($A)] = $token;
cek(admin_perangkat_cek($db, $A) === true, 'token benar diterima', $hasil);
cek(hitung($db, "SELECT COUNT(*) FROM admin_perangkat_dipercaya WHERE id = :id AND terakhir_dipakai IS NOT NULL", ['id' => $id_token]) === 1, 'pemakaian memperbarui terakhir_dipakai', $hasil);

$_COOKIE[admin_ingat_nama($A)] = bin2hex(random_bytes(32));
cek(admin_perangkat_cek($db, $A) === false, 'token acak ditolak', $hasil);

$_COOKIE[admin_ingat_nama($A)] = 'bukan-token';
cek(admin_perangkat_cek($db, $A) === false, 'format cookie salah ditolak', $hasil);

// Cookie dibuat per admin: cookie admin A tidak berlaku untuk admin B.
$_COOKIE[admin_ingat_nama($B)] = $token;
cek(admin_perangkat_cek($db, $B) === false, 'token admin A tidak berlaku untuk admin B', $hasil);

// Perangkat yang sudah kedaluwarsa ditolak.
$token_lewat = bin2hex(random_bytes(32));
$db->prepare("INSERT INTO admin_perangkat_dipercaya (admin_id, token_hash, kedaluwarsa_pada) VALUES (:id, :h, DATE_SUB(NOW(), INTERVAL 1 DAY))")
   ->execute(['id' => $A, 'h' => hash('sha256', $token_lewat)]);
$_COOKIE[admin_ingat_nama($A)] = $token_lewat;
cek(admin_perangkat_cek($db, $A) === false, 'perangkat kedaluwarsa ditolak', $hasil);

// Daftar: hanya yang belum kedaluwarsa, tanda 'ini' untuk perangkat aktif, tanpa hash.
$_COOKIE[admin_ingat_nama($A)] = $token;
$daftar = admin_perangkat_daftar($db, $A);
cek(count($daftar) === 2, 'daftar hanya memuat perangkat yang belum kedaluwarsa', $hasil);
cek(count(array_filter($daftar, fn($p) => $p['ini'] === true)) === 1, 'tepat satu perangkat bertanda ini', $hasil);
cek(count(array_filter($daftar, fn($p) => array_key_exists('token_hash', $p))) === 0, 'hash token tidak ikut ke daftar', $hasil);

// Cabut: milik sendiri berhasil, milik admin lain gagal.
$db->prepare("INSERT INTO admin_perangkat_dipercaya (admin_id, token_hash, kedaluwarsa_pada) VALUES (:id, :h, DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute(['id' => $B, 'h' => hash('sha256', bin2hex(random_bytes(32)))]);
$id_b = (int)$db->lastInsertId();
cek(admin_perangkat_cabut($db, $A, $id_b) === false, 'cabut perangkat admin lain ditolak', $hasil);
cek(admin_perangkat_cabut($db, $A, $id_token) === true, 'cabut perangkat sendiri berhasil', $hasil);
cek(admin_perangkat_cek($db, $A) === false, 'perangkat yang dicabut tidak lagi tepercaya', $hasil);

// Reset 2FA: kode dan perangkat admin A hilang, admin B tidak terpengaruh.
admin_2fa_reset($db, $A);
cek(hitung($db, "SELECT totp_aktif FROM admin_sistem WHERE id = :id", ['id' => $A]) === 0, 'reset mematikan 2FA', $hasil);
cek(hitung($db, "SELECT COUNT(*) FROM admin_kode_cadangan WHERE admin_id = :id", ['id' => $A]) === 0, 'reset menghapus kode cadangan', $hasil);
cek(hitung($db, "SELECT COUNT(*) FROM admin_perangkat_dipercaya WHERE admin_id = :id", ['id' => $A]) === 0, 'reset mencabut semua perangkat', $hasil);
cek(hitung($db, "SELECT COUNT(*) FROM admin_kode_cadangan WHERE admin_id = :id", ['id' => $B]) === 8, 'reset admin A tidak menghapus kode admin B', $hasil);
cek(hitung($db, "SELECT COUNT(*) FROM admin_perangkat_dipercaya WHERE admin_id = :id", ['id' => $B]) === 1, 'reset admin A tidak mencabut perangkat admin B', $hasil);

bersihkan_admin($db, [$A, $B]);
ob_end_clean();

$lulus = 0;
$gagal = 0;
foreach ($hasil as [$ok, $nama]) {
    if ($ok) {
        $lulus++;
        echo "PASS  $nama\n";
    } else {
        $gagal++;
        echo "FAIL  $nama\n";
    }
}
echo "\nLulus: $lulus, gagal: $gagal\n";
exit($gagal === 0 ? 0 : 1);
