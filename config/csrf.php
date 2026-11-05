<?php
// file: config/csrf.php
// Helper CSRF token per-session. Include setelah session_start() dan config/koneksi.php.

/**
 * Ambil (atau buat) CSRF token untuk session saat ini.
 */
if (!function_exists('csrf_token')) {
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
}

/**
 * Render hidden input CSRF untuk disisipkan di dalam <form>.
 */
if (!function_exists('csrf_field')) {
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
}

/**
 * Verifikasi token dari request POST. Mengembalikan true jika valid.
 */
if (!function_exists('csrf_verify')) {
function csrf_verify(?string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}
}

/**
 * Wajibkan CSRF valid untuk request POST; redirect dengan pesan error jika gagal.
 */
if (!function_exists('csrf_require')) {
function csrf_require(string $redirect_on_fail): void {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_verify(is_string($token) ? $token : null)) {
        header("Location: $redirect_on_fail" . (str_contains($redirect_on_fail, '?') ? '&' : '?') . 'error=' . urlencode('Sesi tidak valid atau kedaluwarsa. Silakan coba lagi.'));
        exit;
    }
}
}
