function submitGantiPin() {
    const pin_baru       = document.getElementById('pin_baru').value.trim();
    const pin_konfirmasi = document.getElementById('pin_konfirmasi').value.trim();
    const error_div      = document.getElementById('error-pin');

    error_div.classList.add('hidden');
    error_div.textContent = '';

    if (pin_baru.length !== 6 || !/^\d{6}$/.test(pin_baru)) {
        error_div.textContent = 'PIN harus berupa 6 digit angka.';
        error_div.classList.remove('hidden');
        return;
    }
    if (pin_baru !== pin_konfirmasi) {
        error_div.textContent = 'Konfirmasi PIN tidak cocok.';
        error_div.classList.remove('hidden');
        return;
    }

    fetch('proses_ganti_pin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ pin_baru: pin_baru, csrf_token: document.querySelector('meta[name="csrf-token"]').content })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sukses) {
            document.getElementById('form-pin').classList.add('hidden');
            document.getElementById('sukses-pin').classList.remove('hidden');
        } else {
            error_div.textContent = data.pesan || 'Terjadi kesalahan.';
            error_div.classList.remove('hidden');
        }
    })
    .catch(() => {
        error_div.textContent = 'Terjadi kesalahan jaringan. Coba lagi.';
        error_div.classList.remove('hidden');
    });
}

function tutupModal() {
    document.getElementById('modal-ganti-pin').remove();
    const url = new URL(window.location);
    url.searchParams.delete('first_login');
    window.history.replaceState({}, '', url);
}

document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-aksi]');
    if (!a) return;
    var aksi = { 'ganti-pin': submitGantiPin, 'tutup-modal': tutupModal }[a.getAttribute('data-aksi')];
    if (aksi) aksi();
});
