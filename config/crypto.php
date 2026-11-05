<?php
// file: config/crypto.php

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

$secret_key = getenv('AES_SECRET_KEY');
if (empty($secret_key)) {
    throw new RuntimeException('AES_SECRET_KEY tidak ditemukan di .env');
}
define('AES_SECRET_KEY', $secret_key);

/**
 * Membuat kunci AES unik per pengguna
 * Formula: SHA-256(secret_key + NPK)
 * Hasilnya: 32 byte = 256 bit
 */
function buatKunci(string $npk): string {
    return hash('sha256', AES_SECRET_KEY . $npk, true);
}

/**
 * Enkripsi data menggunakan AES-256-CBC
 * Output: string Base64 (IV + Ciphertext, dipisah titik dua)
 * Format: base64(iv):base64(ciphertext)
 */
function enkripsi(string $data, string $npk): string {
    if (empty($data)) return '';
    
    $kunci = buatKunci($npk);
    $iv    = random_bytes(16); // IV acak 16 byte setiap kali enkripsi
    
    $ciphertext = openssl_encrypt(
        $data,
        'AES-256-CBC',
        $kunci,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    if ($ciphertext === false) {
        throw new RuntimeException('Enkripsi gagal.');
    }
    
    // Gabungkan IV dan ciphertext, encode ke Base64
    return base64_encode($iv) . ':' . base64_encode($ciphertext);
}

/**
 * Dekripsi data AES-256-CBC
 * Input: string Base64 format "base64(iv):base64(ciphertext)"
 * Output: string plaintext asli
 */
function dekripsi(?string $data_enkripsi, string $npk): string {
    if (empty($data_enkripsi)) return '';
    
    // Pisahkan IV dan ciphertext
    $bagian = explode(':', $data_enkripsi, 2);
    
    if (count($bagian) !== 2) {
        // Data tidak dalam format yang benar, kembalikan apa adanya
        return $data_enkripsi;
    }
    
    $iv         = base64_decode($bagian[0]);
    $ciphertext = base64_decode($bagian[1]);
    $kunci      = buatKunci($npk);
    
    $plaintext = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $kunci,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    if ($plaintext === false) {
        // Dekripsi gagal — kemungkinan kunci salah atau data korup
        return '[Data tidak dapat dibaca]';
    }
    
    return $plaintext;
}

/**
 * Enkripsi file (foto/dokumen) dari binary ke Base64 AES
 * Input: raw binary file (dari file_get_contents atau stream)
 * Output: string Base64 terenkripsi
 */
function enkripsiFile(string $binary_data, string $npk): string {
    return enkripsi($binary_data, $npk);
}

/**
 * Dekripsi file kembali ke binary
 * Output: raw binary siap ditampilkan sebagai gambar atau diunduh
 */
function dekripsiFile(?string $data_enkripsi, string $npk): string {
    return dekripsi($data_enkripsi, $npk);
}

/**
 * Hash PIN menggunakan Bcrypt
 * Dipakai saat admin tambah pensiunan atau reset PIN
 */
function hashPin(string $pin): string {
    return password_hash($pin, PASSWORD_BCRYPT);
}

/**
 * Verifikasi PIN saat login
 */
function verifikasiPin(string $pin, string $hash): bool {
    return password_verify($pin, $hash);
}