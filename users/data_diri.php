<?php
// file: users/data_diri.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$npk = $_SESSION['user_npk'];

try {
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

    // Format tanggal dd/mm/yyyy
    $tgl_lhr_fmt = $user_raw['tgl_lhr']
        ? date('d/m/Y', strtotime($user_raw['tgl_lhr'])) : '-';
    $tgl_msk_fmt = $user_raw['tgl_msk']
        ? date('d/m/Y', strtotime($user_raw['tgl_msk'])) : '-';
    $tgl_psn_fmt = $user_raw['tgl_psn']
        ? date('d/m/Y', strtotime($user_raw['tgl_psn'])) : '-';
    $jk_label = $user_raw['jk'] === 'L' ? 'Laki-laki' : ($user_raw['jk'] === 'P' ? 'Perempuan' : '-');

} catch (PDOException $e) {
    die("Gagal memuat data.");
}
$page_title = 'Detail Data Diri';
?>
<?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto p-4 md:p-6 space-y-6">

        <div class="mt-4">
            <a href="dashboard.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-blue-600 font-medium transition-colors mb-4 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Kembali ke Dashboard
            </a>
            <h2 class="text-3xl font-bold text-slate-800">Detail Data Diri</h2>
            <p class="text-slate-500 mt-2">Pastikan informasi kontak dan domisili Anda selalu terkini.</p>
        </div>

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

        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-xl flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-blue-700 text-sm">Anda hanya dapat mengubah <strong>Nomor Telepon</strong> dan <strong>Alamat</strong>. Untuk perubahan data resmi, hubungi Administrator.</p>
        </div>

        <form action="proses_update_data.php" method="POST" class="space-y-6 pb-10">
            <?= csrf_field(); ?>

            <!-- Identitas Resmi -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/>
                        </svg>
                        Identitas Resmi (Tidak Dapat Diubah)
                    </h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">NPK</label>
                        <input type="text" value="<?= htmlspecialchars($user['npk']); ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 font-bold font-mono outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">NIK</label>
                        <input type="text" value="<?= htmlspecialchars($user['nik'] ?: '—'); ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Nama Lengkap</label>
                        <input type="text" value="<?= htmlspecialchars($user['nama']); ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Tempat, Tanggal Lahir</label>
                        <input type="text" value="<?= htmlspecialchars($user['tmpt_lhr'] ?: '—') . ', ' . $tgl_lhr_fmt; ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Jenis Kelamin</label>
                        <input type="text" value="<?= $jk_label; ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                </div>
            </div>

            <!-- Data Kepensiunan -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                            <path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
                            <line x1="12" y1="12" x2="12" y2="16"/>
                            <line x1="10" y1="14" x2="14" y2="14"/>
                        </svg>
                        Data Kepensiunan
                    </h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Tanggal Masuk</label>
                        <input type="text" value="<?= $tgl_msk_fmt; ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Tanggal Pensiun</label>
                        <input type="text" value="<?= $tgl_psn_fmt; ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Jenis Manfaat Pensiun</label>
                        <input type="text" value="<?= htmlspecialchars($user_raw['jns_mnft'] ?: '—'); ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-600 mb-2">Status</label>
                        <input type="text" value="<?= htmlspecialchars($user_raw['status']); ?>" readonly
                               class="w-full px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-blue-700 font-bold outline-none cursor-not-allowed">
                    </div>
                </div>
            </div>

            <!-- Kontak & Domisili (bisa diubah) -->
            <div class="bg-white rounded-2xl shadow-md border-2 border-blue-600 overflow-hidden relative">
                <div class="absolute top-0 right-0 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-bl-lg">
                    Dapat Diubah
                </div>
                <div class="bg-white px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Informasi Kontak & Domisili
                    </h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Nomor Telepon Utama <span class="text-red-500">*</span></label>
                        <input type="tel" name="telp" value="<?= htmlspecialchars($user['telp']); ?>" required
                               class="w-full px-4 py-3 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition-all text-slate-800 text-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Nomor Telepon Cadangan <span class="text-slate-400 font-normal">(opsional)</span></label>
                        <input type="tel" name="telp2" value="<?= htmlspecialchars($user['telp2']); ?>"
                               placeholder="Nomor cadangan jika nomor utama tidak dapat dihubungi"
                               class="w-full px-4 py-3 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition-all text-slate-800 text-lg">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Alamat Lengkap <span class="text-red-500">*</span></label>
                        <textarea name="alamat" rows="3" required
                                  class="w-full px-4 py-3 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none transition-all text-slate-800 text-lg leading-relaxed"><?= htmlspecialchars($user['alamat']); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit"
                    class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xl py-4 rounded-xl transition-all shadow-md flex justify-center items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Simpan Perubahan
            </button>

        </form>
    </div>
<?php include '../includes/footer.php'; ?>