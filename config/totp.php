<?php
// file: config/totp.php
// TOTP (RFC 6238) dengan HMAC-SHA1, kode 6 digit, langkah 30 detik.
// Tidak memakai library eksternal. Diuji dengan vektor resmi RFC 6238.

const TOTP_ALFABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_base32_encode(string $biner): string {
    $bit = '';
    foreach (str_split($biner) as $c) {
        $bit .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $hasil = '';
    foreach (str_split($bit, 5) as $potongan) {
        if (strlen($potongan) < 5) {
            $potongan = str_pad($potongan, 5, '0');
        }
        $hasil .= TOTP_ALFABET[bindec($potongan)];
    }
    return $hasil;
}

function totp_base32_decode(string $teks): string {
    $teks = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $teks));
    $bit = '';
    foreach (str_split($teks) as $c) {
        $nilai = strpos(TOTP_ALFABET, $c);
        if ($nilai === false) {
            continue;
        }
        $bit .= str_pad(decbin($nilai), 5, '0', STR_PAD_LEFT);
    }
    $hasil = '';
    foreach (str_split($bit, 8) as $potongan) {
        if (strlen($potongan) === 8) {
            $hasil .= chr(bindec($potongan));
        }
    }
    return $hasil;
}

// Rahasia 20 byte acak, dikembalikan dalam base32 (format yang dibaca aplikasi autentikator).
function totp_buat_rahasia(): string {
    return totp_base32_encode(random_bytes(20));
}

// Kode untuk satu langkah waktu.
function totp_kode_pada_langkah(string $rahasia_base32, int $langkah): string {
    $kunci = totp_base32_decode($rahasia_base32);
    $pesan = pack('J', $langkah); // 64-bit big-endian
    $hash = hash_hmac('sha1', $pesan, $kunci, true);
    $offset = ord($hash[19]) & 0x0F;
    $biner = ((ord($hash[$offset]) & 0x7F) << 24)
           | ((ord($hash[$offset + 1]) & 0xFF) << 16)
           | ((ord($hash[$offset + 2]) & 0xFF) << 8)
           |  (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($biner % 1000000), 6, '0', STR_PAD_LEFT);
}

// Mencocokkan kode dengan toleransi satu langkah sebelum/sesudah.
// Mengembalikan langkah yang cocok, atau null. Langkah yang sudah dipakai ditolak (anti-replay).
function totp_cocokkan(string $rahasia_base32, string $kode, ?int $langkah_terakhir = null, ?int $waktu = null): ?int {
    $kode = preg_replace('/\s+/', '', $kode);
    if (!preg_match('/^[0-9]{6}$/', $kode)) {
        return null;
    }
    $langkah_sekarang = intdiv($waktu ?? time(), 30);
    foreach ([-1, 0, 1] as $selisih) {
        $langkah = $langkah_sekarang + $selisih;
        if ($langkah_terakhir !== null && $langkah <= $langkah_terakhir) {
            continue;
        }
        if (hash_equals(totp_kode_pada_langkah($rahasia_base32, $langkah), $kode)) {
            return $langkah;
        }
    }
    return null;
}

// URI untuk dibaca aplikasi autentikator (atau dibuat QR-nya).
function totp_uri(string $rahasia_base32, string $akun, string $penerbit): string {
    return 'otpauth://totp/' . rawurlencode($penerbit . ':' . $akun)
         . '?secret=' . $rahasia_base32 . '&issuer=' . rawurlencode($penerbit)
         . '&algorithm=SHA1&digits=6&period=30';
}
