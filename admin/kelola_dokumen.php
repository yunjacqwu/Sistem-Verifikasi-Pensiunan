<?php
// file: admin/kelola_dokumen.php
require_once '../config/bootstrap.php';
require_once '../config/koneksi.php';
require_once '../config/crypto.php';
require_once '../config/helper.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');

// Parameter
$filter_jenis   = $_GET['jenis']   ?? '';
$filter_status  = $_GET['status']  ?? '';
$search         = trim($_GET['search'] ?? '');
$per_page       = in_array((int)($_GET['per_page'] ?? 25), [25,50,100,250,500])
                    ? (int)($_GET['per_page'] ?? 25) : 25;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $per_page;

try {
    // Bangun WHERE untuk filter yang bisa dilakukan di SQL (search nama TIDAK di sini)
    $where  = ["1=1"];
    $params = [];

    if (!empty($filter_jenis)) {
        $where[]          = "d.jns_dok = :jenis";
        $params['jenis']  = $filter_jenis;
    }
    if (!empty($filter_status)) {
        $where[]           = "d.status = :status";
        $params['status']  = $filter_status;
    }

    $where_sql = implode(' AND ', $where);

    // Ambil SEMUA data yang lolos filter SQL dulu (tanpa pagination)
    $stmt_all = $db->prepare("
        SELECT d.id, d.npk, d.jns_dok, d.status, d.created_at, d.updated_at, p.nama
        FROM dokumen d
        JOIN pensiunan p ON d.npk = p.npk
        WHERE $where_sql
        ORDER BY d.updated_at DESC
    ");
    $stmt_all->execute($params);
    $semua_data = $stmt_all->fetchAll();

    // Dekripsi nama + format waktu untuk semua data
    foreach ($semua_data as &$row) {
        $row['nama_plain'] = dekripsi($row['nama'], $row['npk']);
        $fmt_upd = formatTanggal($row['updated_at']);
        $fmt_cre = formatTanggal($row['created_at']);
        $row['updated_fmt'] = $fmt_upd['tanggal'] . ' ' . date('H:i', strtotime($row['updated_at']));
        $row['created_fmt'] = $fmt_cre['tanggal'] . ' ' . date('H:i', strtotime($row['created_at']));
    }
    unset($row);

    // Filter search (nama atau NPK) di PHP
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $semua_data = array_filter($semua_data, function($row) use ($search_lower) {
            return str_contains(strtolower($row['nama_plain']), $search_lower)
                || str_contains(strtolower($row['npk']), $search_lower);
        });
        $semua_data = array_values($semua_data);
    }

    // Pagination manual setelah filter PHP
    $total_data = count($semua_data);
    $total_page = max(1, ceil($total_data / $per_page));
    $page       = min($page, $total_page);
    $offset     = ($page - 1) * $per_page;
    $daftar     = array_slice($semua_data, $offset, $per_page);

} catch (PDOException $e) {
    die("Gagal memuat data.");
}

$query_params = $_GET;
unset($query_params['page']);
$query_string = http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/absensi_pensiunan/favicon.png">
    <title>Kelola Dokumen - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-800">
