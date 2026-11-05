<?php
// file: users/notifikasi.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$npk = $_SESSION['user_npk'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('notifikasi.php');
    try {
        $stmt_baca = $db->prepare("
            UPDATE notifikasi
            SET status_baca = 'Sudah Dibaca'
            WHERE npk = :npk AND status_baca = 'Belum Dibaca'
        ");
        $stmt_baca->execute(['npk' => $npk]);
    } catch (PDOException $e) {
        // Tetap render daftar notifikasi; tidak membocorkan detail database.
    }
}

try {
    // Ambil semua notifikasi milik user ini
    $stmt = $db->prepare("
        SELECT id, judul, pesan, jenis, status_baca, created_at
        FROM notifikasi
        WHERE npk = :npk
        ORDER BY created_at DESC
    ");
    $stmt->execute(['npk' => $npk]);
    $notifikasi_list = $stmt->fetchAll();

} catch (PDOException $e) {
    $notifikasi_list = [];
}

date_default_timezone_set('Asia/Jakarta');

$page_title = 'Notifikasi Saya';
?>
<?php include '../includes/header.php'; ?>

    <div class="max-w-4xl mx-auto p-4 md:p-6 space-y-4">

        <div class="mt-4">
            <a href="dashboard.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-blue-600 font-medium bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm text-sm mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Kembali ke Dashboard
            </a>
            <h2 class="text-3xl font-bold text-slate-800">Notifikasi</h2>
            <p class="text-slate-500 mt-1">Pemberitahuan terkait lapor diri dan dokumen Anda.</p>
            <form method="POST" action="notifikasi.php" class="mt-3">
                <?= csrf_field(); ?>
                <button type="submit" class="text-sm text-blue-600 hover:text-blue-700 font-semibold">Tandai semua sudah dibaca</button>
            </form>
        </div>

        <?php if (empty($notifikasi_list)): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-12 flex flex-col items-center justify-center text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-slate-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <p class="text-slate-500 font-medium">Belum ada notifikasi</p>
                <p class="text-slate-400 text-sm mt-1">Notifikasi akan muncul setelah admin memverifikasi lapor diri atau dokumen Anda.</p>
            </div>

        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($notifikasi_list as $notif):
                    $sudah_dibaca = $notif['status_baca'] === 'Sudah Dibaca';
                    $jenis        = $notif['jenis'];
                    $waktu        = date('d/m/Y H:i', strtotime($notif['created_at']));

                    // Warna dan ikon berdasarkan jenis
                    if ($jenis === 'lapor_diri') {
                        $ikon_bg  = 'bg-blue-100';
                        $ikon_svg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />';
                        $ikon_color = 'text-blue-600';
                    } else {
                        $ikon_bg  = 'bg-amber-100';
                        $ikon_svg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />';
                        $ikon_color = 'text-amber-600';
                    }

                    // Tentukan apakah ini notif diterima atau ditolak dari judul
                    $is_ditolak = str_contains(strtolower($notif['judul']), 'ditolak');
                    $border_class = $sudah_dibaca
                        ? 'border-slate-200'
                        : ($is_ditolak ? 'border-red-300 bg-red-50' : 'border-blue-300 bg-blue-50');
                ?>
                <div class="bg-white rounded-xl shadow-sm border <?= $border_class; ?> p-4 flex items-start gap-4 transition-all">

                    <!-- Ikon -->
                    <div class="flex-shrink-0 h-10 w-10 rounded-full <?= $ikon_bg; ?> flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 <?= $ikon_color; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <?= $ikon_svg; ?>
                        </svg>
                    </div>

                    <!-- Konten -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-bold text-slate-800 text-sm leading-snug">
                                <?= htmlspecialchars($notif['judul']); ?>
                                <?php if (!$sudah_dibaca): ?>
                                    <span class="inline-block w-2 h-2 bg-blue-500 rounded-full ml-1 align-middle"></span>
                                <?php endif; ?>
                            </p>
                            <span class="text-xs text-slate-400 whitespace-nowrap flex-shrink-0"><?= $waktu; ?> WIB</span>
                        </div>
                        <p class="text-sm text-slate-600 mt-1 leading-relaxed">
                            <?= htmlspecialchars($notif['pesan']); ?>
                        </p>
                        <span class="inline-block mt-2 text-xs px-2 py-0.5 rounded-full font-medium
                            <?= $jenis === 'lapor_diri' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'; ?>">
                            <?= $jenis === 'lapor_diri' ? 'Lapor Diri' : 'Dokumen'; ?>
                        </span>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

<?php include '../includes/footer.php'; ?>