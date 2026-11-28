<?php
// file: config/admin_2fa.php
// Fungsi bersama untuk 2FA admin (TOTP + kode cadangan).
// Rahasia TOTP dienkripsi dengan kunci turunan per admin: 'ADMIN:' . username.

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/audit.php';

function admin_2fa_kunci(string $username): string {
    return 'ADMIN:' . $username;
}

function admin_2fa_simpan_rahasia(PDO $db, int $admin_id, string $username, string $rahasia_base32, int $langkah): void {
    $terenkripsi = enkripsi($rahasia_base32, admin_2fa_kunci($username));
    $st = $db->prepare("UPDATE admin_sistem SET totp_secret = :s, totp_aktif = 1, totp_langkah_terakhir = :l WHERE id = :id");
    $st->execute(['s' => $terenkripsi, 'l' => $langkah, 'id' => $admin_id]);
}

function admin_2fa_baca_rahasia(string $username, ?string $terenkripsi): string {
    return dekripsi($terenkripsi, admin_2fa_kunci($username));
}

// Membuat 8 kode cadangan sekali pakai. Yang lama dihapus. Kode asli hanya dikembalikan sekali.
function admin_2fa_buat_kode_cadangan(PDO $db, int $admin_id): array {
    $db->prepare("DELETE FROM admin_kode_cadangan WHERE admin_id = :id")->execute(['id' => $admin_id]);
    $alfabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $kode = [];
    $st = $db->prepare("INSERT INTO admin_kode_cadangan (admin_id, kode_hash) VALUES (:id, :h)");
    for ($i = 0; $i < 8; $i++) {
        $teks = '';
        for ($j = 0; $j < 8; $j++) {
            $teks .= $alfabet[random_int(0, strlen($alfabet) - 1)];
        }
        $tampil = substr($teks, 0, 4) . '-' . substr($teks, 4, 4);
        $st->execute(['id' => $admin_id, 'h' => password_hash($tampil, PASSWORD_BCRYPT)]);
        $kode[] = $tampil;
    }
    return $kode;
}

