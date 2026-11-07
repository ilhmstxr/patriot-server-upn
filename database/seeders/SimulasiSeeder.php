<?php

namespace Database\Seeders;

use App\Models\Agama;
use App\Models\Penugasan;
use App\Models\Identitas;
use App\Models\Institusi;
use App\Models\Reviewer;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SimulasiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // 1. Seed Reviewer User & profile record
        // 2. Seed Participant User (Peserta X)
        // 3. Seed Institusi
        // 4. Seed Penugasan linked to the reviewer
        // 5. Seed Identitas (legal documents without kalender_akademik)
        // 6. Seed Agama linked to Identitas

        $reviewerUser = User::where('email', 'reviewer@gmail.com')->first();
        if (!$reviewerUser) {
            $reviewerUser = User::create([
                'email' => 'reviewer@gmail.com',
                'password' => bcrypt('123123123'),
                'role' => 'REVIEWER',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ]);
        }

        $reviewer = Reviewer::where('user_id', $reviewerUser->id)->first();
        if (!$reviewer) {
            $reviewer = Reviewer::create([
                'user_id' => $reviewerUser->id,
                'nama_lengkap' => 'Reviewer',
                'nip' => '198001012000031001',
            ]);
        }


        $pesertaUser = User::where('email', '23082010166@student.upnjatim.ac.id')->first();
        if (!$pesertaUser) {
            $pesertaUser = User::create([
                'email' => '23082010166@student.upnjatim.ac.id',
                'password' => bcrypt('Ilham6769'),
                'role' => 'PESERTA',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ]);
        }


        $institusi = Institusi::where('domain_email', 'student.upnjatim.ac.id')->first();
        if (!$institusi) {
            $institusi = Institusi::create([
                'id' => (string) Str::uuid(),
                'nama_institusi' => 'Universitas Pembangunan Nasional Jawa Timur',
                'jenis_institusi' => 'PTN',
                'domain_email' => 'student.upnjatim.ac.id',
                'logo_url' => '/storage/verifikasi/logo.webp',
            ]);
        } else {
            $institusi->update([
                'nama_institusi' => 'Universitas Pembangunan Nasional Jawa Timur',
                'jenis_institusi' => 'PTN',
                'logo_url' => '/storage/verifikasi/logo.webp',
            ]);
        }


        $penugasan = Penugasan::where('user_id', $pesertaUser->id)->where('tahun_periode', 2026)->first();
        if (!$penugasan) {
            $penugasan = Penugasan::create([
                'user_id' => $pesertaUser->id,
                'tahun_periode' => 2026,
                'institution_id' => $institusi->id,
                'nama_pic' => 'Tester 1',
                'jabatan_pic' => 'Wakil Rektor 4',
                'no_hp_pic' => '081234567890',
                'status' => 'ACTIVE',
                'reviewer_1_id' => $reviewer->id,
            ]);
        } else {
            $penugasan->update([
                'institution_id' => $institusi->id,
                'nama_pic' => 'Tester 1',
                'jabatan_pic' => 'Wakil Rektor 4',
                'no_hp_pic' => '081234567890',
                'status' => 'ACTIVE',
                'reviewer_1_id' => $reviewer->id,
            ]);
        }


        $identitas = Identitas::where('Penugasan_id', $penugasan->id)->first();
        if (!$identitas) {
            $identitas = Identitas::create([
                'Penugasan_id' => $penugasan->id,
                'jml_mahasiswa' => 23000,
                'jml_dosen' => 2300,
                'jml_tendik' => 300,
                'jml_prodi' => 40,
                'jml_ukm' => 20,
                'jml_ormawa' => 40,
                'jml_fakultas' => 8,
                'visi' => 'Unggul dalam Ilmu Pengetahuan, Teknologi, dan Humaniora yang Berwawasan Kebangsaan demi Kemaslahatan Bangsa',
                'misi' => '1. Menyelenggarakan dan mengembangkan pendidikan berkarakter bela negara <br>
2. Meningkatkan budaya riset dalam pengembangan bidang IPTEK yang berdayaguna untuk kesejahteraan masyarakat <br>
3. Menyelenggarakan pengabdian kepada masyarakat  berbasis riset dan kearifan lokal <br>
4. Menyelenggarakan tata kelola yang baik dan bersih dalam rangka mencapai akuntabilitas pengelolaan anggaran <br>
5. Mengembangkan kualitas sumber daya manusia unggul dalam sikap dan tata nilai, unjuk kerja, penguasaan pengetahuan, dan manajerial <br>
6. Meningkatkan sistem pengelolaan sarana dan prasarana terpadu <br>
7. Meningkatkan kerjasama institusional dengan stakeholders baik dalam dan luar negeri',
                'legal_documents' => [
                    'logo_url' => '/storage/verifikasi/logo.webp',
                    'profil_pt' => '/storage/verifikasi/profil.pdf',
                    'sk_pendirian' => '/storage/verifikasi/sk_pendirian.pdf',
                    'surat_pernyataan' => '/storage/verifikasi/surat_pernyataan.pdf',
                    'struktur_organisasi' => '/storage/verifikasi/struktur_organisasi.pdf',
                ],
                'is_verified' => true,
            ]);
        } else {
            $identitas->update([
                'jml_mahasiswa' => 23000,
                'jml_dosen' => 2300,
                'jml_tendik' => 300,
                'jml_prodi' => 40,
                'jml_ukm' => 20,
                'jml_ormawa' => 40,
                'jml_fakultas' => 8,
                'visi' => 'Unggul dalam Ilmu Pengetahuan, Teknologi, dan Humaniora yang Berwawasan Kebangsaan demi Kemaslahatan Bangsa',
                'misi' => '1. Menyelenggarakan dan mengembangkan pendidikan berkarakter bela negara <br>
2. Meningkatkan budaya riset dalam pengembangan bidang IPTEK yang berdayaguna untuk kesejahteraan masyarakat <br>
3. Menyelenggarakan pengabdian kepada masyarakat  berbasis riset dan kearifan lokal <br>
4. Menyelenggarakan tata kelola yang baik dan bersih dalam rangka mencapai akuntabilitas pengelolaan anggaran <br>
5. Mengembangkan kualitas sumber daya manusia unggul dalam sikap dan tata nilai, unjuk kerja, penguasaan pengetahuan, dan manajerial <br>
6. Meningkatkan sistem pengelolaan sarana dan prasarana terpadu <br>
7. Meningkatkan kerjasama institusional dengan stakeholders baik dalam dan luar negeri',
                'legal_documents' => [
                    'logo_url' => '/storage/verifikasi/logo.webp',
                    'profil_pt' => '/storage/verifikasi/profil.pdf',
                    'sk_pendirian' => '/storage/verifikasi/sk_pendirian.pdf',
                    'surat_pernyataan' => '/storage/verifikasi/surat_pernyataan.pdf',
                    'struktur_organisasi' => '/storage/verifikasi/struktur_organisasi.pdf',
                ],
                'is_verified' => true,
            ]);
        }

        $religions = [
            'islam' => 20000,
            'kristen' => 1500,
            'katolik' => 1000,
            'hindu' => 0,
            'buddha' => 500,
            'konghucu' => 0,
        ];

        foreach ($religions as $agamaName => $jumlah) {
            Agama::updateOrCreate(
                [
                    'identitas_id' => $identitas->id,
                    'agama' => $agamaName,
                ],
                [
                    'jumlah' => $jumlah,
                ]
            );
        }

        // ==========================================
        // 7. Seed Unverified Participant User (Peserta Baru yang belum verifikasi)
        // ==========================================
        $unverifiedPesertaUser = User::where('email', 'unverified@student.unair.ac.id')->first();
        if (!$unverifiedPesertaUser) {
            $unverifiedPesertaUser = User::create([
                'email' => 'unverified@student.unair.ac.id',
                'password' => bcrypt('Ilham6769'),
                'role' => 'PESERTA',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ]);
        }

        $unverifiedInstitusi = Institusi::where('domain_email', 'student.unair.ac.id')->first();
        if (!$unverifiedInstitusi) {
            $unverifiedInstitusi = Institusi::create([
                'id' => (string) Str::uuid(),
                'nama_institusi' => 'Universitas Airlangga',
                'jenis_institusi' => 'PTN',
                'domain_email' => 'student.unair.ac.id',
                'logo_url' => 'assets/images/blank-profile-picture-973460_1280.webp',
            ]);
        } else {
            $unverifiedInstitusi->update([
                'nama_institusi' => 'Universitas Airlangga',
                'jenis_institusi' => 'PTN',
                'logo_url' => 'assets/images/blank-profile-picture-973460_1280.webp',
            ]);
        }

        $unverifiedPenugasan = Penugasan::where('user_id', $unverifiedPesertaUser->id)->where('tahun_periode', 2026)->first();
        if (!$unverifiedPenugasan) {
            Penugasan::create([
                'user_id' => $unverifiedPesertaUser->id,
                'tahun_periode' => 2026,
                'institution_id' => $unverifiedInstitusi->id,
                'nama_pic' => 'Dr. Airlangga',
                'jabatan_pic' => 'Dekan',
                'no_hp_pic' => '089876543210',
                'status' => 'UNVERIFIED',
                'reviewer_1_id' => $reviewer->id,
            ]);
        } else {
            $unverifiedPenugasan->update([
                'institution_id' => $unverifiedInstitusi->id,
                'nama_pic' => 'Dr. Airlangga',
                'jabatan_pic' => 'Dekan',
                'no_hp_pic' => '089876543210',
                'status' => 'UNVERIFIED',
                'reviewer_1_id' => $reviewer->id,
            ]);
        }
    }
}
