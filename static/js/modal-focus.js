// Fokus keyboard untuk modal admin: Tab tetap di dalam modal yang terbuka,
// dan saat ditutup fokus kembali ke elemen yang membukanya.
// Modal dikenali dari elemen dengan x-show dan kelas "fixed inset-0" (pola yang dipakai Alpine di halaman admin).
(function () {
  var SELEKTOR_FOKUS = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function daftarModal() {
    return Array.prototype.slice.call(document.querySelectorAll('div.fixed.inset-0[x-show]'));
  }

  function sedangTampil(modal) {
    return window.getComputedStyle(modal).display !== 'none';
  }

  function elemenFokus(modal) {
    return Array.prototype.slice.call(modal.querySelectorAll(SELEKTOR_FOKUS)).filter(function (el) {
      return el.offsetParent !== null;
    });
  }

  function perubahanModal(modal) {
    var tampil = sedangTampil(modal);
    if (tampil && !modal._buka) {
      modal._buka = true;
      modal._pemicu = document.activeElement;
      var daftar = elemenFokus(modal);
      if (daftar.length) daftar[0].focus();
    } else if (!tampil && modal._buka) {
      modal._buka = false;
      if (modal._pemicu && document.contains(modal._pemicu)) modal._pemicu.focus();
      modal._pemicu = null;
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') return;
    var modal = daftarModal().filter(function (m) { return m._buka; })[0];
    if (!modal) return;
    var daftar = elemenFokus(modal);
    if (!daftar.length) return;
    var pertama = daftar[0];
    var terakhir = daftar[daftar.length - 1];
    if (e.shiftKey && (document.activeElement === pertama || !modal.contains(document.activeElement))) {
      e.preventDefault();
      terakhir.focus();
    } else if (!e.shiftKey && (document.activeElement === terakhir || !modal.contains(document.activeElement))) {
      e.preventDefault();
      pertama.focus();
    }
  });

  // Panah berpindah antar tombol, tautan, dan checkbox.
  // Di dalam modal yang terbuka, perpindahan dibatasi ke modal itu. Di halaman lain, ke seluruh halaman.
  // Isian teks dan dropdown tetap memakai panah bawaannya (mengetik, memilih opsi).
  document.addEventListener('keydown', function (e) {
    var panahMaju = e.key === 'ArrowDown' || e.key === 'ArrowRight';
    var panahMundur = e.key === 'ArrowUp' || e.key === 'ArrowLeft';
    if (!panahMaju && !panahMundur) return;
    var aktif = document.activeElement;
    var boleh = aktif && (aktif.tagName === 'BUTTON' || aktif.tagName === 'A' ||
                          (aktif.tagName === 'INPUT' && aktif.type === 'checkbox'));
    if (!boleh) return;
    var modal = daftarModal().filter(function (m) { return m._buka; })[0];
    var lingkup = modal || document;
    var daftar = elemenFokus(lingkup).filter(function (el) {
      return !(el.tagName === 'INPUT' && el.type === 'radio');
    });
    var i = daftar.indexOf(aktif);
    if (i === -1) return;
    e.preventDefault();
    var langkah = panahMaju ? 1 : -1;
    daftar[(i + langkah + daftar.length) % daftar.length].focus();
  });

  document.addEventListener('DOMContentLoaded', function () {
    daftarModal().forEach(function (modal) {
      new MutationObserver(function () { perubahanModal(modal); })
        .observe(modal, { attributes: true, attributeFilter: ['style'] });
    });
  });
})();
