<?php
// file: tests/security/totp_test.php
// Tes logika TOTP (RFC 6238). Jalankan: php tests/security/totp_test.php
// Hanya memuat config/totp.php: tidak membaca .env dan tidak menyentuh database.

require_once __DIR__ . '/../../config/totp.php';

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

// Rahasia RFC 6238 "12345678901234567890" dalam base32.
$rahasia = totp_base32_encode('12345678901234567890');

// Vektor RFC 6238 (SHA-1). Nilai 8 digit dipotong ke 6 digit: langkah = waktu / 30.
cek(totp_kode_pada_langkah($rahasia, intdiv(59, 30)) === '287082', 'vektor RFC t=59', $gagal, $lulus);
cek(totp_kode_pada_langkah($rahasia, intdiv(1111111109, 30)) === '081804', 'vektor RFC t=1111111109', $gagal, $lulus);
cek(totp_kode_pada_langkah($rahasia, intdiv(1234567890, 30)) === '005924', 'vektor RFC t=1234567890', $gagal, $lulus);

// Base32 bolak-balik.
$acak = random_bytes(20);
cek(totp_base32_decode(totp_base32_encode($acak)) === $acak, 'base32 bolak-balik', $gagal, $lulus);
cek(strlen(totp_buat_rahasia()) >= 16, 'rahasia baru cukup panjang', $gagal, $lulus);

// Kode benar pada langkah sekarang diterima, dan mengembalikan langkahnya.
$waktu = 1700000000;
$langkah = intdiv($waktu, 30);
$kode = totp_kode_pada_langkah($rahasia, $langkah);
cek(totp_cocokkan($rahasia, $kode, null, $waktu) === $langkah, 'kode benar diterima', $gagal, $lulus);

// Toleransi satu langkah ke belakang dan ke depan.
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah - 1), null, $waktu) === $langkah - 1, 'toleransi -1 langkah', $gagal, $lulus);
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah + 1), null, $waktu) === $langkah + 1, 'toleransi +1 langkah', $gagal, $lulus);

// Di luar toleransi ditolak.
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah + 2), null, $waktu) === null, 'langkah +2 ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah - 2), null, $waktu) === null, 'langkah -2 ditolak', $gagal, $lulus);

// Anti-replay: langkah yang sudah dipakai tidak boleh dipakai lagi, dan langkah lebih lama juga ditolak.
cek(totp_cocokkan($rahasia, $kode, $langkah, $waktu) === null, 'kode yang sudah dipakai ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah - 1), $langkah, $waktu) === null, 'langkah lebih lama dari terakhir ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, totp_kode_pada_langkah($rahasia, $langkah + 1), $langkah, $waktu) === $langkah + 1, 'langkah baru setelah terakhir diterima', $gagal, $lulus);

// Format kode: harus tepat 6 digit (spasi di tengah boleh, huruf dan panjang lain ditolak).
cek(totp_cocokkan($rahasia, substr($kode, 0, 3) . ' ' . substr($kode, 3), null, $waktu) === $langkah, 'spasi di tengah kode diabaikan', $gagal, $lulus);
cek(totp_cocokkan($rahasia, '12345', null, $waktu) === null, 'kode 5 digit ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, '1234567', null, $waktu) === null, 'kode 7 digit ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, 'abcdef', null, $waktu) === null, 'kode huruf ditolak', $gagal, $lulus);
cek(totp_cocokkan($rahasia, '', null, $waktu) === null, 'kode kosong ditolak', $gagal, $lulus);

// Rahasia lain tidak cocok dengan kode ini.
$lain = totp_base32_encode(random_bytes(20));
cek(totp_cocokkan($lain, $kode, null, $waktu) === null, 'kode dari rahasia lain ditolak', $gagal, $lulus);

echo "\nLulus: $lulus, gagal: $gagal\n";
exit($gagal === 0 ? 0 : 1);
