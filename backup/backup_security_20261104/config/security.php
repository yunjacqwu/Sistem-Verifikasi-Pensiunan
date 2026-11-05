<?php
// file: config/security.php
// Helper keamanan server-side: rate limiting (DB) dan PIN acak.

/**
 * Generate PIN acak 6 digit yang tidak sama dengan NPK.
 */
function generatePinAcak(string $npk): string {
    do {
        $pin = (string) random_int(0, 999999);
        $pin = str_pad($pin, 6, '0', STR_PAD_LEFT);
    } while ($pin === $npk);
    return $pin;
}

/**
 * Rate limit berbasis database (tabel login_attempts), keyed by IP + context.
 * Mengembalikan sisa detik lockout jika masih terkunci, atau 0 jika boleh mencoba.
 */
function rate_limit_check(PDO $db, string $context, int $max_attempts = 5, int $window_seconds = 600): int {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $now = time();

    $stmt = $db->prepare("SELECT attempts, first_attempt, locked_until FROM login_attempts WHERE ip = :ip AND context = :context LIMIT 1");
    $stmt->execute(['ip' => $ip, 'context' => $context]);
    $row = $stmt->fetch();

    if ($row) {
        // Reset window jika sudah lewat
        if (($now - (int)$row['first_attempt']) > $window_seconds) {
            $reset = $db->prepare("UPDATE login_attempts SET attempts = 0, first_attempt = :now, locked_until = 0 WHERE ip = :ip AND context = :context");
            $reset->execute(['now' => $now, 'ip' => $ip, 'context' => $context]);
            return 0;
        }
        if (!empty($row['locked_until']) && $now < (int)$row['locked_until']) {
            return (int)$row['locked_until'] - $now;
        }
        if ((int)$row['attempts'] >= $max_attempts) {
            $lock_until = $now + $window_seconds;
            $lock = $db->prepare("UPDATE login_attempts SET locked_until = :lu WHERE ip = :ip AND context = :context");
            $lock->execute(['lu' => $lock_until, 'ip' => $ip, 'context' => $context]);
            return $lock_until - $now;
        }
    }
    return 0;
}

/**
 * Catat satu percobaan gagal.
 */
function rate_limit_hit(PDO $db, string $context, int $max_attempts = 5, int $window_seconds = 600): void {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $now = time();

    $stmt = $db->prepare("SELECT attempts, first_attempt FROM login_attempts WHERE ip = :ip AND context = :context LIMIT 1");
    $stmt->execute(['ip' => $ip, 'context' => $context]);
    $row = $stmt->fetch();

    if ($row) {
        // Jika window sudah lewat, mulai ulang dari 1
        if (($now - (int)$row['first_attempt']) > $window_seconds) {
            $upd = $db->prepare("UPDATE login_attempts SET attempts = 1, first_attempt = :now, locked_until = 0 WHERE ip = :ip AND context = :context");
            $upd->execute(['now' => $now, 'ip' => $ip, 'context' => $context]);
        } else {
            $upd = $db->prepare("UPDATE login_attempts SET attempts = attempts + 1 WHERE ip = :ip AND context = :context");
            $upd->execute(['ip' => $ip, 'context' => $context]);
        }
    } else {
        $ins = $db->prepare("INSERT INTO login_attempts (ip, context, attempts, first_attempt, locked_until) VALUES (:ip, :context, 1, :now, 0)");
        $ins->execute(['ip' => $ip, 'context' => $context, 'now' => $now]);
    }
}

/**
 * Reset counter setelah login berhasil.
 */
function rate_limit_reset(PDO $db, string $context): void {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $del = $db->prepare("DELETE FROM login_attempts WHERE ip = :ip AND context = :context");
    $del->execute(['ip' => $ip, 'context' => $context]);
}
