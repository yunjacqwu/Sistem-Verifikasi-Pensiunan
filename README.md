# Sistem Verifikasi Pensiunan

Aplikasi web untuk verifikasi kelangsungan hidup pensiunan Dana Pensiun Pegawai Perum Peruri. Pensiunan melapor diri secara berkala dengan foto (KTP dan wajah), lalu admin memverifikasi laporan, dokumen, dan data pensiunan.

## Fitur

**Pensiunan (portal pengguna)**
- Login dengan NPK dan PIN, lalu wajib mengganti PIN pada login pertama.
- Lapor diri dengan foto live capture sambil memegang KTP. Foto diberi watermark waktu dan disimpan terenkripsi.
- Unggah dokumen KTP dan KK (JPG, PNG, atau PDF tanpa skrip).
- Melihat status lapor dan dokumen, serta notifikasi.

**Admin (panel admin)**
- Dashboard ringkasan lapor, dokumen, dan kelengkapan berkas.
- Data pensiunan: tambah, ubah, nonaktifkan, alih status, reset PIN, impor dan ekspor Excel.
- Verifikasi lapor diri dan dokumen.
- Monitoring lapor diri per kuartal.
- Log aktivitas dan audit (akses berkas, foto, dan profil, serta perubahan data). Rantai audit diberi hash HMAC agar perubahan dapat dideteksi.

## Teknologi

- PHP 8.5 dengan Apache (XAMPP di Windows)
- MariaDB 10.11
- PhpSpreadsheet 5.8 (Composer)
- Tailwind CSS (CDN), Alpine.js 3.14.1, dan DaisyUI 4.12.10 (lokal dengan SRI)
- Font Inter (lokal, lisensi OFL)

## Struktur folder

| Folder | Isi |
|---|---|
| `admin/` | Halaman dan proses panel admin |
| `users/` | Halaman dan proses portal pensiunan |
| `config/` | Koneksi, enkripsi, CSRF, rate limit, header keamanan, audit |
| `includes/` | Header, footer, dan bagian bersama |
| `static/` | Aset: CSS, JavaScript, font, dan vendor |
| `database/` | Berkas migrasi skema |
| `vendor/` | Dependensi Composer (tidak ikut di repo) |

## Instalasi (pengembangan lokal)

1. Pasang XAMPP dengan PHP 8.5 dan MariaDB 10.11.
2. Letakkan folder project di `htdocs/absensi_pensiunan_dev`.
3. Jalankan `composer install` di folder project.
4. Buat database dan terapkan skema. Berkas migrasi ada di `database/`.
5. Buat berkas `.env` di root project dengan variabel di bagian berikut.
6. Buka `http://localhost/absensi_pensiunan_dev/admin/login.php` untuk panel admin, atau `users/login.php` untuk portal pensiunan.

## Konfigurasi `.env`

Kode membaca variabel berikut. Isi nilainya sendiri, jangan dikomit ke repo.

| Variabel | Fungsi |
|---|---|
| `DB_HOST` | Alamat database |
| `DB_NAME` | Nama database |
| `DB_USER` | Akun database aplikasi |
| `DB_PASS` | Password akun database |
| `AES_SECRET_KEY` | Kunci dasar enkripsi data pensiunan dan foto |
| `AUDIT_HMAC_KEY` | Kunci HMAC untuk rantai audit (minimal 32 karakter) |

Catatan: `.env.example` di repo masih memuat variabel lama (`APP_KEY`, `APP_ENV`, `APP_DEBUG`) yang tidak dipakai kode. Perlu diperbarui.

## Keamanan

- Semua form POST dilindungi token CSRF.
- Login dibatasi 5 percobaan per 10 menit, dicatat di database.
- PIN disimpan dengan bcrypt. PIN awal dibuat acak, bukan NPK.
- Data pribadi (nama, NIK, telepon, alamat, foto, dan dokumen) dienkripsi AES-256-CBC dengan kunci per NPK.
- Header keamanan: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, dan Permissions-Policy.
- Akses foto dan dokumen dicatat ke audit log. Berkas tidak tampil langsung di browser, PDF selalu diunduh.
- Berkas `.env`, `.sql`, dan `.md` diblokir oleh `.htaccess`.

## Yang tidak ada di repo

- Folder `backup/`, berkas `.env`, dan arsip ekspor data pensiunan. Ketiganya harus disimpan terpisah dan aman.
- Dokumen internal operator.

## Lisensi

Hak cipta Dana Pensiun Pegawai Perum Peruri. Belum ada lisensi terbuka.
