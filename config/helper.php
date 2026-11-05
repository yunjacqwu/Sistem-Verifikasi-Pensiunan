<?php
// file: config/helper.php

function formatTanggal(string $datetime): array {
    $bulan_arr = [
        1  => 'Januari',  2  => 'Februari', 3  => 'Maret',
        4  => 'April',    5  => 'Mei',       6  => 'Juni',
        7  => 'Juli',     8  => 'Agustus',   9  => 'September',
        10 => 'Oktober',  11 => 'November',  12 => 'Desember',
    ];
    $ts      = strtotime($datetime);
    $tgl     = date('j', $ts);
    $bulan   = $bulan_arr[(int)date('n', $ts)];
    $tahun   = date('Y', $ts);
    $kuartal = 'Q' . ceil(date('n', $ts) / 3);
    $jam     = date('H:i:s', $ts) . ' WIB';

    return [
        'kuartal' => $kuartal,
        'tanggal' => $tgl . ' ' . $bulan . ' ' . $tahun,
        'jam'     => $jam,
        'label'   => $kuartal . ' / ' . $tgl . ' ' . $bulan . ' ' . $tahun,
    ];
}