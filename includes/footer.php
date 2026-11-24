</div><!-- penutup container konten -->

    <footer class="bg-white border-t border-slate-200 mt-auto">
        <div class="max-w-4xl mx-auto px-4 py-4 flex flex-col sm:flex-row items-center justify-between gap-1 sm:gap-2 text-center sm:text-left text-slate-600">
            <span class="text-sm">&copy; <?= date('Y'); ?> Dana Pensiun Pegawai Perum Peruri. Hak cipta dilindungi.</span>
            <span class="text-xs">Sistem Verifikasi Kelangsungan Hidup Pensiunan</span>
        </div>
    </footer>
    <?php if (isset($extra_script)): ?>
        <?= $extra_script; ?>
    <?php endif; ?>
</body>
</html>