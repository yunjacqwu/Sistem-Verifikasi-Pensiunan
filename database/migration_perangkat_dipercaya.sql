-- Migrasi ingat perangkat untuk admin (cookie tepercaya selama 7 hari).
-- Dijalankan operator sebagai root, di database aplikasi.
-- Ganti nama_database dengan nama database sebelum dijalankan.
-- Tidak menghapus data apa pun.

USE db_absensi_pensiun_dev;

CREATE TABLE IF NOT EXISTS admin_perangkat_dipercaya (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kedaluwarsa_pada DATETIME NOT NULL,
  terakhir_dipakai DATETIME NULL,
  INDEX idx_perangkat_admin (admin_id),
  INDEX idx_perangkat_token (token_hash),
  CONSTRAINT fk_perangkat_admin FOREIGN KEY (admin_id) REFERENCES admin_sistem(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

GRANT SELECT, INSERT, UPDATE, DELETE ON db_absensi_pensiun_dev.admin_perangkat_dipercaya TO 'absensi_app'@'localhost';
FLUSH PRIVILEGES;
