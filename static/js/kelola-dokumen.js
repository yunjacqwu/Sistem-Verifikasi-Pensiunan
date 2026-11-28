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
