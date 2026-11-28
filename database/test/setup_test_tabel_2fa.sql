-- Tabel untuk tes 2FA admin di db_absensi_test. Tidak berisi password.
-- Jalankan sebagai user absensi_test (sudah punya hak di db_absensi_test).
-- Hanya tabel tes; tidak menyentuh database dev atau produksi.

USE db_absensi_test;

CREATE TABLE IF NOT EXISTS admin_sistem (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    username              VARCHAR(50) NOT NULL UNIQUE,
    totp_secret           TEXT NULL,
    totp_aktif            TINYINT(1) NOT NULL DEFAULT 0,
    totp_langkah_terakhir BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_kode_cadangan (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    kode_hash   VARCHAR(255) NOT NULL,
    dipakai_pada DATETIME NULL,
    dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tes_kode_admin FOREIGN KEY (admin_id) REFERENCES admin_sistem(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_perangkat_dipercaya (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    admin_id          INT NOT NULL,
    token_hash        CHAR(64) NOT NULL,
    user_agent        VARCHAR(255) NULL,
    dibuat_pada       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    kedaluwarsa_pada  DATETIME NOT NULL,
    terakhir_dipakai  DATETIME NULL,
    INDEX idx_tes_perangkat_admin (admin_id),
    CONSTRAINT fk_tes_perangkat_admin FOREIGN KEY (admin_id) REFERENCES admin_sistem(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
