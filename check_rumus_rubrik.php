<?php

/**
 * Cek ambang skor rubrik: untuk tiap indikator, pastikan input di dalam rentang
 * sebuah bucket menghasilkan skor (opsi_jawaban) bucket itu.
 *
 * Jalankan: php check_rumus_rubrik.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Pertanyaan;
use App\Repositories\PertanyaanRepository;

// kode => [ [input, skor harapan], ... ]
$kasus = [
    'B.6'  => [[0, 0], [0.5, 1], [25, 1], [25.01, 2], [50, 2], [75, 3], [100, 4], [100.01, 5], [300, 5]],
    'B.7'  => [[0, 0], [0.5, 1], [1, 1], [4.5, 5], [50, 5]],
    'B.18' => [[0, 0], [10, 1], [24.99, 1], [25, 2], [49.99, 2], [50, 3], [75, 4], [99.99, 4], [100, 5], [120, 5]],
    'B.20' => [[0, 0], [10, 1], [24.99, 1], [25, 2], [49.99, 2], [50, 3], [75, 4], [99.99, 4], [100, 5], [120, 5]],
    'C.6'  => [[0, 0], [3, 1], [5, 1], [6, 2], [11, 3], [16, 4], [20, 4], [21, 5], [40, 5]],
    'C.7'  => [[0, 0], [12.5, 1], [20, 1], [20.5, 2], [40, 2], [60, 3], [80, 4], [100, 5], [150, 5]],
    'C.10' => [[0, 0], [10, 1], [20, 1], [21, 2], [40, 2], [61, 3], [121, 4], [200, 4], [201, 5], [500, 5]],
];

$repo = app(PertanyaanRepository::class);
$gagal = 0;
$total = 0;

foreach ($kasus as $kode => $baris) {
    $pertanyaan = Pertanyaan::where('kode_pertanyaan', $kode)->first();

    if (!$pertanyaan) {
        echo "SKIP  {$kode} — pertanyaan tidak ada di DB ini\n";
        continue;
    }

    foreach ($baris as [$input, $harapan]) {
        $total++;
        $opsi = $repo->findMatchingOpsiByValue($pertanyaan->id, $input);
        $skor = $opsi ? (int) $opsi->opsi_jawaban : 0;

        if ($skor === $harapan) {
            continue;
        }

        $gagal++;
        $dapat = $opsi ? "{$skor} (\"{$opsi->keterangan}\", value={$opsi->value})" : 'null';
        echo "FAIL  {$kode} input {$input} → harap {$harapan}, dapat {$dapat}\n";
    }
}

echo $gagal === 0
    ? "OK — {$total} kasus lolos\n"
    : "\n{$gagal} dari {$total} kasus GAGAL\n";

exit($gagal === 0 ? 0 : 1);
