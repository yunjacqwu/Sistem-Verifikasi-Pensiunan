<?php
// file: config/bootstrap.php
// Include ini di semua halaman PHP sebelum output apapun

// Matikan tampilan error ke browser
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Log error ke file
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Deteksi HTTPS (termasuk reverse proxy yang secara eksplisit menandai HTTPS)
$is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https');
$is_local_host = in_array(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), ['localhost', 'localhost:80', 'localhost:443', '127.0.0.1', '127.0.0.1:80', '127.0.0.1:443', '[::1]'], true);

// Session security. Cookie Secure mengikuti transport; .htaccess memaksa HTTPS untuk non-local host.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', $is_https ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_path', '/');
    ini_set('session.use_strict_mode', '1');
}

// CSP mengizinkan CDN yang dipakai UI dan inline scripts/style saat ini agar fitur tetap berfungsi.
// Nonce/hash migration dapat dilakukan terpisah untuk CSP yang lebih ketat.
if (!headers_sent()) {
    $csp = "default-src 'self'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "img-src 'self' data: blob:; font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "connect-src 'self' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "media-src 'self' blob:; upgrade-insecure-requests";
    header('Content-Security-Policy: ' . $csp);
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=()');
    if ($is_https && !$is_local_host) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Inisialisasi session (dilakukan setelah session config di atas)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
