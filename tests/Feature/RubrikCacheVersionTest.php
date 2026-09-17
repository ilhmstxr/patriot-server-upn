<?php

namespace Tests\Feature;

use App\Models\Kategori;
use App\Models\OpsiJawaban;
use App\Models\Penugasan;
use App\Models\Pertanyaan;
use App\Models\ResponPenugasan;
use App\Models\User;
use App\Services\PenugasanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cache rubrik peserta & skor hasil harus ikut berubah begitu data rubrik di DB berubah —
 * termasuk perubahan yang dilakukan lewat raw SQL tanpa menyentuh updated_at.
 */
class RubrikCacheVersionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Penugasan, 2: Pertanyaan, 3: OpsiJawaban, 4: ResponPenugasan} */
    private function buatRubrik(): array
    {
        $user = User::factory()->create();
        $penugasan = Penugasan::factory()->for($user)->create(['status' => 'SUBMITTED']);
        $kategori = Kategori::factory()->create(['nama_kategori' => 'A. Test']);
        $pertanyaan = Pertanyaan::factory()->create([
            'category_id' => $kategori->id,
            'kode_pertanyaan' => 'A.1',
            'tipe' => 'isian_singkat',
            'kebutuhan_bukti' => 'Dokumen pendukung',
        ]);

        // Ambang bucket: input 30 cocok ke opsi dengan value >= 30 yang terkecil
        $opsi1 = OpsiJawaban::create([
            'pertanyaan_id' => $pertanyaan->id,
            'opsi_jawaban' => '1',
            'value' => 10,
            'keterangan' => 'rendah',
        ]);
        OpsiJawaban::create([
            'pertanyaan_id' => $pertanyaan->id,
            'opsi_jawaban' => '2',
            'value' => 50,
            'keterangan' => 'tinggi',
        ]);

        $jawaban = ResponPenugasan::create([
            'penugasan_id' => $penugasan->id,
            'pertanyaan_id' => $pertanyaan->id,
            'jawaban_teks' => ['raw_input' => 30, 'calculated_percentage' => null],
            'tautan_bukti_drive' => 'https://drive.google.com/bukti',
            'skor_sistem' => 5, // sengaja "basi": hasil hitungan ambang rubrik lama
        ]);

        return [$user, $penugasan, $pertanyaan, $opsi1, $jawaban];
    }

    public function test_version_rubrik_berubah_saat_ambang_opsi_diubah_lewat_raw_update(): void
    {
        [, $penugasan, , $opsi1] = $this->buatRubrik();
        $service = app(PenugasanService::class);

        $sebelum = $service->getQuestionsVersion($penugasan)['version'];

        // Raw update ala migration revisi rubrik: updated_at tidak tersentuh
        DB::table('opsi_jawaban')->where('id', $opsi1->id)->update(['value' => 40]);

        $this->assertNotSame(
            $sebelum,
            $service->getQuestionsVersion($penugasan->fresh())['version'],
            'Token versi harus berubah supaya frontend refetch rubrik terbaru'
        );
    }

    public function test_skor_hasil_dihitung_ulang_pakai_ambang_rubrik_terkini(): void
    {
        [$user, , , $opsi1, $jawaban] = $this->buatRubrik();
        $service = app(PenugasanService::class);

        $skorTampil = fn () => $service->getHasilData($user->id)['categories'][0]['items'][0]['score'];

        // input 30 → bucket value>=30 terkecil = 50 (skor 2), bukan skor_sistem basi (5)
        $this->assertSame(2, $skorTampil());

        DB::table('opsi_jawaban')->where('id', $opsi1->id)->update(['value' => 40]);

        // 40 >= 30 → sekarang bucket ini yang dipakai (skor 1)
        $this->assertSame(1, $skorTampil());
        $this->assertSame(5, (int) $jawaban->fresh()->skor_sistem, 'skor_sistem di DB tidak boleh diubah');
    }
}
