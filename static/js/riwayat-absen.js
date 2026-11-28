function riwayatApp() {
    const DATA_AWAL = JSON.parse(document.getElementById('data-awal').textContent);
    return {
        modalFoto:    false,
        modalTolak:   false,
        fotoId:       '',
        fotoSrc:      '',
        fotoNama:     '',
        fotoTgl:      '',
        fotoJam:      '',
        fotoLoading:  false,
        tolakId:      '',
        tolakNpk:     '',
        catatanFinal: '',
        customCatatan: false,
        riwayatDetail: DATA_AWAL.riwayatDetail,
        tahunRangeDetail: DATA_AWAL.tahunRangeDetail,
        modalDetailLapor: false,
        detailNpk:        '',
        detailNama:       '',

        bukaDetailLapor(npk, nama) {
            this.detailNpk  = npk;
            this.detailNama = nama;
            this.modalDetailLapor = true;
        },

        bukaFoto(id, nama, tgl, jam) {
            this.fotoId      = id;
            this.fotoNama    = nama;
            this.fotoTgl     = tgl;
            this.fotoJam     = jam;
            this.fotoSrc     = '';
            this.fotoLoading = true;
            this.modalFoto   = true;

            // Load foto via fetch ke endpoint dekripsi
            fetch('lihat_foto_lapor.php?id=' + id)
                .then(r => r.blob())
                .then(blob => {
                    this.fotoSrc     = URL.createObjectURL(blob);
                    this.fotoLoading = false;
                })
                .catch(() => {
                    this.fotoLoading = false;
                    this.fotoSrc     = '';
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
