<?php
// file: config/koneksi.php
// Load environment variables dan setup database connection.
// Requires bootstrap.php sudah diinclude sebelumnya.

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

$host   = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME');
$user   = getenv('DB_USER');
$pass   = getenv('DB_PASS');

try {
    $db = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Jangan tampilkan detail error ke browser
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.');
}
?>