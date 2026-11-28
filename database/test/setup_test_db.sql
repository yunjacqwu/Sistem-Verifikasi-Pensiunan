-- Database khusus untuk tes keamanan. Bukan database dev atau produksi.
-- Jalankan sebagai root. Ganti GANTI_PASSWORD dengan password yang Anda ketik sendiri.

CREATE DATABASE IF NOT EXISTS db_absensi_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'absensi_test'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD';
GRANT ALL PRIVILEGES ON db_absensi_test.* TO 'absensi_test'@'localhost';
FLUSH PRIVILEGES;

USE db_absensi_test;

-- Salinan dari database/migration_security_f01_f02_f03.sql, hanya tabel rate limit.
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45)  NOT NULL,
    context       VARCHAR(20)  NOT NULL,
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until  INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_ip_context (ip, context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
