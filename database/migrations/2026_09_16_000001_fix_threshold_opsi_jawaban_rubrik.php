<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan ambang skor rubrik.
 *
 * findMatchingOpsiByValue() membaca kolom `opsi_jawaban.value` sebagai BATAS ATAS
 * bucket (opsi dengan value terkecil yang >= input). Beberapa indikator menyimpannya
 * sebagai batas bawah / nomor urut, sehingga skornya meleset satu bucket ke atas —
 * dan untuk C.7 selalu jatuh ke fallback (skor 5).
 */
return new class extends Migration
{
    /**
     * Nilai batas atas yang benar, per kode_pertanyaan => urutan => value.
     */
    private const PERBAIKAN = [
        'B.18' => [1 => 24.99, 2 => 49.99, 3 => 74.99, 4 => 99.99, 5 => 100],
        'B.20' => [1 => 24.99, 2 => 49.99, 3 => 74.99, 4 => 99.99, 5 => 100],
        'C.6'  => [1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 21],
        'C.7'  => [1 => 20, 2 => 40, 3 => 60, 4 => 80, 5 => 100],
        'C.10' => [1 => 20, 2 => 60, 3 => 120, 4 => 200, 5 => 201],
    ];

    /**
     * Nilai lama, untuk rollback.
     */
    private const SEMULA = [
        'B.18' => [1 => 1, 2 => 26, 3 => 51, 4 => 76, 5 => 100],
        'B.20' => [1 => 1, 2 => 26, 3 => 51, 4 => 76, 5 => 100],
        'C.6'  => [1 => 1, 2 => 6, 3 => 11, 4 => 16, 5 => 21],
        'C.7'  => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
        'C.10' => [1 => 1, 2 => 21, 3 => 61, 4 => 121, 5 => 201],
    ];

    public function up(): void
    {
        Schema::table('opsi_jawaban', function (Blueprint $table) {
            $table->decimal('value', 8, 4)->nullable()->change();
        });

        $this->terapkan(self::PERBAIKAN);
    }

    public function down(): void
    {
        $this->terapkan(self::SEMULA);

        Schema::table('opsi_jawaban', function (Blueprint $table) {
            $table->integer('value')->nullable()->change();
        });
    }

    /**
     * Update idempotent — pencocokan lewat kode_pertanyaan + urutan opsi.
     */
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
