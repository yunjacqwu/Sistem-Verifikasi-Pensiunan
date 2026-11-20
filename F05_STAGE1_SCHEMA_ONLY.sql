-- F-05 Stage 1 test schema only
-- Data rows and dump-specific AUTO_INCREMENT counters intentionally omitted.
-- MariaDB 10.11.16 / InnoDB

CREATE TABLE `admin_sistem` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pensiunan` (
  `npk` varchar(20) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `is_first_login` tinyint(1) DEFAULT 1,
  `nik` text DEFAULT NULL,
  `nama` text NOT NULL,
  `nama_asli` longtext DEFAULT NULL,
  `nama_cari` varchar(100) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `telp` text NOT NULL,
  `telp2` text DEFAULT NULL,
  `tmpt_lhr` text DEFAULT NULL,
  `tgl_lhr` date DEFAULT NULL,
  `jk` enum('L','P') DEFAULT NULL,
  `tgl_msk` date DEFAULT NULL,
  `tgl_psn` date DEFAULT NULL,
  `jns_mnft` varchar(50) DEFAULT NULL,
  `jns_penerima` enum('Pensiunan Asli','Janda/Duda','Anak') DEFAULT 'Pensiunan Asli',
  `tgl_alih` date DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Aktif',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`npk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `npk` varchar(20) NOT NULL,
  `tabel_terkait` varchar(50) NOT NULL,
  `kolom_diubah` varchar(100) NOT NULL,
  `nilai_lama` text DEFAULT NULL,
  `nilai_baru` text DEFAULT NULL,
  `diubah_oleh` varchar(50) NOT NULL,
  `waktu` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `npk` (`npk`),
  CONSTRAINT `fk_audit_npk` FOREIGN KEY (`npk`) REFERENCES `pensiunan` (`npk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `dokumen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `npk` varchar(20) NOT NULL,
  `jns_dok` enum('KTP','KK') NOT NULL,
  `file` longtext NOT NULL,
  `status` enum('Menunggu Verifikasi','Disetujui','Ditolak') DEFAULT 'Menunggu Verifikasi',
  `catatan_tolak` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `npk` (`npk`),
  CONSTRAINT `fk_dokumen_npk` FOREIGN KEY (`npk`) REFERENCES `pensiunan` (`npk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `lapor_diri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `npk` varchar(20) NOT NULL,
  `waktu` timestamp NULL DEFAULT current_timestamp(),
  `foto` longtext NOT NULL,
  `status_verifikasi` enum('Menunggu Verifikasi','Disetujui','Ditolak') DEFAULT 'Menunggu Verifikasi',
  `catatan_tolak` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `npk` (`npk`),
  CONSTRAINT `fk_lapor_npk` FOREIGN KEY (`npk`) REFERENCES `pensiunan` (`npk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `context` varchar(20) NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `first_attempt` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip_context` (`ip`,`context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifikasi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `npk` varchar(20) NOT NULL,
  `judul` varchar(100) NOT NULL,
  `pesan` text NOT NULL,
  `jenis` enum('lapor_diri','dokumen') NOT NULL,
  `status_baca` enum('Belum Dibaca','Sudah Dibaca') DEFAULT 'Belum Dibaca',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `npk` (`npk`),
  CONSTRAINT `fk_notif_npk` FOREIGN KEY (`npk`) REFERENCES `pensiunan` (`npk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
