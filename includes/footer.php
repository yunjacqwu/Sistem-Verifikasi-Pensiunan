</div><!-- penutup container konten -->

    <footer class="bg-white border-t border-slate-200 mt-auto">
        <div class="max-w-4xl mx-auto px-4 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-sm text-slate-400">
            <span>&copy; <?= date('Y'); ?> Dana Pensiun Perum Peruri. All rights reserved.</span>
            <span class="text-xs">Sistem Verifikasi Kelangsungan Hidup Pensiunan</span>
        </div>
    </footer>
    <?php if (isset($extra_script)): ?>
        <?= $extra_script; ?>
    <?php endif; ?>
</body>
</html>