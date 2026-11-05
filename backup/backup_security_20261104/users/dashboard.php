<?php
// file: users/dashboard.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$npk = $_SESSION['user_npk'];
date_default_timezone_set('Asia/Jakarta');

try {
    // Ambil data profil pensiunan
    $stmt = $db->prepare("SELECT * FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    $user_raw = $stmt->fetch();

    if (!$user_raw) {
        session_destroy();
        header("Location: login.php?error=Akun tidak ditemukan.");
        exit;
    }

    // Dekripsi kolom sensitif
    $user = $user_raw;
    $user['nama']     = dekripsi($user_raw['nama'], $npk);
    $user['nik']      = dekripsi($user_raw['nik'], $npk);
    $user['alamat']   = dekripsi($user_raw['alamat'], $npk);
    $user['telp']     = dekripsi($user_raw['telp'], $npk);
    $user['telp2']    = dekripsi($user_raw['telp2'], $npk);
    $user['tmpt_lhr'] = dekripsi($user_raw['tmpt_lhr'], $npk);

    // Ambil status lapor diri kuartal ini
    $stmt_lapor = $db->prepare("
        SELECT status_verifikasi, catatan_tolak FROM lapor_diri
        WHERE npk = :npk
        AND QUARTER(waktu) = QUARTER(CURRENT_DATE())
        AND YEAR(waktu) = YEAR(CURRENT_DATE())
        ORDER BY waktu DESC LIMIT 1
    ");
    $stmt_lapor->execute(['npk' => $npk]);
    $lapor_terakhir = $stmt_lapor->fetch();
    $status_lapor   = $lapor_terakhir ? $lapor_terakhir['status_verifikasi'] : 'Belum Lapor';
    $catatan_tolak  = $lapor_terakhir ? $lapor_terakhir['catatan_tolak'] : '';

    // Cek status dokumen per jenis (KTP & KK)
    $stmt_doc = $db->prepare("SELECT jns_dok, status, catatan_tolak FROM dokumen WHERE npk = :npk");
    $stmt_doc->execute(['npk' => $npk]);
    $dok_map = [];
    foreach ($stmt_doc->fetchAll() as $d) {
        $dok_map[$d['jns_dok']] = $d;
    }

    $status_ktp = $dok_map['KTP']['status'] ?? null;
    $status_kk  = $dok_map['KK']['status']  ?? null;

    $ada_yang_ditolak = ($status_ktp === 'Ditolak' || $status_kk === 'Ditolak');
    $semua_disetujui  = ($status_ktp === 'Disetujui' && $status_kk === 'Disetujui');
    $berkas_lengkap   = (!empty($status_ktp) && !empty($status_kk)); // kedua dokumen sudah pernah diupload

    // Tentukan status gabungan untuk badge
    if (empty($status_ktp) && empty($status_kk)) {
        $status_berkas_label = 'Belum Lengkap';
    } elseif ($ada_yang_ditolak) {
        $status_berkas_label = 'Ditolak';
    } elseif (empty($status_ktp) || empty($status_kk)) {
        $status_berkas_label = 'Belum Lengkap';
    } elseif ($semua_disetujui) {
        $status_berkas_label = 'Disetujui';
    } else {
        $status_berkas_label = 'Menunggu Verifikasi';
    }

    // Hitung jumlah notifikasi belum dibaca
    $stmt_notif = $db->prepare("
        SELECT COUNT(*) as jml FROM notifikasi
        WHERE npk = :npk AND status_baca = 'Belum Dibaca'
    ");
    $stmt_notif->execute(['npk' => $npk]);
    $jml_notif_belum_baca = $stmt_notif->fetch()['jml'];

    // Tentukan periode kuartal
    $bulan        = (int)date('n');
    $tahun        = date('Y');
    $kuartal_map  = [
        1 => 'Kuartal 1 (Januari – Maret',
        2 => 'Kuartal 2 (April – Juni',
        3 => 'Kuartal 3 (Juli – September',
        4 => 'Kuartal 4 (Oktober – Desember',
    ];
    $kuartal_no      = ceil($bulan / 3);
    $periode_kuartal = $kuartal_map[$kuartal_no] . ' ' . $tahun . ')';

} catch (PDOException $e) {
    die("Gagal memuat data: " . $e->getMessage());
}

$page_title = 'Dashboard';
?>

<?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto p-4 md:p-6 space-y-6">

        <?php if (isset($_GET['sukses'])): ?>
            <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded-r-xl font-medium">
                <?= htmlspecialchars($_GET['sukses']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium">
                <?= htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Sambutan -->
        <div class="mt-4">
            <h2 class="text-3xl font-bold text-slate-800">
                Selamat datang, <?= htmlspecialchars($user['nama']); ?>!
            </h2>
            <p class="text-slate-500 mt-1 text-lg">Silakan cek status lapor diri dan berkas Anda.</p>
        </div>

        <!-- Kartu status lapor diri -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-md border-t-4 border-blue-700">
            <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                <h3 class="text-2xl font-bold text-slate-800">Status Lapor Diri</h3>

                <?php if ($status_lapor === 'Disetujui'): ?>
                    <span class="bg-green-100 text-green-700 text-sm px-3 py-1.5 rounded-full font-bold">
                        ✅ Disetujui
                    </span>
                <?php elseif ($status_lapor === 'Menunggu Verifikasi'): ?>
                    <span class="bg-amber-100 text-amber-700 text-sm px-3 py-1.5 rounded-full font-bold animate-pulse">
                        ⏳ Menunggu Verifikasi Admin
                    </span>
                <?php elseif ($status_lapor === 'Ditolak'): ?>
                    <span class="bg-red-100 text-red-700 text-sm px-3 py-1.5 rounded-full font-bold">
                        ❌ Ditolak — Silakan Foto Ulang
                    </span>
                <?php else: ?>
                    <span class="bg-red-100 text-red-700 text-sm px-3 py-1.5 rounded-full font-bold animate-pulse">
                        Belum Lapor
                    </span>
                <?php endif; ?>
            </div>

            <p class="text-slate-500 text-base mb-4">
                Periode: <span class="font-semibold text-slate-700"><?= $periode_kuartal; ?></span>
            </p>

            <?php if (!empty($catatan_tolak) && $status_lapor === 'Ditolak'): ?>
                <div class="bg-red-50 border border-red-200 rounded-xl p-3 mb-4 text-sm text-red-700">
                    <span class="font-semibold"></span> <?= htmlspecialchars($catatan_tolak); ?>
                </div>
            <?php endif; ?>

            <?php if ($status_lapor === 'Disetujui'): ?>
                <button disabled class="w-full bg-slate-200 text-slate-500 font-bold text-lg py-4 rounded-xl cursor-not-allowed">
                    Lapor Diri Sudah Disetujui
                </button>
            <?php elseif ($status_lapor === 'Menunggu Verifikasi'): ?>
                <button disabled class="w-full bg-amber-500 text-white font-bold text-lg py-4 rounded-xl cursor-not-allowed shadow-md">
                    Foto Menunggu Verifikasi Admin
                </button>
            <?php else: ?>
                <a href="absen.php"
                   class="flex items-center justify-center gap-2 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-xl py-4 rounded-xl transition-all shadow-md hover:shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <?= $status_lapor === 'Ditolak' ? 'Ulangi Lapor Diri' : 'Mulai Lapor Diri'; ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Kartu kelengkapan berkas -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-md border-t-4 <?= $ada_yang_ditolak ? 'border-red-500' : 'border-slate-300'; ?>">
            <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                <h3 class="text-2xl font-bold text-slate-800">Status Kelengkapan Berkas KTP dan KK</h3>

                <?php if ($status_berkas_label === 'Disetujui'): ?>
                    <span class="bg-green-100 text-green-700 text-sm px-3 py-1.5 rounded-full font-bold">
                        ✅ Disetujui
                    </span>
                <?php elseif ($status_berkas_label === 'Menunggu Verifikasi'): ?>
                    <span class="bg-amber-100 text-amber-700 text-sm px-3 py-1.5 rounded-full font-bold animate-pulse">
                        ⏳ Menunggu Verifikasi Admin
                    </span>
                <?php elseif ($status_berkas_label === 'Ditolak'): ?>
                    <span class="bg-red-100 text-red-700 text-sm px-3 py-1.5 rounded-full font-bold">
                        ❌ Ada Dokumen Ditolak
                    </span>
                <?php else: ?>
                    <span class="bg-amber-100 text-amber-700 text-sm px-3 py-1.5 rounded-full font-bold animate-pulse">
                        ⚠️ Belum Lengkap
                    </span>
                <?php endif; ?>
            </div>

            <p class="text-slate-500 text-base mb-4">KTP dan KK dibutuhkan untuk verifikasi data kepesertaan.</p>

            <!-- Detail per dokumen -->
            <div class="grid grid-cols-2 gap-3 mb-4">
                <?php foreach (['KTP' => $status_ktp, 'KK' => $status_kk] as $jenis => $st): ?>
                    <div class="border border-slate-200 rounded-xl p-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700 text-sm"><?= $jenis; ?></span>
                        <?php if ($st === 'Disetujui'): ?>
                            <span class="text-xs font-bold text-emerald-600">✅ Disetujui</span>
                        <?php elseif ($st === 'Ditolak'): ?>
                            <span class="text-xs font-bold text-red-600">❌ Ditolak</span>
                        <?php elseif ($st === 'Menunggu Verifikasi'): ?>
                            <span class="text-xs font-bold text-amber-600">⏳ Menunggu</span>
                        <?php else: ?>
                            <span class="text-xs font-bold text-slate-400">Belum Ada</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Catatan penolakan -->
            <?php if ($ada_yang_ditolak): ?>
                <?php foreach (['KTP' => $dok_map['KTP'] ?? null, 'KK' => $dok_map['KK'] ?? null] as $jenis => $d): ?>
                    <?php if ($d && $d['status'] === 'Ditolak' && !empty($d['catatan_tolak'])): ?>
                        <div class="bg-red-50 border border-red-200 rounded-xl p-3 mb-3 text-sm text-red-700">
                            <span class="font-semibold"><?= $jenis; ?> ditolak:</span> <?= htmlspecialchars($d['catatan_tolak']); ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($status_berkas_label === 'Disetujui'): ?>
                <a href="upload_dokumen.php" class="block text-center text-blue-600 hover:text-blue-700 text-sm font-semibold bg-white px-3 py-2.5 rounded-xl border border-slate-200 shadow-sm">
                    Perbarui Dokumen
                </a>
            <?php else: ?>
                <a href="upload_dokumen.php"
                class="flex items-center justify-center gap-2 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg py-4 rounded-xl transition-all shadow-md hover:shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"/>
                    </svg>
                    <?= $ada_yang_ditolak ? 'Unggah Ulang Dokumen' : 'Upload Berkas'; ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Kartu informasi data diri — ringkas -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-800">Data Diri</h3>
                <a href="data_diri.php"
                class="text-sm text-blue-600 hover:text-blue-700 font-medium transition-colors flex items-center gap-1">
                    Lihat & Edit
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>
            <div class="flex items-center gap-4">
                <!-- Avatar inisial -->
                <div class="h-14 w-14 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xl flex-shrink-0">
                    <?= strtoupper(mb_substr($user['nama'], 0, 1)); ?>
                </div>
                <div class="min-w-0">
                    <p class="font-bold text-slate-800 text-lg leading-tight truncate">
                        <?= htmlspecialchars($user['nama']); ?>
                    </p>
                    <p class="text-slate-500 text-sm font-mono mt-0.5">
                        NPK: <?= htmlspecialchars($user['npk']); ?>
                    </p>
                    <p class="text-slate-500 text-sm mt-0.5 truncate">
                        <?= htmlspecialchars($user['telp'] ?: '—'); ?>
                    </p>
                </div>
            </div>
        </div>

    </div>

    </div>

<!-- Modal Ganti PIN Pertama Kali -->
<?php if (isset($_GET['first_login']) && $_SESSION['is_first_login']): ?>
<div id="modal-ganti-pin"
     class="fixed inset-0 bg-slate-900/70 flex items-center justify-center z-[999] p-4 !mt-0">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md border border-slate-200">

        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-200 bg-amber-50 rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-lg">Ganti PIN Sekarang</h3>
                    <p class="text-amber-700 text-xs mt-0.5">Anda masuk menggunakan PIN sementara (NPK Anda)</p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="px-6 py-5 space-y-4">

            <!-- Peringatan -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                <p class="text-sm font-bold text-amber-800 mb-1">⚠️ Penting — Catat PIN Baru Anda!</p>
                <ul class="text-xs text-amber-700 space-y-1 list-disc list-inside">
                    <li>PIN baru hanya Anda yang tahu — sistem tidak menyimpan PIN dalam bentuk asli</li>
                    <li>Catat PIN baru Anda di tempat yang aman sebelum menyimpan</li>
                    <li>Jika lupa PIN, hubungi Dana Pensiun Peruri untuk reset PIN</li>
                </ul>
            </div>

            <!-- Error -->
            <div id="error-pin" class="hidden bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl font-semibold"></div>

            <!-- Form -->
            <div id="form-pin">
                <div class="space-y-4">
                    <div>
                        <label class="text-xs font-bold text-slate-600 block mb-1.5">PIN Baru (4 digit angka)</label>
                        <input type="password" id="pin_baru" maxlength="4" inputmode="numeric"
                               placeholder="••••"
                               class="w-full px-4 py-3 border border-slate-300 rounded-xl text-center text-2xl font-mono tracking-[1em] focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 block mb-1.5">Konfirmasi PIN Baru</label>
                        <input type="password" id="pin_konfirmasi" maxlength="4" inputmode="numeric"
                               placeholder="••••"
                               class="w-full px-4 py-3 border border-slate-300 rounded-xl text-center text-2xl font-mono tracking-[1em] focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                <button onclick="submitGantiPin()"
                        class="w-full mt-4 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-colors">
                    Simpan PIN Baru
                </button>
            </div>

            <!-- Sukses -->
            <div id="sukses-pin" class="hidden text-center py-4 space-y-3">
                <div class="h-14 w-14 rounded-full bg-emerald-100 flex items-center justify-center mx-auto">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="font-bold text-slate-800">PIN berhasil diganti!</p>
                <p class="text-sm text-slate-500">Pastikan Anda sudah mencatat PIN baru Anda.</p>
                <button onclick="tutupModal()"
                        class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl transition-colors">
                    Lanjut ke Dashboard
                </button>
            </div>

        </div>
    </div>
</div>

<script>
function submitGantiPin() {
    const pin_baru       = document.getElementById('pin_baru').value.trim();
    const pin_konfirmasi = document.getElementById('pin_konfirmasi').value.trim();
    const error_div      = document.getElementById('error-pin');

    error_div.classList.add('hidden');
    error_div.textContent = '';

    if (pin_baru.length !== 4 || !/^\d{4}$/.test(pin_baru)) {
        error_div.textContent = 'PIN harus berupa 4 digit angka.';
        error_div.classList.remove('hidden');
        return;
    }
    if (pin_baru !== pin_konfirmasi) {
        error_div.textContent = 'Konfirmasi PIN tidak cocok.';
        error_div.classList.remove('hidden');
        return;
    }

    fetch('proses_ganti_pin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token(); ?>' },
        body: JSON.stringify({ pin_baru: pin_baru, csrf_token: '<?= csrf_token(); ?>' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sukses) {
            document.getElementById('form-pin').classList.add('hidden');
            document.getElementById('sukses-pin').classList.remove('hidden');
        } else {
            error_div.textContent = data.pesan || 'Terjadi kesalahan.';
            error_div.classList.remove('hidden');
        }
    })
    .catch(() => {
        error_div.textContent = 'Terjadi kesalahan jaringan. Coba lagi.';
        error_div.classList.remove('hidden');
    });
}

function tutupModal() {
    document.getElementById('modal-ganti-pin').remove();
    const url = new URL(window.location);
    url.searchParams.delete('first_login');
    window.history.replaceState({}, '', url);
}
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>