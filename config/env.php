<?php
// file: config/env.php

function loadEnv(string $path): void {
    if (!file_exists($path)) {
        throw new RuntimeException(".env file tidak ditemukan di: $path");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip komentar
        if (str_starts_with(trim($line), '#')) continue;

        // Pisah key dan value
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Hapus tanda kutip kalau ada
        $value = trim($value, '"\'');

        if (!empty($key)) {
            putenv("$key=$value");
            $_ENV[$key]   = $value;
            $_SERVER[$key] = $value;
        }
    }
}