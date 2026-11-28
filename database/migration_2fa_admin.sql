-- Migrasi 2FA (TOTP) untuk admin.
-- Dijalankan operator sebagai root, di database aplikasi.
-- Ganti nama_database dengan nama database dev/production sebelum dijalankan.
-- Tidak menghapus data apa pun.

USE db_absensi_pensiun_dev;

ALTER TABLE admin_sistem
  ADD COLUMN totp_secret TEXT NULL AFTER nama,
  ADD COLUMN totp_aktif TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN totp_langkah_terakhir BIGINT NULL AFTER totp_aktif;

CREATE TABLE IF NOT EXISTS admin_kode_cadangan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  kode_hash VARCHAR(255) NOT NULL,
  dipakai_pada DATETIME NULL,
  dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin (admin_id),
  CONSTRAINT fk_kode_cadangan_admin FOREIGN KEY (admin_id) REFERENCES admin_sistem(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hak akun aplikasi untuk fitur ini (hanya yang dibutuhkan).
GRANT SELECT, UPDATE ON db_absensi_pensiun_dev.admin_sistem TO 'absensi_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON db_absensi_pensiun_dev.admin_kode_cadangan TO 'absensi_app'@'localhost';
FLUSH PRIVILEGES;
