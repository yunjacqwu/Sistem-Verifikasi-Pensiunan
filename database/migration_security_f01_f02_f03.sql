-- file: database/migration_security_f01_f02_f03.sql
-- Migrasi untuk perbaikan F-01/F-02/F-03
-- Jalankan di database aplikasi (sesuaikan nama database jika berbeda).

-- Tabel rate limiting server-side (F-02)
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45)  NOT NULL,
    context       VARCHAR(20)  NOT NULL,           -- 'user' atau 'admin'
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt INT UNSIGNED NOT NULL DEFAULT 0, -- unix timestamp
    locked_until  INT UNSIGNED NOT NULL DEFAULT 0, -- unix timestamp
    UNIQUE KEY uniq_ip_context (ip, context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
