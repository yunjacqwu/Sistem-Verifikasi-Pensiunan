(function () {
    var el = document.getElementById('qr-kode');
    if (!el) return;
    var uri = el.getAttribute('data-uri');
    if (!uri || typeof qrcode !== 'function') return;
    var qr = qrcode(0, 'M');
    qr.addData(uri);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
})();
