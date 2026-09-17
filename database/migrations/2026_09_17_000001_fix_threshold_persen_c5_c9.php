<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan ambang persen C.5 & C.9.
 *
 * C.5 sebelumnya menyimpan nomor urut (1-5) sebagai `value`, padahal labelnya
 * 0,25% / 0,5% / 0,75% / 1% / >1% — akibatnya semua input ≤ 1% dapat skor 1.
 * C.9 benar kecuali bucket terakhir (">4%") yang dibatasi 5, samakan polanya
 * dengan bucket terbuka lain (C.10 memakai 201, B.18 memakai 100).
 */
return new class extends Migration
{
    /** value baru yang benar, per kode_pertanyaan => urutan => value. */
    private const PERBAIKAN = [
        'C.5' => [1 => 0.25, 2 => 0.5, 3 => 0.75, 4 => 1, 5 => 100],
        'C.9' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 100],
    ];

    /** Nilai lama, untuk rollback. */
    private const SEMULA = [
        'C.5' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
        'C.9' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
    ];

    public function up(): void
    {
        $this->terapkan(self::PERBAIKAN);
    }

    public function down(): void
    {
        $this->terapkan(self::SEMULA);
    }

    /** Update idempotent — pencocokan lewat kode_pertanyaan + urutan opsi. */
    private function terapkan(array $peta): void
    {
        foreach ($peta as $kode => $perUrutan) {
            foreach ($perUrutan as $urutan => $value) {
                DB::table('opsi_jawaban')
                    ->join('pertanyaans', 'pertanyaans.id', '=', 'opsi_jawaban.pertanyaan_id')
                    ->where('pertanyaans.kode_pertanyaan', $kode)
                    ->where('opsi_jawaban.opsi_jawaban', (string) $urutan)
                    ->update(['value' => $value]);
            }
        }
    }
};