<div class="flex h-screen overflow-hidden">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/topbar.php'; ?>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6 space-y-5"
              x-data="dokumenApp()">

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

            <!-- Header -->
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Kelola Dokumen</h2>
                <p class="text-slate-500 text-sm mt-1">Verifikasi KTP dan Kartu Keluarga pensiunan.</p>
            </div>

            <!-- Filter -->
            <form method="GET" class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">

                    <!-- Search NPK -->
                    <div class="col-span-2 relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                            placeholder="Cari NPK atau Nama..."
                            class="pl-9 pr-4 py-2 w-full border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Filter Jenis -->
                    <select name="jenis" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Jenis</option>
                        <option value="KTP" <?= $filter_jenis === 'KTP' ? 'selected' : ''; ?>>KTP</option>
                        <option value="KK"  <?= $filter_jenis === 'KK'  ? 'selected' : ''; ?>>Kartu Keluarga</option>
                    </select>

                    <!-- Filter Status -->
                    <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Semua Status</option>
                        <option value="Menunggu Verifikasi" <?= $filter_status === 'Menunggu Verifikasi' ? 'selected' : ''; ?>>Menunggu Verifikasi</option>
                        <option value="Disetujui"           <?= $filter_status === 'Disetujui'           ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="Ditolak"             <?= $filter_status === 'Ditolak'             ? 'selected' : ''; ?>>Ditolak</option>
                    </select>

                    <!-- Tombol -->
                    <div class="col-span-1 flex gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors">Filter</button>
                        <a href="kelola_dokumen.php" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition-colors">Reset</a>
                    </div>

                </div>

                <!-- Per page -->
                <div class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                    <span>Tampilkan</span>
                    <select name="per_page" onchange="this.form.submit()"
                            class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ([25,50,100,250,500] as $n): ?>
                            <option value="<?= $n; ?>" <?= $per_page === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>baris — Total: <strong><?= number_format($total_data); ?></strong> dokumen</span>
                </div>
            </form>

            <!-- Tabel -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-3 text-left font-semibold">Pensiunan</th>
                                <th class="px-4 py-3 text-left font-semibold">Jenis</th>
                                <th class="px-4 py-3 text-left font-semibold">Diunggah</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($daftar)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-slate-400">
                                        Tidak ada dokumen yang sesuai filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($daftar as $row): ?>
                                <?php $status = $row['status']; ?>
                                <tr class="hover:bg-slate-50 transition-colors">

                                    <!-- Pensiunan -->
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= htmlspecialchars($row['nama_plain']); ?></p>
                                        <p class="text-xs text-slate-400 font-mono">NPK: <?= htmlspecialchars($row['npk']); ?></p>
                                    </td>

                                    <!-- Jenis -->
                                    <td class="px-4 py-3">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-bold
                                            <?= $row['jns_dok'] === 'KTP' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                                            <?= $row['jns_dok']; ?>
                                        </span>
                                    </td>

                                    <!-- Waktu -->
                                    <td class="px-4 py-3">
                                        <div class="flex items-start gap-2">
                                            <?php if ($status === 'Menunggu Verifikasi'): ?>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" title="Belum diproses">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                                </svg>
                                            <?php endif; ?>
                                            <div class="text-slate-600 text-xs">
                                                <p>Upload: <?= $row['created_fmt']; ?></p>
                                                <p class="text-slate-400">Update: <?= $row['updated_fmt']; ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-3">
                                        <?php
                                        $badge  = match($status) {
                                            'Disetujui' => 'bg-emerald-100 text-emerald-700',
                                            'Ditolak'   => 'bg-red-100 text-red-700',
                                            default     => 'bg-amber-100 text-amber-700 animate-pulse',
                                        };
                                        ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge; ?>">
                                            <?= $status; ?>
                                        </span>
                                    </td>

                                    <!-- Aksi -->
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1">

                                            <!-- Lihat -->
                                            <button @click="bukaDokumen('<?= $row['id']; ?>', '<?= htmlspecialchars($row['nama_plain'], ENT_QUOTES); ?>', '<?= $row['jns_dok']; ?>')"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors" title="Lihat Dokumen">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </button>

                                            <!-- Setujui -->
                                            <form action="proses_verifikasi_dokumen.php" method="POST" class="inline">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="id_dok" value="<?= $row['id']; ?>">
                                                <input type="hidden" name="npk"    value="<?= $row['npk']; ?>">
                                                <input type="hidden" name="aksi"   value="Disetujui">
                                                <button type="submit"
                                                        <?= $status === 'Disetujui' ? 'disabled' : ''; ?>
                                                        class="p-1.5 rounded-lg transition-colors
                                                        <?= $status === 'Disetujui' ? 'text-slate-300 cursor-not-allowed' : 'text-slate-500 hover:text-emerald-600 hover:bg-emerald-50'; ?>"
                                                        title="Setujui">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                </button>
                                            </form>

                                            <!-- Tolak -->
                                            <button @click="bukaTolak('<?= $row['id']; ?>', '<?= $row['npk']; ?>')"
                                                    <?= $status === 'Ditolak' ? 'disabled' : ''; ?>
                                                    class="p-1.5 rounded-lg transition-colors
                                                    <?= $status === 'Ditolak' ? 'text-slate-300 cursor-not-allowed' : 'text-slate-500 hover:text-red-600 hover:bg-red-50'; ?>"
                                                    title="Tolak">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>

                                        </div>
                                    </td>

                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_page > 1): ?>
                <div class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-600">
                    <p>Halaman <?= $page; ?> dari <?= $total_page; ?></p>
                    <div class="flex gap-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page-1; ?>&<?= $query_string; ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">← Prev</a>
                        <?php endif; ?>
                        <?php for ($i = max(1,$page-2); $i <= min($total_page,$page+2); $i++): ?>
                            <a href="?page=<?= $i; ?>&<?= $query_string; ?>"
                               class="px-3 py-1.5 rounded-lg border transition-colors <?= $i===$page ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 hover:bg-slate-50'; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_page): ?>
                            <a href="?page=<?= $page+1; ?>&<?= $query_string; ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Modal Lihat Dokumen -->
            <div x-show="modalDok" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                        <div>
                            <h3 class="font-bold text-slate-800" x-text="dokNama + ' — ' + dokJenis"></h3>
                            <p class="text-xs text-slate-400 mt-0.5">Dokumen Terenkripsi</p>
                        </div>
                        <button @click="modalDok = false" class="p-2 rounded-full border border-slate-200 text-slate-400 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="bg-slate-900 p-6 flex items-center justify-center min-h-[300px]">
                        <template x-if="dokLoading">
                            <div class="flex flex-col items-center gap-3 text-white">
                                <svg class="animate-spin h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                                </svg>
                                <p class="text-sm">Mendekripsi dokumen...</p>
                            </div>
                        </template>
                        <template x-if="!dokLoading && dokSrc">
                            <img :src="dokSrc" class="max-w-full max-h-[60vh] object-contain rounded border border-slate-700 shadow-lg">
                        </template>
                        <template x-if="!dokLoading && dokPdf">
                            <div class="flex flex-col items-center gap-3 text-white text-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm">Dokumen PDF — gunakan tombol Unduh untuk membuka.</p>
                            </div>
                        </template>
                    </div>
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center">
                        <a :href="'lihat_dokumen.php?id=' + dokId + '&mode=download'"
                           class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Unduh Dokumen
                        </a>
                        <button @click="modalDok = false" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2 rounded-lg text-sm font-medium">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Tolak -->
            <div x-show="modalTolak" class="fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-0 !mt-0" style="display:none;">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
                    <form action="proses_verifikasi_dokumen.php" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="id_dok" :value="tolakId">
                        <input type="hidden" name="npk"    :value="tolakNpk">
                        <input type="hidden" name="aksi"   value="Ditolak">
                        <input type="hidden" name="catatan" x-model="catatanFinal">

                        <h3 class="text-lg font-bold text-slate-800 mb-4">Tolak Dokumen</h3>

                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-600 mb-3">Pilih alasan penolakan:</p>

                            <?php
                            $preset_dok = [
                                'Dokumen tidak terbaca atau buram',
                                'Dokumen bukan milik pensiunan yang bersangkutan',
                                'Dokumen sudah kadaluarsa',
                                'Format file tidak sesuai ketentuan',
                                'Foto dokumen terpotong atau tidak lengkap',
                            ];
                            foreach ($preset_dok as $p):
                            ?>
                            <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition-colors"
                                   :class="catatanFinal === '<?= addslashes($p); ?>' ? 'border-blue-500 bg-blue-50' : ''">
                                <input type="radio" name="catatan_radio" value="<?= htmlspecialchars($p); ?>"
                                       @change="catatanFinal = $event.target.value; customCatatan = false"
                                       class="accent-blue-600">
                                <span class="text-sm text-slate-700"><?= htmlspecialchars($p); ?></span>
                            </label>
                            <?php endforeach; ?>

                            <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition-colors"
                                   :class="customCatatan ? 'border-blue-500 bg-blue-50' : ''">
                                <input type="radio" name="catatan_radio" value="custom"
                                       @change="customCatatan = true; catatanFinal = ''"
                                       class="accent-blue-600">
                                <span class="text-sm text-slate-700">Tulis alasan sendiri...</span>
                            </label>

                            <textarea x-show="customCatatan"
                                      x-model="catatanFinal"
                                      placeholder="Tuliskan alasan penolakan..."
                                      rows="3"
                                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none mt-1"></textarea>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="button" @click="modalTolak = false"
                                    class="flex-1 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:bg-slate-50">
                                Batal
                            </button>
                            <button type="submit"
                                    :disabled="!catatanFinal"
                                    :class="!catatanFinal ? 'bg-slate-300 text-slate-500 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700 text-white'"
                                    class="flex-1 py-2.5 rounded-xl font-bold text-sm transition-colors">
                                Konfirmasi Tolak
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
function dokumenApp() {
    return {
        modalDok:     false,
        modalTolak:   false,
        dokId:        '',
        dokSrc:       '',
        dokPdf:       false,
        dokNama:      '',
        dokJenis:     '',
        dokLoading:   false,
        tolakId:      '',
        tolakNpk:     '',
        catatanFinal: '',
        customCatatan: false,

        bukaDokumen(id, nama, jenis) {
            this.dokId      = id;
            this.dokNama    = nama;
            this.dokJenis   = jenis;
            this.dokSrc     = '';
            this.dokPdf     = false;
            this.dokLoading = true;
            this.modalDok   = true;

            fetch('lihat_dokumen.php?id=' + id)
                .then(r => {
                    const ct = r.headers.get('Content-Type') || '';
                    if (ct.includes('pdf')) {
                        this.dokPdf     = true;
                        this.dokLoading = false;
                        return null;
                    }
                    return r.blob();
                })
                .then(blob => {
                    if (blob) {
                        this.dokSrc     = URL.createObjectURL(blob);
                        this.dokLoading = false;
                    }
                })
                .catch(() => {
                    this.dokLoading = false;
                });
        },

        bukaTolak(id, npk) {
            this.tolakId      = id;
            this.tolakNpk     = npk;
            this.catatanFinal = '';
            this.customCatatan = false;
            this.modalTolak   = true;
        },
    }
}
</script>

</body>
</html>