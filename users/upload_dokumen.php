<?php
// file: users/upload_dokumen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$npk = $_SESSION['user_npk'];

// Proses upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('upload_dokumen.php');
    $jns_dok    = $_POST['jns_dok'] ?? '';
    $file_input = $_FILES['berkas'] ?? null;

    if (empty($jns_dok) || empty($file_input['name'])) {
        $pesan_error = "Harap pilih jenis dokumen dan file terlebih dahulu.";
    } else {
        $ekstensi_ok = ['jpg', 'jpeg', 'png', 'pdf'];
        $ekstensi    = strtolower(pathinfo($file_input['name'], PATHINFO_EXTENSION));

        if (!in_array($ekstensi, $ekstensi_ok)) {
            $pesan_error = "Format file tidak didukung. Gunakan JPG, PNG, atau PDF.";
        } elseif ($file_input['size'] > 2097152) {
            $pesan_error = "Ukuran file maksimal 2 MB.";
        } else {
            try {
                // Baca file sebagai binary
                $file_binary = file_get_contents($file_input['tmp_name']);

                if ($file_binary === false) {
                    throw new RuntimeException("Gagal membaca file.");
                }

                // Enkripsi file binary
                $file_enc = enkripsiFile($file_binary, $npk);

                // Cek apakah dokumen jenis ini sudah ada
                $cek = $db->prepare("SELECT id, file FROM dokumen WHERE npk = :npk AND jns_dok = :jns_dok LIMIT 1");
                $cek->execute(['npk' => $npk, 'jns_dok' => $jns_dok]);
                $dok_lama = $cek->fetch();

                if ($dok_lama) {
                    // Update dokumen (re-upload)
                    $upd = $db->prepare("
                        UPDATE dokumen
                        SET file = :file, status = 'Menunggu Verifikasi', updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $upd->execute(['file' => $file_enc, 'id' => $dok_lama['id']]);
                } else {
                    // Insert baru
                    $ins = $db->prepare("
                        INSERT INTO dokumen (npk, jns_dok, file, status)
                        VALUES (:npk, :jns_dok, :file, 'Menunggu Verifikasi')
                    ");
                    $ins->execute(['npk' => $npk, 'jns_dok' => $jns_dok, 'file' => $file_enc]);
                }

                // Catat aktivitas Upload Dokumen ke audit log (berlaku untuk upload baru maupun re-upload)
                $stmt_log = $db->prepare("
                    INSERT INTO audit_logs (npk, tabel_terkait, kolom_diubah, nilai_lama, nilai_baru, diubah_oleh)
                    VALUES (:npk, 'dokumen', 'dokumen_upload', NULL, :jenis, :oleh)
                ");
                $stmt_log->execute(['npk' => $npk, 'jenis' => $jns_dok, 'oleh' => $npk]);

                $pesan_sukses = "Dokumen $jns_dok berhasil diunggah dan sedang menunggu verifikasi admin.";

            } catch (Exception $e) {
                $pesan_error = "Gagal memproses dokumen. Silakan coba lagi.";
            }
        }
    }
}

// Ambil status dokumen
try {
    $stmt = $db->prepare("SELECT jns_dok, status FROM dokumen WHERE npk = :npk");
    $stmt->execute(['npk' => $npk]);
    $list_dok = [];
    foreach ($stmt->fetchAll() as $row) {
        $list_dok[$row['jns_dok']] = $row['status'];
    }
} catch (PDOException $e) {
    $list_dok = [];
}
$page_title = 'Unggah Dokumen';
?>
<?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto p-4 md:p-6 space-y-6">

        <div class="mt-4">
            <a href="dashboard.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-blue-600 font-medium bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm text-sm mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Kembali ke Dashboard
            </a>
            <h2 class="text-3xl font-bold text-slate-800">Unggah Dokumen</h2>
            <p class="text-slate-500 mt-1">Unggah KTP dan Kartu Keluarga untuk verifikasi data kepesertaan.</p>
        </div>

        <?php if (!empty($pesan_sukses)): ?>
            <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded-r-xl font-medium">
                <?= htmlspecialchars($pesan_sukses); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($pesan_error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-xl font-medium">
                <?= htmlspecialchars($pesan_error); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- Form Upload -->
            <div class="md:col-span-1 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-slate-100 pb-2">Form Unggah</h3>
                <form action="upload_dokumen.php" method="POST" enctype="multipart/form-data" class="space-y-5">
                    <?= csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1.5">Jenis Dokumen</label>
                        <select name="jns_dok" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Jenis --</option>
                            <option value="KTP">Kartu Tanda Penduduk (KTP)</option>
                            <option value="KK">Kartu Keluarga (KK)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1.5">Pilih File</label>
                        <input type="file" name="berkas" required accept=".jpg,.jpeg,.png,.pdf"
                               class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-slate-300 rounded-lg p-1.5 bg-slate-50 outline-none">
                        <p class="text-xs text-slate-400 mt-1.5">JPG, PNG, atau PDF. Maks 2 MB.</p>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-sm text-sm">
                        Unggah Dokumen
                    </button>
                </form>
            </div>

            <!-- Status Dokumen -->
            <div class="md:col-span-2 bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-slate-100 pb-2">Status Dokumen</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">

                    <?php foreach (['KTP' => 'Kartu Tanda Penduduk', 'KK' => 'Kartu Keluarga'] as $kode => $label): ?>
                    <div class="border border-slate-200 rounded-xl p-4 flex flex-col items-center bg-slate-50">
                        <span class="text-sm font-bold text-slate-700 mb-3"><?= $label; ?></span>

                        <?php if (isset($list_dok[$kode])): ?>
                            <?php
                            $status = $list_dok[$kode];
                            $badge_class = match($status) {
                                'Disetujui'          => 'bg-green-100 text-green-700 border-green-200',
                                'Ditolak'            => 'bg-red-100 text-red-700 border-red-200',
                                default              => 'bg-amber-100 text-amber-700 border-amber-200',
                            };
                            $badge_icon = match($status) {
                                'Disetujui' => '✅',
                                'Ditolak'   => '❌',
                                default     => '⏳',
                            };
                            ?>
                            <!-- Ikon dokumen tersimpan terenkripsi -->
                            <div class="w-full h-32 flex flex-col items-center justify-center border border-dashed border-slate-300 rounded-lg bg-white mb-3 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-blue-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <p class="text-xs text-slate-400 text-center">Dokumen tersimpan<br>terenkripsi</p>
                            </div>
                            <span class="text-xs font-bold px-3 py-1 rounded-full border <?= $badge_class; ?>">
                                <?= $badge_icon; ?> <?= $status; ?>
                            </span>
                        <?php else: ?>
                            <div class="w-full h-32 flex flex-col items-center justify-center border border-dashed border-slate-300 rounded-lg bg-white mb-3 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-300 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <p class="text-xs text-center">Belum diunggah</p>
                            </div>
                            <span class="text-xs font-bold px-3 py-1 rounded-full border bg-slate-100 text-slate-600 border-slate-200">
                                Belum Ada
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>
    </div>
<?php include '../includes/footer.php'; ?>