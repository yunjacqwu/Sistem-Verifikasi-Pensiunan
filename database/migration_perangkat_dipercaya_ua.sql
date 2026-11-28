-- Tambahan untuk daftar perangkat tepercaya: kolom user agent.
-- Jalankan setelah migration_perangkat_dipercaya.sql, sebagai root.
-- Ganti nama_database dengan nama database sebelum dijalankan.
-- Tidak menghapus data apa pun.

USE db_absensi_pensiun_dev;

ALTER TABLE admin_perangkat_dipercaya ADD COLUMN user_agent VARCHAR(255) NULL AFTER token_hash;
