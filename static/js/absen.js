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

document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-aksi]');
    if (!a) return;
    var aksi = { 'tutup-preview': tutupPreview, 'kirim-foto': kirimFoto }[a.getAttribute('data-aksi')];
    if (aksi) aksi();
});
