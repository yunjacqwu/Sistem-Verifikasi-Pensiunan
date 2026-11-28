// Interaksi umum tanpa atribut onclick/onchange inline (agar CSP tidak perlu unsafe-inline).
// - [data-modal-buka="id"]  : menampilkan elemen dengan id tersebut
// - [data-modal-tutup="id"] : menyembunyikan elemen dengan id tersebut
// - select[data-auto-submit] : mengirim form induknya saat nilai berubah
(function () {
    document.addEventListener('click', function (e) {
        var buka = e.target.closest('[data-modal-buka]');
        if (buka) {
            var m = document.getElementById(buka.getAttribute('data-modal-buka'));
            if (m) m.classList.remove('hidden');
            return;
        }
        var tutup = e.target.closest('[data-modal-tutup]');
        if (tutup) {
            var t = document.getElementById(tutup.getAttribute('data-modal-tutup'));
            if (t) t.classList.add('hidden');
        }
    });

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.matches && el.matches('select[data-auto-submit]') && el.form) {
            el.form.submit();
        }
    });
})();
