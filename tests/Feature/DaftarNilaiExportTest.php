<?php

namespace Tests\Feature;

use App\Http\Controllers\DaftarNilaiExportController;
use App\Models\Identitas;
use App\Models\Institusi;
use App\Models\Kategori;
use App\Models\Penugasan;
use App\Models\Pertanyaan;
use App\Models\ResponPenugasan;
use App\Models\Reviewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class DaftarNilaiExportTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'email' => 'admin-export@example.test',
            'password' => bcrypt('password123'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);
    }

    private function createPeserta(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => bcrypt('password123'),
            'role' => 'PESERTA',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);
    }

    public function test_non_admin_cannot_access_export_endpoints(): void
    {
        $peserta = $this->createPeserta('peserta-blocked@example.test');

        $this->actingAs($peserta)
            ->getJson(route('admin.daftar-nilai.export.meta'))
            ->assertStatus(403);

        $this->actingAs($peserta)
            ->getJson(route('admin.daftar-nilai.export.chunk'))
            ->assertStatus(403);
    }

    public function test_meta_returns_total_chunk_config_and_full_rubrik_schema(): void
    {
        $admin = $this->createAdmin();
        $peserta = $this->createPeserta('pt1@example.test');
        $institusi = Institusi::create([
            'id' => (string) Str::uuid(),
            'nama_institusi' => 'Universitas Patriot Test',
            'jenis_institusi' => 'PTN',
        ]);

        Penugasan::create([
            'user_id' => $peserta->id,
            'institution_id' => $institusi->id,
            'tahun_periode' => 2026,
            'status' => 'GRADED',
            'nama_pic' => 'PIC Test',
            'no_hp_pic' => '08123456789',
            'nilai_reviewer_1' => 80,
            'nilai_reviewer_2' => 90,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.daftar-nilai.export.meta'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.chunk_size', DaftarNilaiExportController::DEFAULT_CHUNK_SIZE)
            ->assertJsonPath('data.max_per_second', 100);

        $schema = $response->json('data.rubrik_schema');
        $this->assertCount(3, $schema);
        $this->assertCount(5, $schema[0]['indikators']);
        $this->assertCount(20, $schema[1]['indikators']);
        $this->assertCount(15, $schema[2]['indikators']);
    }

    public function test_chunk_returns_keyset_paginated_data_previous_year_scores_and_rubrik_scores(): void
    {
        $admin = $this->createAdmin();
        $peserta = $this->createPeserta('pt-full@example.test');
        $revUser1 = User::create([
            'email' => 'rev1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'REVIEWER',
            'status' => 'ACTIVE',
        ]);
        $revUser2 = User::create([
            'email' => 'rev2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'REVIEWER',
            'status' => 'ACTIVE',
        ]);
        $r1 = Reviewer::create(['user_id' => $revUser1->id, 'nama_lengkap' => 'Dr. Penilai Satu', 'nip' => '111']);
        $r2 = Reviewer::create(['user_id' => $revUser2->id, 'nama_lengkap' => 'Dr. Penilai Dua', 'nip' => '222']);

        $institusi = Institusi::create([
            'id' => (string) Str::uuid(),
            'nama_institusi' => 'UPN "Veteran" Jawa Timur',
            'jenis_institusi' => 'PTN',
        ]);

        // Tahun lalu (2025)
        Penugasan::create([
            'user_id' => $peserta->id,
            'institution_id' => $institusi->id,
            'tahun_periode' => 2025,
            'status' => 'PUBLISHED',
            'nama_pic' => 'PIC 2025',
            'no_hp_pic' => '0811111111',
            'nilai_reviewer_1' => 78.70,
            'nilai_reviewer_2' => 78.70,
        ]);

        // Tahun berjalan (2026)
        $p2026 = Penugasan::create([
            'user_id' => $peserta->id,
            'institution_id' => $institusi->id,
            'tahun_periode' => 2026,
            'status' => 'GRADED',
            'nama_pic' => 'PIC 2026',
            'no_hp_pic' => '0822222222',
            'reviewer_1_id' => $r1->id,
            'reviewer_2_id' => $r2->id,
            'nilai_reviewer_1' => 78.10,
            'nilai_reviewer_2' => 90.74,
            'catatan_penilaian' => json_encode(['r1' => 'Sangat baik', 'r2' => 'Mantap']),
        ]);

        Identitas::create([
            'Penugasan_id' => $p2026->id,
            'jml_dosen' => 450,
            'jml_tendik' => 210,
            'jml_mahasiswa' => 18500,
            'jml_prodi' => 30,
            'jml_ukm' => 25,
            'jml_fakultas' => 7,
            'is_verified' => true,
        ]);

        $kat = Kategori::create([
            'nama_kategori' => 'A. Patriotisme Kebijakan',
        ]);
        $qA1 = Pertanyaan::create([
            'category_id' => $kat->id,
            'kode_pertanyaan' => 'A.1',
            'teks_pertanyaan' => 'Kebijakan Bela Negara',
            'tipe' => 'pilihan_ganda',
        ]);
        ResponPenugasan::create([
            'penugasan_id' => $p2026->id,
            'pertanyaan_id' => $qA1->id,
            'skor_sistem' => 5,
            'skor_validasi_reviewer' => ['r1' => 4, 'r2' => 5],
        ]);

        // Request chunk untuk tahun 2026 dengan limit > MAX_CHUNK_SIZE (harus di-clamp)
        $res = $this->actingAs($admin)
            ->getJson(route('admin.daftar-nilai.export.chunk', [
                'tahun_periode' => 2026,
                'limit' => 500,
                'mode' => 'full',
            ]));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonCount(1, 'data.items');

        $item = $res->json('data.items.0');
        $this->assertSame('UPN "Veteran" Jawa Timur', $item['nama_institusi']);
        $this->assertSame('GRADED', $item['status']);
        $this->assertEquals(84.42, $item['nilai_rata_rata']);
        $this->assertEquals(84.42, $item['total_skor_akhir']);
        $this->assertSame(4, $item['bintang']);
        $this->assertSame('Dr. Penilai Satu', $item['nama_penilai_1']);
        $this->assertSame('Dr. Penilai Dua', $item['nama_penilai_2']);
        $this->assertEquals(78.70, $item['nilai_tahun_lalu']);
        $this->assertSame(4, $item['bintang_tahun_lalu']);
        $this->assertSame(450, $item['jml_dosen']);
        $this->assertEquals([4, 5], $item['scores']['A.1']);
        $this->assertStringContainsString('Sangat baik', $item['catatan_penilaian']);
    }

    public function test_chunk_endpoint_enforces_per_second_rate_limit(): void
    {
        $admin = $this->createAdmin();
        RateLimiter::clear('daftar-nilai-export:' . $admin->id);

        for ($i = 0; $i < DaftarNilaiExportController::MAX_REQUESTS_PER_SECOND; $i++) {
            $this->actingAs($admin)
                ->getJson(route('admin.daftar-nilai.export.chunk', ['mode' => 'summary']))
                ->assertOk();
        }

        // Request ke-5 dalam 1 detik yang sama harus dibatasi (429)
        $this->actingAs($admin)
            ->getJson(route('admin.daftar-nilai.export.chunk', ['mode' => 'summary']))
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }
}
