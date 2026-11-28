<?php
// file: tests/security/rate_limit_test.php
// Tes rate limit login (config/security.php) terhadap database tes db_absensi_test.
// Jalankan: php tests/security/rate_limit_test.php
// Variabel yang dibutuhkan (diset di terminal, bukan di file):
//   TEST_DB_DSN   contoh: mysql:host=localhost;dbname=db_absensi_test;charset=utf8mb4
//   TEST_DB_USER  dan TEST_DB_PASS

require_once __DIR__ . '/../../config/security.php';

$dsn  = (string)getenv('TEST_DB_DSN');
$user = (string)getenv('TEST_DB_USER');
$pass = (string)getenv('TEST_DB_PASS');

// Penjaga: tes hanya boleh jalan di database tes, tidak pernah di database dev/produksi.
if (!str_contains($dsn, 'dbname=db_absensi_test')) {
    fwrite(STDERR, "TEST_DB_DSN harus mengarah ke dbname=db_absensi_test. Tes dibatalkan.\n");
    exit(2);
}

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$_SERVER['REMOTE_ADDR'] = '10.99.99.99';
$konteks = 'tes_rl';
$lain = 'tes_rl_lain';

$gagal = 0;
$lulus = 0;

function cek(bool $kondisi, string $nama, int &$gagal, int &$lulus): void {
    if ($kondisi) {
        $lulus++;
        echo "PASS  $nama\n";
    } else {
        $gagal++;
        echo "FAIL  $nama\n";
    }
}

function bersihkan(PDO $db, string $ip, array $konteks): void {
    $st = $db->prepare("DELETE FROM login_attempts WHERE ip = :ip AND context = :c");
    foreach ($konteks as $c) {
        $st->execute(['ip' => $ip, 'c' => $c]);
    }
}

$ip = '10.99.99.99';
bersihkan($db, $ip, [$konteks, $lain]);

// Empat percobaan gagal belum mengunci. Percobaan kelima mengunci.
for ($i = 0; $i < 4; $i++) {
    rate_limit_hit($db, $konteks, 5, 600);
}
cek(rate_limit_check($db, $konteks, 5, 600) === 0, 'empat percobaan gagal belum mengunci', $gagal, $lulus);
rate_limit_hit($db, $konteks, 5, 600);
$sisa = rate_limit_check($db, $konteks, 5, 600);
cek($sisa > 0 && $sisa <= 600, 'percobaan kelima mengunci selama window', $gagal, $lulus);
cek(rate_limit_check($db, $konteks, 5, 600) > 0, 'kunci tetap aktif pada pengecekan berikutnya', $gagal, $lulus);

// Konteks lain tidak ikut terkunci.
cek(rate_limit_check($db, $lain, 5, 600) === 0, 'konteks lain tidak terkunci', $gagal, $lulus);

// Login berhasil menghapus hitungan dan kunci.
rate_limit_reset($db, $konteks);
cek(rate_limit_check($db, $konteks, 5, 600) === 0, 'reset menghapus kunci', $gagal, $lulus);

// Setelah window lewat, hitungan mulai dari nol.
for ($i = 0; $i < 5; $i++) {
    rate_limit_hit($db, $konteks, 5, 600);
}
cek(rate_limit_check($db, $konteks, 5, 600) > 0, 'kunci kembali setelah lima kegagalan', $gagal, $lulus);
$db->prepare("UPDATE login_attempts SET first_attempt = :t, locked_until = 0 WHERE ip = :ip AND context = :c")
   ->execute(['t' => time() - 601, 'ip' => $ip, 'c' => $konteks]);
cek(rate_limit_check($db, $konteks, 5, 600) === 0, 'window lewat membuka kunci', $gagal, $lulus);
rate_limit_hit($db, $konteks, 5, 600);
$hitungan = $db->prepare("SELECT attempts FROM login_attempts WHERE ip = :ip AND context = :c");
$hitungan->execute(['ip' => $ip, 'c' => $konteks]);
cek((int)$hitungan->fetchColumn() === 1, 'hitungan mulai dari 1 setelah window lewat', $gagal, $lulus);

bersihkan($db, $ip, [$konteks, $lain]);

echo "\nLulus: $lulus, gagal: $gagal\n";
exit($gagal === 0 ? 0 : 1);
