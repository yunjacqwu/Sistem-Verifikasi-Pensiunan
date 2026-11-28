function pensiunanApp() {
    const DATA_AWAL = JSON.parse(document.getElementById('data-awal').textContent);
    return {
        modalDetail:  false,
        modalReset:   false,
        modalNonaktif: false,
        modalTambah:  false,
        tab:          'identitas',
        editMode:     false,
        aktif:        {},
        resetNpk:     '',
        resetPinHasil: '',
        resetPinError: '',
        resetPinLoading: false,
        nonaktifNpk:  '',
        nonaktifStatus: '',
        riwayatLapor: DATA_AWAL.riwayatLapor,
        tahunRange: DATA_AWAL.tahunRange,
        dokumenMap: DATA_AWAL.dokumenMap,
        modalDok:   false,
        dokId:      '',
        dokSrc:     '',
        dokPdf:     false,
        dokNama:    '',
        dokJenis:   '',
        dokLoading: false,
        modalAlihStatus: false,
        alihNpk:         '',
        alihNama:        '',
        alihJenisPenerima: '',
        alihValidasiUmur:  '',

        bukaAlihStatus(npk, nama) {
            this.alihNpk             = npk;
            this.alihNama            = nama;
            this.alihJenisPenerima   = '';
            this.alihValidasiUmur    = '';
            this.modalAlihStatus     = true;
        },

        hitungUmur(tgl_lahir) {
            if (!tgl_lahir) return;
            const today = new Date();
            const lahir = new Date(tgl_lahir);
            const umur  = today.getFullYear() - lahir.getFullYear()
                        - (today < new Date(today.getFullYear(), lahir.getMonth(), lahir.getDate()) ? 1 : 0);

            if (this.alihJenisPenerima === 'Anak' && umur >= 25) {
                this.alihValidasiUmur = 'Penerima anak tidak memenuhi syarat (usia ' + umur + ' tahun ≥ 25 tahun).';
            } else {
                this.alihValidasiUmur = '';
            }
        },

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
                .catch(() => { this.dokLoading = false; });
        },

        async bukaDetail(npk) {
            try {
                const r = await fetch('lihat_profil.php?npk=' + encodeURIComponent(npk));
                if (!r.ok) throw new Error('HTTP ' + r.status);
                this.aktif       = await r.json();
                this.aktif.nama_penerima_asli = this.aktif.nama_asli_plain || '';
                this.tab         = 'identitas';
                this.editMode    = false;
                this.modalDetail = true;
            } catch (e) {
                alert('Data profil tidak bisa dibuka. Coba lagi.');
            }
        },
        async resetPinKirim(form) {
            this.resetPinLoading = true;
            this.resetPinError = '';
            try {
                const r = await fetch('proses_reset_pin.php', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json' },
                });
                const data = await r.json().catch(() => null);
                if (!r.ok || !data || !data.pin) {
                    throw new Error((data && data.error) || 'Gagal reset PIN. Coba lagi.');
                }
                this.resetPinHasil = data.pin;
            } catch (e) {
                this.resetPinError = e.message;
            } finally {
                this.resetPinLoading = false;
            }
        },

        bukaResetPin(npk) {
            this.resetNpk = npk;
            this.resetPinHasil = '';
            this.resetPinError = '';
            this.modalReset = true;
        },
        bukaNonaktif(npk, status) {
            this.nonaktifNpk    = npk;
            this.nonaktifStatus = status;
            this.modalNonaktif  = true;
        },
    }
}