// Memakai satu kode cadangan. Mengembalikan true jika cocok dan belum dipakai.
function admin_2fa_pakai_kode_cadangan(PDO $db, int $admin_id, string $kode): bool {
    $kode = strtoupper(trim($kode));
    $st = $db->prepare("SELECT id, kode_hash FROM admin_kode_cadangan WHERE admin_id = :id AND dipakai_pada IS NULL");
    $st->execute(['id' => $admin_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $baris) {
        if (password_verify($kode, $baris['kode_hash'])) {
            $tandai = $db->prepare("UPDATE admin_kode_cadangan SET dipakai_pada = NOW() WHERE id = :id AND dipakai_pada IS NULL");
            $tandai->execute(['id' => $baris['id']]);
            return $tandai->rowCount() === 1;
        }
    }
    return false;
}

// Mematikan 2FA admin: rahasia dihapus dan semua kode cadangan dihapus.
function admin_2fa_reset(PDO $db, int $admin_id): void {
    $db->prepare("UPDATE admin_sistem SET totp_secret = NULL, totp_aktif = 0, totp_langkah_terakhir = NULL WHERE id = :id")
       ->execute(['id' => $admin_id]);
    $db->prepare("DELETE FROM admin_kode_cadangan WHERE admin_id = :id")->execute(['id' => $admin_id]);
    admin_perangkat_cabut_semua($db, $admin_id);
}

// Mencatat langkah TOTP terakhir yang dipakai (anti-replay). Mengembalikan false jika sudah tidak lebih baru.
function admin_2fa_catat_langkah(PDO $db, int $admin_id, int $langkah): bool {
    $st = $db->prepare("UPDATE admin_sistem SET totp_langkah_terakhir = :l WHERE id = :id AND (totp_langkah_terakhir IS NULL OR totp_langkah_terakhir < :l2)");
    $st->execute(['l' => $langkah, 'l2' => $langkah, 'id' => $admin_id]);
    return $st->rowCount() === 1;
}

// Menyelesaikan login admin setelah kode TOTP atau kode cadangan benar. Sesi admin baru dibuat di sini.
function admin_2fa_selesai_login(PDO $db, array $admin, string $keterangan = 'Login berhasil (2FA)'): void {
    unset($_SESSION['admin_2fa_tunggu'], $_SESSION['admin_2fa_rahasia'], $_SESSION['admin_2fa_kode_baru']);
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = $admin['id'];
    $_SESSION['admin_username']  = $admin['username'];
    $_SESSION['admin_nama']      = $admin['nama'];
    $_SESSION['admin_terakhir']  = time();
    audit_tulis($db, null, 'admin_sistem', 'login', null, $keterangan, $admin['username']);
}

// ---- Ingat perangkat (cookie per admin, berlaku ADMIN_INGAT_HARI hari) ----
const ADMIN_INGAT_HARI = 7;

function admin_ingat_nama(int $admin_id): string {
    return 'admin_ingat_' . $admin_id;
}

// Membuat token acak untuk perangkat ini. Database hanya menyimpan hash-nya.
function admin_perangkat_buat(PDO $db, int $admin_id): void {
    $token = bin2hex(random_bytes(32));
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $st = $db->prepare("INSERT INTO admin_perangkat_dipercaya (admin_id, token_hash, user_agent, kedaluwarsa_pada) VALUES (:id, :h, :ua, DATE_ADD(NOW(), INTERVAL " . (int)ADMIN_INGAT_HARI . " DAY))");
    $st->execute(['id' => $admin_id, 'h' => hash('sha256', $token), 'ua' => $ua]);
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(admin_ingat_nama($admin_id), $token, [
        'expires'  => time() + ADMIN_INGAT_HARI * 86400,
        'path'     => '/absensi_pensiunan_dev/admin/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// Memeriksa apakah perangkat ini tepercaya untuk admin tersebut. Memperbarui waktu pemakaian.
function admin_perangkat_cek(PDO $db, int $admin_id): bool {
    $token = $_COOKIE[admin_ingat_nama($admin_id)] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $st = $db->prepare("SELECT id FROM admin_perangkat_dipercaya WHERE admin_id = :id AND token_hash = :h AND kedaluwarsa_pada > NOW() LIMIT 1");
    $st->execute(['id' => $admin_id, 'h' => hash('sha256', $token)]);
    $baris = $st->fetch(PDO::FETCH_ASSOC);
    if (!$baris) {
        return false;
    }
    $db->prepare("UPDATE admin_perangkat_dipercaya SET terakhir_dipakai = NOW() WHERE id = :id")->execute(['id' => $baris['id']]);
    return true;
}

// Daftar perangkat tepercaya yang belum kedaluwarsa. Tanda 'ini' untuk perangkat yang sedang dipakai.
function admin_perangkat_daftar(PDO $db, int $admin_id): array {
    $token = $_COOKIE[admin_ingat_nama($admin_id)] ?? '';
    $hash_ini = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : '';
    $st = $db->prepare("SELECT id, token_hash, user_agent, dibuat_pada, terakhir_dipakai, kedaluwarsa_pada FROM admin_perangkat_dipercaya WHERE admin_id = :id AND kedaluwarsa_pada > NOW() ORDER BY terakhir_dipakai DESC, dibuat_pada DESC");
    $st->execute(['id' => $admin_id]);
    $hasil = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $baris) {
        $baris['ini'] = $hash_ini !== '' && hash_equals($baris['token_hash'], $hash_ini);
        unset($baris['token_hash']);
        $hasil[] = $baris;
    }
    return $hasil;
}

// Mencabut satu perangkat. Hanya bisa mencabut milik admin yang sama.
function admin_perangkat_cabut(PDO $db, int $admin_id, int $perangkat_id): bool {
    $st = $db->prepare("DELETE FROM admin_perangkat_dipercaya WHERE id = :pid AND admin_id = :id");
    $st->execute(['pid' => $perangkat_id, 'id' => $admin_id]);
    return $st->rowCount() > 0;
}

// Mencabut semua perangkat tepercaya milik admin.
function admin_perangkat_cabut_semua(PDO $db, int $admin_id): void {
    $db->prepare("DELETE FROM admin_perangkat_dipercaya WHERE admin_id = :id")->execute(['id' => $admin_id]);
}

// Ringkasan user agent untuk ditampilkan, misalnya "Chrome di Windows".
function admin_ringkas_ua(?string $ua): string {
    $ua = (string)$ua;
    if (str_contains($ua, 'Edg/')) $browser = 'Edge';
    elseif (str_contains($ua, 'OPR/')) $browser = 'Opera';
    elseif (str_contains($ua, 'Chrome/')) $browser = 'Chrome';
    elseif (str_contains($ua, 'Firefox/')) $browser = 'Firefox';
    elseif (str_contains($ua, 'Safari/')) $browser = 'Safari';
    else $browser = 'Browser lain';
    if (str_contains($ua, 'Windows')) $os = 'Windows';
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
    elseif (str_contains($ua, 'Mac OS')) $os = 'macOS';
    elseif (str_contains($ua, 'Linux')) $os = 'Linux';
    else $os = 'OS lain';
    return $browser . ' di ' . $os;
}
