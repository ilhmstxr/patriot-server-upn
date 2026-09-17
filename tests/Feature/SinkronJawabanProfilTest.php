<?php

namespace Tests\Feature;

use App\Models\Identitas;
use App\Models\Kategori;
use App\Models\OpsiJawaban;
use App\Models\Penugasan;
use App\Models\Pertanyaan;
use App\Models\ResponPenugasan;
use App\Models\User;
use App\Services\PenugasanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Soal rubrik yang nilainya diturunkan dari profil (rumus persen & soal otomatis) harus ikut
 * berubah di DB begitu data identitas/agama diubah — bukan hanya di browser.
 */
class SinkronJawabanProfilTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Penugasan, 1: Identitas, 2: Pertanyaan, 3: ResponPenugasan} */
    private function buatC7(int $jmlMahasiswa = 1000): array
    {
        $user = User::factory()->create();
        $penugasan = Penugasan::factory()->for($user)->create(['status' => 'SUBMITTED']);
        $identitas = Identitas::factory()->create([
            'Penugasan_id' => $penugasan->id,
            'jml_mahasiswa' => $jmlMahasiswa,
        ]);

        $kategori = Kategori::factory()->create(['nama_kategori' => 'C. Test']);
        $pertanyaan = Pertanyaan::factory()->create([
            'category_id' => $kategori->id,
            'kode_pertanyaan' => 'C.7',
            'tipe' => 'isian_singkat',
            'kebutuhan_bukti' => 'Daftar mahasiswa peserta UKM',
        ]);

        // Ambang C.7 memang persentase (lihat PertanyaanSeeder)
        foreach ([20 => 1, 40 => 2, 60 => 3, 80 => 4, 100 => 5] as $value => $skor) {
            OpsiJawaban::create([
                'pertanyaan_id' => $pertanyaan->id,
                'opsi_jawaban' => (string) $skor,
                'value' => $value,
                'keterangan' => "≤ {$value}%",
            ]);
        }

        $jawaban = ResponPenugasan::create([
            'penugasan_id' => $penugasan->id,
            'pertanyaan_id' => $pertanyaan->id,
            'jawaban_teks' => ['raw_input' => 300, 'calculated_percentage' => null],
            'tautan_bukti_drive' => 'https://drive.google.com/bukti',
            'skor_sistem' => 1, // sengaja basi: hasil hitungan sebelum rumus profil jalan
        ]);

        return [$penugasan, $identitas, $pertanyaan, $jawaban];
    }

    public function test_resync_menghitung_persen_dari_profil(): void
    {
        [$penugasan, , , $jawaban] = $this->buatC7(1000);

        $berubah = app(PenugasanService::class)->sinkronJawabanProfil($penugasan->id);

        $this->assertSame(1, $berubah);
        $this->assertSame(30.0, (float) $jawaban->fresh()->jawaban_teks['calculated_percentage'], '300 dari 1000 mahasiswa = 30%');
        // 30% → bucket value >= 30 terkecil = 40 (skor 2)
        $this->assertSame(2, (int) $jawaban->fresh()->skor_sistem);
    }

    public function test_ubah_identitas_memicu_resync_lewat_event(): void
    {
        [, $identitas, , $jawaban] = $this->buatC7(1000);

        $identitas->update(['jml_mahasiswa' => 500]);

        $this->assertSame(60.0, (float) $jawaban->fresh()->jawaban_teks['calculated_percentage'], '300 dari 500 mahasiswa = 60%');
        // 60% → bucket 60 (skor 3)
        $this->assertSame(3, (int) $jawaban->fresh()->skor_sistem);
    }

    public function test_persen_di_bawah_0_01_tetap_skala_1(): void
    {
        [$penugasan, , , $jawaban] = $this->buatC7(40000);
        $jawaban->update(['jawaban_teks' => ['raw_input' => 1, 'calculated_percentage' => null]]);

        app(PenugasanService::class)->sinkronJawabanProfil($penugasan->id);

        // 1 dari 40.000 mahasiswa = 0,0025% → tampil 0,01%, bukan 0,00% (yang berarti "Tidak ada")
        $this->assertSame(0.01, (float) $jawaban->fresh()->jawaban_teks['calculated_percentage']);
        $this->assertSame(1, (int) $jawaban->fresh()->skor_sistem);
    }

    public function test_soal_otomatis_mengikuti_jml_ukm_profil(): void
    {
        $user = User::factory()->create();
        $penugasan = Penugasan::factory()->for($user)->create(['status' => 'SUBMITTED']);
        $identitas = Identitas::factory()->create(['Penugasan_id' => $penugasan->id, 'jml_ukm' => 7]);

        $kategori = Kategori::factory()->create(['nama_kategori' => 'C. Test']);
        $pertanyaan = Pertanyaan::factory()->create([
            'category_id' => $kategori->id,
            'kode_pertanyaan' => 'C.6',
            'tipe' => 'otomatis_sistem',
            'kebutuhan_bukti' => 'Dokumen administrasi',
        ]);
        foreach ([5 => 1, 10 => 2, 15 => 3, 20 => 4, 21 => 5] as $value => $skor) {
            OpsiJawaban::create([
                'pertanyaan_id' => $pertanyaan->id,
                'opsi_jawaban' => (string) $skor,
                'value' => $value,
                'keterangan' => "UKM {$value}",
            ]);
        }

        $jawaban = ResponPenugasan::create([
            'penugasan_id' => $penugasan->id,
            'pertanyaan_id' => $pertanyaan->id,
            'jawaban_teks' => ['raw_input' => 3, 'calculated_percentage' => null],
            'tautan_bukti_drive' => 'https://drive.google.com/bukti',
            'skor_sistem' => 1,
        ]);

        $identitas->update(['jml_ukm' => 12]);

        $this->assertSame(12, (int) $jawaban->fresh()->jawaban_teks['raw_input'], 'nilai C.6 diambil dari profil, bukan input lama');
        // 12 UKM → bucket value >= 12 terkecil = 15 (skor 3)
        $this->assertSame(3, (int) $jawaban->fresh()->skor_sistem);
    }

    public function test_penugasan_yang_sudah_dinilai_reviewer_tidak_diubah(): void
    {
        [, $identitas, , $jawaban] = $this->buatC7(1000);
        $jawaban->update(['skor_validasi_reviewer' => ['r1' => 4]]);

        $identitas->update(['jml_mahasiswa' => 500]);

        $this->assertSame(1, (int) $jawaban->fresh()->skor_sistem, 'skor tidak boleh dihitung ulang di tengah penilaian');
        $this->assertNull($jawaban->fresh()->jawaban_teks['calculated_percentage']);
    }

    public function test_resync_tidak_menyentuh_jawaban_yang_sudah_sesuai(): void
    {
        [$penugasan, $identitas] = $this->buatC7(1000);

        $service = app(PenugasanService::class);
        $this->assertSame(1, $service->sinkronJawabanProfil($penugasan->id));
        $this->assertSame(0, $service->sinkronJawabanProfil($penugasan->id), 'tanpa perubahan → updated_at tidak boleh berubah');

        // Mengubah hal yang tidak dipakai rumus C.7 juga tidak menyentuh jawaban.
        $identitas->update(['jml_tendik' => 999]);
        $this->assertSame(0, $service->sinkronJawabanProfil($penugasan->id));
    }
}
