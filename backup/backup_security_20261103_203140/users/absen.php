<?php
// file: users/absen.php
session_start();
require_once '../config/koneksi.php';
require_once '../config/crypto.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$npk = $_SESSION['user_npk'];

try {
    $stmt = $db->prepare("SELECT nama FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    $user = $stmt->fetch();
    $nama_user = $user ? dekripsi($user['nama'], $npk) : 'Pensiunan';
} catch (PDOException $e) {
    $nama_user = 'Pensiunan';
}

$page_title = 'Lapor Diri';

// Script kamera ditaruh di sini, nanti footer.php yang render
$extra_script = <<<'HTML'
<script>
    const video       = document.getElementById('live-camera');
    const btnSwitchKamera   = document.getElementById('btn-switch-kamera');
    const imgHasil    = document.getElementById('hasil-foto');
    const ktpOverlay  = document.getElementById('ktp-overlay');
    const btnJepret   = document.getElementById('btn-jepret');
    const grupAksi    = document.getElementById('grup-aksi');
    const btnUlang    = document.getElementById('btn-ulang');
    const canvas      = document.getElementById('canvas-foto');
    const inputBase64 = document.getElementById('input-foto-base64');
    const pesanError  = document.getElementById('pesan-error-kamera');
    let streamKamera  = null;
    let facingModeAktif     = 'user'; // mulai dari kamera depan
    let jumlahKameraTersedia = 1;

    async function nyalakanKamera(facingMode) {
        if (streamKamera) {
            streamKamera.getTracks().forEach(track => track.stop());
        }

        try {
            streamKamera = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: facingMode }, width: { ideal: 720 }, height: { ideal: 960 } },
                audio: false
            });
            video.srcObject = streamKamera;

            if (facingMode === 'user') {
                video.classList.add('scale-x-[-1]');
            } else {
                video.classList.remove('scale-x-[-1]');
            }
            facingModeAktif = facingMode;

            // Cek jumlah kamera SETELAH permission granted
            await cekJumlahKamera();

        } catch (err) {
            video.classList.add('hidden');
            ktpOverlay.classList.add('hidden');
            pesanError.classList.remove('hidden');
            btnJepret.disabled = true;
            btnJepret.classList.add('opacity-50', 'cursor-not-allowed');
            console.error('Kamera error:', err);
        }
    }

    // Cek jumlah kamera tersedia — kalau cuma 1, sembunyikan tombol switch
    async function cekJumlahKamera() {
        try {
            // Enumerate SETELAH stream aktif supaya deviceId tersedia
            const devices = await navigator.mediaDevices.enumerateDevices();
            const kameras = devices.filter(d => d.kind === 'videoinput');
            jumlahKameraTersedia = kameras.length;
            if (jumlahKameraTersedia <= 1) {
                btnSwitchKamera.classList.add('hidden');
            }
        } catch (err) {
            btnSwitchKamera.classList.add('hidden');
        }
    }

    btnSwitchKamera.addEventListener('click', () => {
        const facingModeBaru = facingModeAktif === 'user' ? 'environment' : 'user';
        nyalakanKamera(facingModeBaru);
    });

    nyalakanKamera('user');

    // Ganti addEventListener btnJepret yang lama dengan ini
    btnJepret.addEventListener('click', () => {
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');

        // Mirror capture HANYA kalau kamera depan aktif
        if (facingModeAktif === 'user') {
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const dataFoto = canvas.toDataURL('image/jpeg', 0.85);

        document.getElementById('input-foto-base64').value = dataFoto;
        document.getElementById('preview-besar').src       = dataFoto;
        document.getElementById('modal-preview').classList.remove('hidden');
    });

    function tutupPreview() {
        document.getElementById('modal-preview').classList.add('hidden');
        document.getElementById('input-foto-base64').value = '';
        document.getElementById('preview-besar').src       = '';
    }

    function kirimFoto() {
        // Loading state
        document.getElementById('icon-kirim-modal').classList.add('hidden');
        document.getElementById('icon-loading-modal').classList.remove('hidden');
        document.getElementById('teks-kirim-modal').textContent = 'Mengenkripsi...';

        // Submit form
        document.getElementById('form-kirim').submit();
    }

    btnUlang.addEventListener('click', () => {
        video.classList.remove('hidden');
        ktpOverlay.classList.remove('hidden');
        imgHasil.classList.add('hidden');
        imgHasil.src      = '';
        inputBase64.value = '';
        btnJepret.classList.remove('hidden');
        grupAksi.classList.add('hidden');
        grupAksi.classList.remove('flex');
    });

    // Loading state saat form disubmit
    document.getElementById('form-kirim').addEventListener('submit', function() {
        const btnKirim    = document.getElementById('btn-kirim');
        const iconKirim   = document.getElementById('icon-kirim');
        const iconLoading = document.getElementById('icon-loading');
        const teksKirim   = document.getElementById('teks-kirim');

        // Nonaktifkan tombol supaya tidak bisa diklik dua kali
        btnKirim.disabled = true;
        btnKirim.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
        btnKirim.classList.add('bg-emerald-400', 'cursor-not-allowed');

        // Ganti ikon dan teks
        iconKirim.classList.add('hidden');
        iconLoading.classList.remove('hidden');
        teksKirim.textContent = 'Mengenkripsi & Mengirim...';
    });
</script>
HTML;
?>
<?php include '../includes/header.php'; ?>

    <div class="space-y-6">

        <div>
            <a href="dashboard.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-blue-600 font-medium bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm text-sm mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Batal & Kembali
            </a>
            <h2 class="text-3xl font-bold text-slate-800">Lapor Diri</h2>
            <p class="text-slate-500 mt-2">
                Halo <strong><?= htmlspecialchars($nama_user); ?></strong>,
                arahkan kamera ke wajah Anda sambil memegang KTP di bawah wajah.
            </p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-sm font-semibold">
                <?= htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Instruksi -->
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
            <p class="font-bold mb-1">📋 Petunjuk pengambilan foto:</p>
            <ol class="list-decimal list-inside space-y-1">
                <li>Pastikan wajah Anda terlihat jelas di kamera</li>
                <li>Pegang KTP di bawah wajah menghadap kamera</li>
                <li>Pastikan tulisan KTP terbaca dengan jelas</li>
                <li>Pilih tempat dengan pencahayaan yang cukup</li>
            </ol>
        </div>

        <!-- Area kamera -->
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-6 flex flex-col items-center">

            <div class="w-full max-w-sm bg-slate-900 rounded-2xl overflow-hidden relative shadow-inner mb-6"
                 style="aspect-ratio: 3/4;">

                <video id="live-camera"
                       class="w-full h-full object-cover"
                       autoplay playsinline></video>

                <!-- Tombol switch kamera -->
                <button type="button" id="btn-switch-kamera"
                        class="absolute top-3 right-3 z-10 bg-black/50 hover:bg-black/70 text-white p-2.5 rounded-full transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>

                <img id="hasil-foto"
                     class="hidden w-full h-full object-cover"
                     src="" alt="Hasil Foto">

                <!-- Overlay siluet + frame KTP -->
                <div id="ktp-overlay" class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none px-4">
                    <svg viewBox="0 0 200 200" class="w-40 mb-6" xmlns="http://www.w3.org/2000/svg">
                        <!-- Outline wajah (oval) -->
                        <ellipse cx="100" cy="100" rx="55" ry="75" fill="none" stroke="white" stroke-width="3" stroke-dasharray="6 6" opacity="0.8"/>
                    </svg>
                    <!-- Frame KTP lebih kecil dan ada jarak dari siluet -->
                    <div class="relative" style="width:65%; aspect-ratio: 85.6/54; border: 3px dashed rgba(255,255,255,0.85); border-radius: 10px; box-shadow: 0 0 0 2000px rgba(0,0,0,0.35);">
                        <span class="absolute -bottom-7 left-1/2 -translate-x-1/2 whitespace-nowrap bg-black/55 text-white text-xs px-3 py-0.5 rounded-full">
                            Posisikan KTP di sini
                        </span>
                    </div>
                </div>

                <!-- Pesan error kamera -->
                <div id="pesan-error-kamera"
                     class="hidden absolute inset-0 flex items-center justify-center text-white text-center p-4 bg-slate-800">
                    <p class="font-semibold">Kamera tidak dapat diakses.<br>Pastikan izin kamera diberikan di browser.</p>
                </div>

            </div>

            <div class="w-full max-w-sm flex flex-col gap-4">

                <button type="button" id="btn-jepret"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg py-3.5 rounded-xl transition-all shadow-md flex justify-center items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Ambil Foto
                </button>

                <div id="grup-aksi" class="hidden flex-col gap-3 w-full">
                    <form action="proses_absen.php" method="POST" class="w-full" id="form-kirim">
                        <input type="hidden" name="foto_base64" id="input-foto-base64">
                        <button type="submit" id="btn-kirim"
                                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-lg py-3.5 rounded-xl transition-all shadow-md flex justify-center items-center gap-2">
                            <svg id="icon-kirim" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg id="icon-loading" class="hidden animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                            </svg>
                            <span id="teks-kirim">Kirim Lapor Diri</span>
                        </button>
                    </form>

                    <button type="button" id="btn-ulang"
                            class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-base py-2.5 rounded-xl transition-colors border border-slate-300">
                        Ulangi Foto
                    </button>
                </div>

            </div>

            <canvas id="canvas-foto" class="hidden"></canvas>
        </div>
    </div>
    <!-- Modal Preview Foto -->
    <div id="modal-preview" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5 flex flex-col items-center gap-4">
            
            <h3 class="text-lg font-bold text-slate-800 text-center">Cek Foto Sebelum Kirim</h3>
            <p class="text-slate-500 text-sm text-center -mt-2">Pastikan wajah dan KTP terlihat jelas.</p>

            <!-- Preview foto besar -->
            <img id="preview-besar" src="" alt="Preview Foto"
                class="w-full rounded-xl border border-slate-200 shadow-sm object-cover"
                style="aspect-ratio: 3/4;">

            <!-- Dua tombol -->
            <div class="flex gap-3 w-full">
                <button onclick="tutupPreview()"
                        class="flex-1 py-3 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 transition-colors text-sm">
                    Ulangi Foto
                </button>
                <button onclick="kirimFoto()"
                        class="flex-1 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold transition-colors text-sm flex items-center justify-center gap-2">
                    <svg id="icon-kirim-modal" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg id="icon-loading-modal" class="hidden animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                    </svg>
                    <span id="teks-kirim-modal">Kirim</span>
                </button>
            </div>

        </div>
    </div>

    <!-- Form tersembunyi untuk submit -->
    <form action="proses_absen.php" method="POST" id="form-kirim">
        <input type="hidden" name="foto_base64" id="input-foto-base64">
    </form>

<?php include '../includes/footer.php'; ?>