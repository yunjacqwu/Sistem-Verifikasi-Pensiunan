<?php // Daftar aktivitas satu sisi (admin atau pensiunan). Variabel: $events_page, $events_grouped. ?>
            <!-- Activity Feed -->
            <div class="space-y-3">
                <?php if (empty($events_page)): ?>
                    <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
                        Tidak ada aktivitas yang sesuai filter.
                    </div>
                <?php else: ?>
                    <?php
                    $hari_ini = date('Y-m-d');
                    $kemarin  = date('Y-m-d', strtotime('-1 day'));

                    foreach ($events_grouped as $tgl => $group_events):
                        if ($tgl === $hari_ini) {
                            $label_tgl = 'Hari ini';
                        } elseif ($tgl === $kemarin) {
                            $label_tgl = 'Kemarin';
                        } else {
                            $fmt = formatTanggal($tgl . ' 00:00:00');
                            $label_tgl = $fmt['tanggal'];
                        }
                    ?>

                    <!-- Separator tanggal -->
                    <div class="flex items-center gap-3 my-1">
                        <div class="flex-1 h-px bg-slate-200"></div>
                        <span class="text-xs font-semibold text-slate-500 bg-slate-50 px-3 py-1 rounded-full border border-slate-200 whitespace-nowrap">
                            <?= $label_tgl; ?>
                        </span>
                        <div class="flex-1 h-px bg-slate-200"></div>
                    </div>

                    <?php foreach ($group_events as $e): ?>
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden w-full" x-data="{ open: false }">

                        <div class="px-4 py-3 flex items-center gap-3 <?= $e['has_detail'] ? 'cursor-pointer hover:bg-slate-50' : ''; ?> transition-colors"
                            <?= $e['has_detail'] ? '@click="open = !open"' : ''; ?>>

                            <!-- Icon -->
                            <div class="h-10 w-10 rounded-full flex items-center justify-center bg-<?= $e['color']; ?>-100 text-<?= $e['color']; ?>-600 flex-shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <?= activityIconPath($e['icon']); ?>
                                </svg>
                            </div>

                            <!-- Teks -->
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-slate-800 text-sm truncate">
                                    <?php if ($e['is_user']): ?>
                                        <span class="font-mono text-slate-500"><?= htmlspecialchars($e['npk'] ?? ''); ?></span> /
                                        <?= htmlspecialchars($e['nama_plain']); ?>
                                        <?= $e['verb']; ?> <span class="font-bold"><?= $e['objek']; ?></span>
                                    <?php else: ?>
                                        <span class="text-blue-600">Admin</span>
                                        <span class="text-slate-500">(<?= htmlspecialchars($e['diubah_oleh']); ?>)</span>
                                        <?= $e['verb']; ?> <span class="font-bold"><?= $e['objek']; ?></span>
                                        <?php if (!empty($e['npk'])): ?>
                                            <?php $connector = ($e['objek'] === 'Pensiunan Baru') ? ':' : 'untuk'; ?>
                                            <?= $connector; ?> <span class="font-mono text-slate-500"><?= htmlspecialchars($e['npk']); ?></span> / <?= htmlspecialchars($e['nama_plain']); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs text-slate-500 mt-0.5"><?= $e['tanggal_fmt']; ?> · <?= $e['jam_fmt']; ?></p>
                            </div>

                            <!-- Chevron -->
                            <?php if ($e['has_detail']): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-500 flex-shrink-0 ml-auto transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            <?php endif; ?>
                        </div>

                        <!-- Detail -->
                        <?php if ($e['has_detail']): ?>
                        <div x-show="open" x-transition class="px-4 pb-4 pt-1 border-t border-slate-100 space-y-2 overflow-x-hidden" style="display:none;">
                            <?php
                            if ($e['tabel_terkait'] === 'dokumen' && $e['is_user']):
                                $jenis_list = array_column($e['details'], 'nilai_baru');
                            ?>
                                <p class="text-sm text-slate-600 pl-1">
                                    <span class="font-bold text-slate-700">01.</span> Mengunggah <span class="font-bold"><?= implode(' dan ', $jenis_list); ?></span>
                                </p>
                            <?php else: ?>
                                <?php
                                $kolom_terenkripsi = ['nama', 'nik', 'tmpt_lhr', 'telp', 'telp2', 'alamat'];
                                foreach ($e['details'] as $i => $d):
                                    $label = $kolom_label_map[$d['kolom_diubah']] ?? $d['kolom_diubah'];
                                    if (in_array($d['kolom_diubah'], $kolom_terenkripsi)) {
                                        $lama = !empty($d['nilai_lama']) ? dekripsi($d['nilai_lama'], $e['npk']) : 'kosong';
                                        $baru = !empty($d['nilai_baru']) ? dekripsi($d['nilai_baru'], $e['npk']) : 'kosong';
                                    } else {
                                        $lama = ($d['nilai_lama'] !== null && $d['nilai_lama'] !== '') ? $d['nilai_lama'] : 'kosong';
                                        $baru = $d['nilai_baru'] ?? 'kosong';
                                    }
                                ?>
                                    <p class="text-sm text-slate-600 pl-1 leading-relaxed break-words">
                                        <span class="font-bold text-slate-700"><?= sprintf('%02d', $i+1); ?>.</span> Mengubah <span class="font-bold"><?= htmlspecialchars($label); ?></span> dari
                                        <span class="font-mono text-xs bg-red-50 text-red-700 px-1.5 py-0.5 rounded break-all inline-block max-w-full align-bottom"><?= htmlspecialchars($lama); ?></span>
                                        jadi
                                        <span class="font-mono text-xs bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded break-all inline-block max-w-full align-bottom"><?= htmlspecialchars($baru); ?></span>
                                    </p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
