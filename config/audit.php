<?php
// Hash-chain audit (F-10 lapisan kedua). Tiap baris menyimpan prev_hash (row_hash baris sebelumnya)
// dan row_hash = HMAC-SHA256(AUDIT_HMAC_KEY, isi baris + prev_hash). Perubahan di tengah rantai terdeteksi.
// Belum dipakai oleh penulisan audit yang ada: fungsi ini hanya disambungkan setelah skema diubah dan dibackfill.
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

function audit_kunci(): string {
    $k = (string)getenv('AUDIT_HMAC_KEY');
    if (strlen($k) < 32) {
        throw new RuntimeException('AUDIT_HMAC_KEY belum diset atau terlalu pendek');
    }
    return $k;
}

function audit_hitung_hash(string $prev, ?string $npk, string $tabel, string $kolom, ?string $lama, ?string $baru, string $oleh, string $waktu): string {
    // Pemisah 0x1F supaya nilai yang berdekatan tidak bisa tertukar tanpa mengubah hash.
    $data = implode("\x1f", [$prev, (string)$npk, $tabel, $kolom, (string)$lama, (string)$baru, $oleh, $waktu]);
    return hash_hmac('sha256', $data, audit_kunci());
}

// Tulis satu baris audit dengan rantai. Dipanggil di dalam transaksi pemanggil bila ada.
function audit_tulis(PDO $db, ?string $npk, string $tabel, string $kolom, ?string $lama, ?string $baru, string $oleh): void {
    $waktu = date('Y-m-d H:i:s');
    $prev  = (string)$db->query("SELECT row_hash FROM audit_logs WHERE row_hash IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
    $hash  = audit_hitung_hash($prev, $npk, $tabel, $kolom, $lama, $baru, $oleh, $waktu);
    $st = $db->prepare("INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh, waktu, prev_hash, row_hash)
                        VALUES (:npk, :tabel, :kolom, :lama, :baru, :oleh, :waktu, :prev, :hash)");
    $st->execute([
        'npk' => $npk, 'tabel' => $tabel, 'kolom' => $kolom, 'lama' => $lama, 'baru' => $baru,
        'oleh' => $oleh, 'waktu' => $waktu, 'prev' => $prev !== '' ? $prev : null, 'hash' => $hash,
    ]);
}

// Verifikasi rantai (read-only). Mengembalikan daftar masalah dan jumlah baris yang diperiksa.
function audit_verifikasi(PDO $db): array {
    $rows = $db->query("SELECT id, npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh, waktu, prev_hash, row_hash
                        FROM audit_logs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $masalah = [];
    $diperiksa = 0;
    $prev = '';
    $mulai = false;
    foreach ($rows as $r) {
        if ($r['row_hash'] === null) {
            if ($mulai) { $masalah[] = "id {$r['id']}: baris di tengah rantai tanpa hash"; }
            continue;
        }
        $mulai = true;
        $diperiksa++;
        if ((string)$r['prev_hash'] !== $prev) {
            $masalah[] = "id {$r['id']}: prev_hash tidak sesuai";
        }
        $hitung = audit_hitung_hash($prev, $r['npk'], $r['tabel_terkait'], $r['kolom_diubah'], $r['nilai_lama'], $r['nilai_baru'], $r['diubah_oleh'], (string)$r['waktu']);
        if (!hash_equals($r['row_hash'], $hitung)) {
            $masalah[] = "id {$r['id']}: isi baris tidak cocok dengan hash (kemungkinan diubah)";
        }
        $prev = $r['row_hash'];
    }
    return ['diperiksa' => $diperiksa, 'masalah' => $masalah];
}
