<?php

namespace App\Services;

use App\Models\Penugasan;
use App\Models\Pertanyaan;
use Illuminate\Support\Facades\File;

class ResumeReportService
{
    /**
     * Menghasilkan seluruh data yang dibutuhkan oleh template Blade resume laporan.
     */
    public function generateReportData(int|Penugasan $penugasan): array
    {
        if (is_numeric($penugasan)) {
            $penugasan = Penugasan::with([
                'institusi',
                'identitas',
                'jawabans.pertanyaan.kategori',
                'jawabans.jawabanOpsi'
            ])->findOrFail($penugasan);
        } else {
            $penugasan->loadMissing([
                'institusi',
                'identitas',
                'jawabans.pertanyaan.kategori',
                'jawabans.jawabanOpsi'
            ]);
        }

        $institusi = $penugasan->institusi;
        $identitas = $penugasan->identitas;

        // 1. Data Institusi & Profil
        $namaInstitusi = $institusi?->nama_institusi ?? 'UNIVERSITAS 17 AGUSTUS 1945 SURABAYA';
        $logoBase64 = $this->resolveLogoBase64($institusi?->logo_url);
        
        $jmlDosen = $identitas?->jml_dosen ?? $institusi?->jml_dosen ?? 345;
        $jmlTendik = $identitas?->jml_tendik ?? $institusi?->jml_tendik ?? 314;
        $jmlMahasiswa = $identitas?->jml_mahasiswa ?? $institusi?->jml_mahasiswa ?? 15134;

        // 2. Map Jawaban Peserta — MURNI skor dari Reviewer ((R1 + R2) / 2), TIDAK mengambil skor_sistem peserta
        $jawabanMap = [];
        if ($penugasan->jawabans) {
            foreach ($penugasan->jawabans as $jawaban) {
                $kode = $jawaban->pertanyaan?->kode_pertanyaan;
                if ($kode) {
                    $r1 = 0.0;
                    $r2 = 0.0;
                    $skorRataRata = 0.0;

                    if (is_array($jawaban->skor_validasi_reviewer)) {
                        $r1 = (isset($jawaban->skor_validasi_reviewer['r1']) && $jawaban->skor_validasi_reviewer['r1'] !== '' && is_numeric($jawaban->skor_validasi_reviewer['r1']))
                            ? min(5.0, max(0.0, (float) $jawaban->skor_validasi_reviewer['r1']))
                            : 0.0;
                        $r2 = (isset($jawaban->skor_validasi_reviewer['r2']) && $jawaban->skor_validasi_reviewer['r2'] !== '' && is_numeric($jawaban->skor_validasi_reviewer['r2']))
                            ? min(5.0, max(0.0, (float) $jawaban->skor_validasi_reviewer['r2']))
                            : 0.0;

                        // Rumus resmi: penjumlahan skor reviewer dibagi 2 -> (R1 + R2) / 2
                        $skorRataRata = round(($r1 + $r2) / 2, 2);
                    } elseif (is_numeric($jawaban->skor_validasi_reviewer)) {
                        $r1 = min(5.0, max(0.0, (float) $jawaban->skor_validasi_reviewer));
                        $skorRataRata = round($r1 / 2, 2);
                    }

                    $jawabanMap[$kode] = [
                        'skor_r1' => $r1,
                        'skor_r2' => $r2,
                        'skor_rata_rata' => $skorRataRata,
                        'jawaban' => $jawaban,
                    ];
                }
            }
        }

        // 3. Proses Variabel 1: Patriotisme Kebijakan (A.1 - A.5 / 1.1 - 1.5, bobot maks 20)
        $kebijakanData = $this->processKebijakan($jawabanMap);

        // 4. Proses Variabel 2: Patriotisme Kelembagaan (B.1 - B.20 / 2.1 - 2.20, bobot maks 30)
        $kelembagaanData = $this->processKelembagaan($jawabanMap);

        // 5. Proses Variabel 3: Patriotisme Mahasiswa (C.1 - C.15 / 3.1 - 3.15, bobot maks 50)
        $mahasiswaData = $this->processMahasiswa($jawabanMap);

        // 6. Total Skor & Predikat Bintang (Maksimal 100, murni dari penjumlahan 3 variabel hasil reviewer / 2)
        $totalSkor = min(100.0, round(
            $kebijakanData['skor_saat_ini'] + $kelembagaanData['skor_saat_ini'] + $mahasiswaData['skor_saat_ini'],
            2
        ));

        $bintang = $this->calculateBintang($totalSkor);

        // 7. Generate SVG Diagrams (Kontribusi tiap variabel terhadap total skor)
        if ($totalSkor > 0) {
            $contrib1 = (int) round(($kebijakanData['skor_saat_ini'] / $totalSkor) * 100);
            $contrib2 = (int) round(($kelembagaanData['skor_saat_ini'] / $totalSkor) * 100);
            $contrib3 = max(0, 100 - $contrib1 - $contrib2);
        } else {
            $contrib1 = 0;
            $contrib2 = 0;
            $contrib3 = 0;
        }
        $pieChartSvg = $this->renderPieChartSvg($contrib1, $contrib2, $contrib3);

        $radarKebijakanSvg = $this->renderRadarKebijakanSvg($kebijakanData['radar_scores']);
        $radarKelembagaanSvg = $this->renderRadarKelembagaanSvg($kelembagaanData['radar_scores']);
        $radarMahasiswaSvg = $this->renderRadarMahasiswaSvg($mahasiswaData['radar_scores']);

        // 8. Catatan Penilaian / Executive Summary
        $rawCatatan = trim($penugasan->catatan_penilaian ?? '');
        $resolvedCatatan = '';
        if ($rawCatatan !== '') {
            $decodedCatatan = json_decode($rawCatatan, true);
            if (is_array($decodedCatatan) && (array_key_exists('r1', $decodedCatatan) || array_key_exists('r2', $decodedCatatan))) {
                $resolvedCatatan = trim((string) ($decodedCatatan['r1'] ?? '')) ?: trim((string) ($decodedCatatan['r2'] ?? ''));
            } else {
                $resolvedCatatan = $rawCatatan;
            }
        }
        $catatanPenilaian = $resolvedCatatan !== '' ? $resolvedCatatan : null;

        return [
            'penugasan' => $penugasan,
            'nama_institusi' => $namaInstitusi,
            'logo_base64' => $logoBase64,
            'jml_dosen' => $jmlDosen,
            'jml_tendik' => $jmlTendik,
            'jml_mahasiswa' => $jmlMahasiswa,
            'total_skor' => $totalSkor,
            'bintang' => $bintang,
            'variabel_1' => $kebijakanData,
            'variabel_2' => $kelembagaanData,
            'variabel_3' => $mahasiswaData,
            'pie_chart_svg' => $pieChartSvg,
            'radar_kebijakan_svg' => $radarKebijakanSvg,
            'radar_kelembagaan_svg' => $radarKelembagaanSvg,
            'radar_mahasiswa_svg' => $radarMahasiswaSvg,
            'catatan_penilaian' => $catatanPenilaian,
        ];
    }

    /**
     * Konversi logo ke Base64 URI agar kompatibel 100% untuk PDF maupun web.
     */
    private function resolveLogoBase64(?string $logoUrl): string
    {
        if ($logoUrl && str_starts_with($logoUrl, 'data:image')) {
            return $logoUrl;
        }

        // Cek file lokal
        $possiblePaths = [];
        if ($logoUrl) {
            $cleanPath = ltrim($logoUrl, '/\\');
            $possiblePaths[] = public_path($cleanPath);
            $possiblePaths[] = storage_path('app/public/' . (str_starts_with($cleanPath, 'storage/') ? substr($cleanPath, 8) : $cleanPath));
            $possiblePaths[] = base_path($cleanPath);
        }

        // Fallback logo untag default yang ada di repo
        $possiblePaths[] = public_path('assets/berkas verif/Logo-Untag (1).png');

        foreach ($possiblePaths as $p) {
            if ($p && File::exists($p)) {
                $ext = pathinfo($p, PATHINFO_EXTENSION);
                $mime = match (strtolower($ext)) {
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'svg' => 'image/svg+xml',
                    'webp' => 'image/webp',
                    default => 'image/png'
                };
                $data = base64_encode(File::get($p));
                return "data:{$mime};base64,{$data}";
            }
        }

        return '';
    }

    /**
     * Logika Kategori A: Patriotisme Kebijakan (Bobot 20 poin).
     */
    private function processKebijakan(array $jawabanMap): array
    {
        $definisi = [
            'A.1' => ['no' => '1.1.', 'judul' => 'Kebijakan Implementasi Nilai-Nilai Bela Negara dalam Kegiatan Tridharma', 'default' => 4],
            'A.2' => ['no' => '1.2.', 'judul' => 'Kebijakan pencegahan dan penanganan kekerasan', 'default' => 4],
            'A.3' => ['no' => '1.3.', 'judul' => 'Kebijakan Pencegahan dan Pemberantasan Penyalahgunaan dan Peredaran Gelap Narkotika dan Prekursor Narkotika', 'default' => 2],
            'A.4' => ['no' => '1.4.', 'judul' => 'Kebijakan mitigasi bencana serta satgas yang aktif dalam penanggulangan dan kesiapsiagaan bencana', 'default' => 4],
            'A.5' => ['no' => '1.5.', 'judul' => 'Kebijakan bagi sivitas akademika untuk bangga menggunakan produk lokal dalam pelaksanaan pembelajaran (sebagai contoh penggunaan batik, pakaian adat)', 'default' => 4],
        ];

        $indikators = [];
        $rawScores = [];
        $radarScores = [];
        $sumRaw = 0.0;
        $jumlahIndikator = count($definisi); // 5 indikator
        $skorMaksimal = 20;
        $bobotPerIndikator = $skorMaksimal / $jumlahIndikator; // 4.00 poin maks per indikator

        foreach ($definisi as $kode => $meta) {
            // Murni dari rata-rata reviewer (R1 + R2) / 2, default 0 jika belum dinilai
            $avgRaw = isset($jawabanMap[$kode]) ? min(5.0, max(0.0, (float) $jawabanMap[$kode]['skor_rata_rata'])) : 0.0;
            $skor = round(($avgRaw / 5.0) * $bobotPerIndikator, 2);

            $rawScores[] = $skor;
            $radarScores[] = $avgRaw;
            $sumRaw += $avgRaw;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => number_format($skor, 2, ',', ''),
            ];
        }

        // Total capaian tertimbang: (sumRaw / (5 * 5)) * 20, dijamin <= 20
        $totalSkor = min((float) $skorMaksimal, round(($sumRaw / ($jumlahIndikator * 5.0)) * $skorMaksimal, 2));
        $persentase = $skorMaksimal > 0 ? min(100, (int) round(($totalSkor / $skorMaksimal) * 100)) : 0;

        return [
            'nama' => 'VARIABEL 1: PATRIOTISME KEBIJAKAN',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators' => $indikators,
            'raw_scores' => $rawScores,
            'radar_scores' => $radarScores,
        ];
    }

    /**
     * Logika Kategori B: Patriotisme Kelembagaan (Bobot 30 poin, 20 indikator, max 1.50 per indikator).
     */
    private function processKelembagaan(array $jawabanMap): array
    {
        $definisi = [
            'B.1'  => ['no' => '2.1.',  'judul' => 'Unit kerja yang berfokus pada pengembangan karakter bela negara', 'default' => 1.50],
            'B.2'  => ['no' => '2.2.',  'judul' => 'Implementasi penyelenggaraan MKWK (Mata Kuliah Wajib Kurikulum : Pancasila, Agama, Bahasa Indonesia, Kewarganegaraan)', 'default' => 1.50],
            'B.3'  => ['no' => '2.3.',  'judul' => 'Jumlah mata kuliah wajib institusi berkarakter bela negara', 'default' => 1.50],
            'B.4'  => ['no' => '2.4.',  'judul' => 'Jumlah kelompok riset berkarakter bela negara', 'default' => 0.02],
            'B.5'  => ['no' => '2.5.',  'judul' => 'Skema Kuliah Kerja Nyata (KKN)', 'default' => 1.50],
            'B.6'  => ['no' => '2.6.',  'judul' => 'Jumlah luaran pengabdian masyarakat berupa karya inovatif, seperti pengembangan teknologi atau prototipe, desain produk', 'default' => 0.45],
            'B.7'  => ['no' => '2.7.',  'judul' => 'Jumlah kolaborasi dengan UMKM atau pelaku usaha lokal untuk mendukung program tridharma', 'default' => 1.50],
            'B.8'  => ['no' => '2.8.',  'judul' => 'Jumlah dosen yang mendapatkan pelatihan bela negara', 'default' => 0.11],
            'B.9'  => ['no' => '2.9.',  'judul' => 'Pusat konseling bagi sivitas akademik', 'default' => 1.50],
            'B.10' => ['no' => '2.10.', 'judul' => 'Jumlah pegawai institusi sebagai anggota komponen cadangan (KOMCAD)', 'default' => 0.00],
            'B.11' => ['no' => '2.11.', 'judul' => 'Penyelenggaraan Upacara Hari Besar Nasional.', 'default' => 1.50],
            'B.12' => ['no' => '2.12.', 'judul' => 'Jumlah kegiatan sosialisasi/seminar/workshop tentang langkah-langkah menjaga kebersihan, keamanan dan pengelolaan lingkungan, serta anti-radikalisme', 'default' => 0.19],
            'B.13' => ['no' => '2.13.', 'judul' => 'Penyelenggaraan kompetisi lomba bertema nilai-nilai bela negara', 'default' => 0.09],
            'B.14' => ['no' => '2.14.', 'judul' => 'Jumlah penghargaan atau insentif atas prestasi sivitas akademika dari perguruan tinggi', 'default' => 0.20],
            'B.15' => ['no' => '2.15.', 'judul' => 'Fasilitas akomodasi yang layak untuk peserta didik penyandang disabilitas di lingkungan pendidikan tinggi', 'default' => 1.50],
            'B.16' => ['no' => '2.16.', 'judul' => 'Jumlah ragam dan variasi media kampanye nilai-nilai cinta tanah air yang disebarluaskan di area kampus', 'default' => 0.60],
            'B.17' => ['no' => '2.17.', 'judul' => 'Fasilitas yang disediakan universitas seperti ruang atau galeri yang digunakan untuk menampilkan karya seni atau hasil kreativitas mahasiswa dan kegiatan kebudayaan', 'default' => 0.32],
            'B.18' => ['no' => '2.18.', 'judul' => 'Ruang khusus (sekretariat) untuk organisasi mahasiswa', 'default' => 1.50],
            'B.19' => ['no' => '2.19.', 'judul' => 'Monumen tentang pahlawan nasional di area universitas', 'default' => 1.50],
            'B.20' => ['no' => '2.20.', 'judul' => 'Persentase jumlah UKM Keagamaan (dengan 6 agama)', 'default' => 1.20],
        ];

        $indikators = [];
        $rawScores = [];
        $radarScores = [];
        $sumRaw = 0.0;
        $jumlahIndikator = count($definisi); // 20 indikator
        $skorMaksimal = 30;
        $bobotPerIndikator = $skorMaksimal / $jumlahIndikator; // 1.50 poin maks per indikator

        foreach ($definisi as $kode => $meta) {
            // Murni dari rata-rata reviewer (R1 + R2) / 2, default 0 jika belum dinilai
            $avgRaw = isset($jawabanMap[$kode]) ? min(5.0, max(0.0, (float) $jawabanMap[$kode]['skor_rata_rata'])) : 0.0;
            $skor = round(($avgRaw / 5.0) * $bobotPerIndikator, 2);

            $rawScores[] = $skor;
            $radarScores[] = $avgRaw;
            $sumRaw += $avgRaw;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => number_format($skor, 2, ',', ''),
            ];
        }

        // Total capaian tertimbang: (sumRaw / (20 * 5)) * 30, dijamin <= 30
        $totalSkor = min((float) $skorMaksimal, round(($sumRaw / ($jumlahIndikator * 5.0)) * $skorMaksimal, 2));
        $persentase = $skorMaksimal > 0 ? min(100, (int) round(($totalSkor / $skorMaksimal) * 100)) : 0;

        return [
            'nama' => 'VARIABEL 2: PATRIOTISME KELEMBAGAAN',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators_left' => array_slice($indikators, 0, 12),
            'indikators_right' => array_slice($indikators, 12, 8),
            'raw_scores' => $rawScores,
            'radar_scores' => $radarScores,
        ];
    }

    /**
     * Logika Kategori C: Patriotisme Mahasiswa (Bobot 50 poin, 15 indikator, max 3.33 per indikator).
     */
    private function processMahasiswa(array $jawabanMap): array
    {
        $definisi = [
            'C.1'  => ['no' => '3.1.',  'judul' => 'Mahasiswa aktif sebagai anggota komponen cadangan (KOMCAD)', 'default' => 1.42],
            'C.2'  => ['no' => '3.2.',  'judul' => 'Jumlah mahasiswa yang terlibat aktif dalam pencegahan kekerasan di perguruan tinggi', 'default' => 0.76],
            'C.3'  => ['no' => '3.3.',  'judul' => 'Jumlah mahasiswa yang terlibat pada upaya mitigasi bencana dalam penanggulangan dan kesiapsiagaan bencana', 'default' => 0.20],
            'C.4'  => ['no' => '3.4.',  'judul' => 'Mahasiswa yang terlibat pada Kuliah Kerja Nyata (KKN) di daerah 3T/ bencana/ konflik', 'default' => 3.33],
            'C.5'  => ['no' => '3.5.',  'judul' => 'Jumlah kegiatan pengabdian masyarakat di luar Kuliah Kerja Nyata (KKN) oleh mahasiswa', 'default' => 2.53],
            'C.6'  => ['no' => '3.6.',  'judul' => 'Jumlah Unit Kegiatan Mahasiswa (UKM)', 'default' => 3.33],
            'C.7'  => ['no' => '3.7.',  'judul' => 'Persentase mahasiswa yang mengikuti UKM terhadap jumlah mahasiswa', 'default' => 0.42],
            'C.8'  => ['no' => '3.8.',  'judul' => 'Jumlah mahasiswa yang mengikuti UKM resimen mahasiswa (MENWA)', 'default' => 1.86],
            'C.9'  => ['no' => '3.9.',  'judul' => 'Jumlah mahasiswa yang mengikuti organisasi (sesuai bidang keilmuan masing-masing) di luar kampus', 'default' => 0.48],
            'C.10' => ['no' => '3.10',  'judul' => 'Jumlah prestasi mahasiswa dalam kompetisi atau perlombaan', 'default' => 3.33],
            'C.11' => ['no' => '3.11.', 'judul' => 'Jumlah mahasiswa yang mendirikan startup', 'default' => 2.41],
            'C.12' => ['no' => '3.12.', 'judul' => 'Kegiatan mahasiswa yang berkaitan dengan seni budaya', 'default' => 0.88],
            'C.13' => ['no' => '3.13.', 'judul' => 'Jumlah karya (seni, desain, dan tulis) mahasiswa yang menyuarakan nilai-nilai kebangsaan dan toleransi di media massa', 'default' => 0.01],
            'C.14' => ['no' => '3.14.', 'judul' => 'Jumlah kegiatan mahasiswa dalam menjaga lingkungan di dalam kampus dan terprogram', 'default' => 0.03],
            'C.15' => ['no' => '3.15.', 'judul' => 'Keterlibatan mahasiswa dalam Upacara Hari Besar Nasional.', 'default' => 3.33],
        ];

        $indikators = [];
        $rawScores = [];
        $radarScores = [];
        $sumRaw = 0.0;
        $jumlahIndikator = count($definisi); // 15 indikator
        $skorMaksimal = 50;
        $bobotPerIndikator = $skorMaksimal / $jumlahIndikator; // 3.3333... poin maks per indikator

        foreach ($definisi as $kode => $meta) {
            // Murni dari rata-rata reviewer (R1 + R2) / 2, default 0 jika belum dinilai
            $avgRaw = isset($jawabanMap[$kode]) ? min(5.0, max(0.0, (float) $jawabanMap[$kode]['skor_rata_rata'])) : 0.0;
            $skor = round(($avgRaw / 5.0) * $bobotPerIndikator, 2);

            $rawScores[] = $skor;
            $radarScores[] = $avgRaw;
            $sumRaw += $avgRaw;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => number_format($skor, 2, ',', ''),
            ];
        }

        // Total capaian tertimbang: (sumRaw / (15 * 5)) * 50, dijamin <= 50
        $totalSkor = min((float) $skorMaksimal, round(($sumRaw / ($jumlahIndikator * 5.0)) * $skorMaksimal, 2));
        $persentase = $skorMaksimal > 0 ? min(100, (int) round(($totalSkor / $skorMaksimal) * 100)) : 0;

        return [
            'nama' => 'VARIABEL 3: PATRIOTISME MAHASISWA',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators' => $indikators,
            'raw_scores' => $rawScores,
            'radar_scores' => $radarScores,
        ];
    }

    /**
     * Skema rubrik resmi sebagai single source of truth (dipakai PDF Resume & Export Excel).
     */
    public static function getRubrikSchema(): array
    {
        return [
            [
                'key' => 'variabel_1',
                'prefix' => 'A.',
                'nama' => 'VARIABEL 1: PATRIOTISME KEBIJAKAN',
                'judul_banner' => 'PATRIOTISME KEBIJAKAN',
                'judul_pendek' => 'Patriotisme Kebijakan',
                'deskripsi' => 'Menilai keberadaan dan implementasi kebijakan dan komitmen perguruan tinggi yang sejalan dengan nilai-nilai bela negara.',
                'skor_maksimal' => 20,
                'warna_banner' => '2F6D28',
                'warna_header' => 'A7CCA4',
                'warna_accent' => '3B822D',
                'warna_bar_bg' => 'A8DCA5',
                'indikators' => [
                    ['kode' => 'A.1', 'no' => '1.1.', 'indikator' => 'Kebijakan Implementasi Nilai-Nilai Bela Negara dalam Kegiatan Tridharma'],
                    ['kode' => 'A.2', 'no' => '1.2.', 'indikator' => 'Kebijakan pencegahan dan penanganan kekerasan'],
                    ['kode' => 'A.3', 'no' => '1.3.', 'indikator' => 'Kebijakan Pencegahan dan Pemberantasan Penyalahgunaan dan Peredaran Gelap Narkotika dan Prekursor Narkotika'],
                    ['kode' => 'A.4', 'no' => '1.4.', 'indikator' => 'Kebijakan mitigasi bencana serta satgas yang aktif dalam penanggulangan dan kesiapsiagaan bencana'],
                    ['kode' => 'A.5', 'no' => '1.5.', 'indikator' => 'Kebijakan bagi sivitas akademika untuk bangga menggunakan produk lokal dalam pelaksanaan pembelajaran (sebagai contoh penggunaan batik, pakaian adat)'],
                ],
            ],
            [
                'key' => 'variabel_2',
                'prefix' => 'B.',
                'nama' => 'VARIABEL 2: PATRIOTISME KELEMBAGAAN',
                'judul_banner' => 'PATRIOTISME KELEMBAGAAN',
                'judul_pendek' => 'Patriotisme Kelembagaan',
                'deskripsi' => 'Menilai kinerja perguruan tinggi dalam penyelenggaraan Tridharma Perguruan Tinggi dan penyediaan infrastruktur.',
                'skor_maksimal' => 30,
                'warna_banner' => 'DF692A',
                'warna_header' => 'F5BBA5',
                'warna_accent' => 'CC5D39',
                'warna_bar_bg' => 'F7BBB1',
                'indikators' => [
                    ['kode' => 'B.1',  'no' => '2.1.',  'indikator' => 'Unit kerja yang berfokus pada pengembangan karakter bela negara'],
                    ['kode' => 'B.2',  'no' => '2.2.',  'indikator' => 'Implementasi penyelenggaraan MKWK (Mata Kuliah Wajib Kurikulum : Pancasila, Agama, Bahasa Indonesia, Kewarganegaraan)'],
                    ['kode' => 'B.3',  'no' => '2.3.',  'indikator' => 'Jumlah mata kuliah wajib institusi berkarakter bela negara'],
                    ['kode' => 'B.4',  'no' => '2.4.',  'indikator' => 'Jumlah kelompok riset berkarakter bela negara'],
                    ['kode' => 'B.5',  'no' => '2.5.',  'indikator' => 'Skema Kuliah Kerja Nyata (KKN)'],
                    ['kode' => 'B.6',  'no' => '2.6.',  'indikator' => 'Jumlah luaran pengabdian masyarakat berupa karya inovatif, seperti pengembangan teknologi atau prototipe, desain produk'],
                    ['kode' => 'B.7',  'no' => '2.7.',  'indikator' => 'Jumlah kolaborasi dengan UMKM atau pelaku usaha lokal untuk mendukung program tridharma'],
                    ['kode' => 'B.8',  'no' => '2.8.',  'indikator' => 'Jumlah dosen yang mendapatkan pelatihan bela negara'],
                    ['kode' => 'B.9',  'no' => '2.9.',  'indikator' => 'Pusat konseling bagi sivitas akademik'],
                    ['kode' => 'B.10', 'no' => '2.10.', 'indikator' => 'Jumlah pegawai institusi sebagai anggota komponen cadangan (KOMCAD)'],
                    ['kode' => 'B.11', 'no' => '2.11.', 'indikator' => 'Penyelenggaraan Upacara Hari Besar Nasional.'],
                    ['kode' => 'B.12', 'no' => '2.12.', 'indikator' => 'Jumlah kegiatan sosialisasi/seminar/workshop tentang langkah-langkah menjaga kebersihan, keamanan dan pengelolaan lingkungan, serta anti-radikalisme'],
                    ['kode' => 'B.13', 'no' => '2.13.', 'indikator' => 'Penyelenggaraan kompetisi lomba bertema nilai-nilai bela negara'],
                    ['kode' => 'B.14', 'no' => '2.14.', 'indikator' => 'Jumlah penghargaan atau insentif atas prestasi sivitas akademika dari perguruan tinggi'],
                    ['kode' => 'B.15', 'no' => '2.15.', 'indikator' => 'Fasilitas akomodasi yang layak untuk peserta didik penyandang disabilitas di lingkungan pendidikan tinggi'],
                    ['kode' => 'B.16', 'no' => '2.16.', 'indikator' => 'Jumlah ragam dan variasi media kampanye nilai-nilai cinta tanah air yang disebarluaskan di area kampus'],
                    ['kode' => 'B.17', 'no' => '2.17.', 'indikator' => 'Fasilitas yang disediakan universitas seperti ruang atau galeri yang digunakan untuk menampilkan karya seni atau hasil kreativitas mahasiswa dan kegiatan kebudayaan'],
                    ['kode' => 'B.18', 'no' => '2.18.', 'indikator' => 'Ruang khusus (sekretariat) untuk organisasi mahasiswa'],
                    ['kode' => 'B.19', 'no' => '2.19.', 'indikator' => 'Monumen tentang pahlawan nasional di area universitas'],
                    ['kode' => 'B.20', 'no' => '2.20.', 'indikator' => 'Persentase jumlah UKM Keagamaan (dengan 6 agama)'],
                ],
            ],
            [
                'key' => 'variabel_3',
                'prefix' => 'C.',
                'nama' => 'VARIABEL 3: PATRIOTISME MAHASISWA',
                'judul_banner' => 'PATRIOTISME MAHASISWA',
                'judul_pendek' => 'Patriotisme Mahasiswa',
                'deskripsi' => 'Menilai tingkat internalisasi, ekspresi perilaku, dan kontribusi nyata mahasiswa terhadap nilai-nilai bela negara dalam menjaga kedaulatan negara, keutuhan wilayah, serta keselamatan bangsa.',
                'skor_maksimal' => 50,
                'warna_banner' => 'F5A623',
                'warna_header' => 'FEE199',
                'warna_accent' => 'EAB318',
                'warna_bar_bg' => 'FCE899',
                'indikators' => [
                    ['kode' => 'C.1',  'no' => '3.1.',  'indikator' => 'Mahasiswa aktif sebagai anggota komponen cadangan (KOMCAD)'],
                    ['kode' => 'C.2',  'no' => '3.2.',  'indikator' => 'Jumlah mahasiswa yang terlibat aktif dalam pencegahan kekerasan di perguruan tinggi'],
                    ['kode' => 'C.3',  'no' => '3.3.',  'indikator' => 'Jumlah mahasiswa yang terlibat pada upaya mitigasi bencana dalam penanggulangan dan kesiapsiagaan bencana'],
                    ['kode' => 'C.4',  'no' => '3.4.',  'indikator' => 'Mahasiswa yang terlibat pada Kuliah Kerja Nyata (KKN) di daerah 3T/ bencana/ konflik'],
                    ['kode' => 'C.5',  'no' => '3.5.',  'indikator' => 'Jumlah kegiatan pengabdian masyarakat di luar Kuliah Kerja Nyata (KKN) oleh mahasiswa'],
                    ['kode' => 'C.6',  'no' => '3.6.',  'indikator' => 'Jumlah Unit Kegiatan Mahasiswa (UKM)'],
                    ['kode' => 'C.7',  'no' => '3.7.',  'indikator' => 'Persentase mahasiswa yang mengikuti UKM terhadap jumlah mahasiswa'],
                    ['kode' => 'C.8',  'no' => '3.8.',  'indikator' => 'Jumlah mahasiswa yang mengikuti UKM resimen mahasiswa (MENWA)'],
                    ['kode' => 'C.9',  'no' => '3.9.',  'indikator' => 'Jumlah mahasiswa yang mengikuti organisasi (sesuai bidang keilmuan masing-masing) di luar kampus'],
                    ['kode' => 'C.10', 'no' => '3.10.', 'indikator' => 'Jumlah prestasi mahasiswa dalam kompetisi atau perlombaan'],
                    ['kode' => 'C.11', 'no' => '3.11.', 'indikator' => 'Jumlah mahasiswa yang mendirikan startup'],
                    ['kode' => 'C.12', 'no' => '3.12.', 'indikator' => 'Kegiatan mahasiswa yang berkaitan dengan seni budaya'],
                    ['kode' => 'C.13', 'no' => '3.13.', 'indikator' => 'Jumlah karya (seni, desain, dan tulis) mahasiswa yang menyuarakan nilai-nilai kebangsaan dan toleransi di media massa'],
                    ['kode' => 'C.14', 'no' => '3.14.', 'indikator' => 'Jumlah kegiatan mahasiswa dalam menjaga lingkungan di dalam kampus dan terprogram'],
                    ['kode' => 'C.15', 'no' => '3.15.', 'indikator' => 'Keterlibatan mahasiswa dalam Upacara Hari Besar Nasional.'],
                ],
            ],
        ];
    }

    /**
     * Resolusi bintang predikat untuk rekap/export (0 jika unverified atau skor <= 0).
     */
    public static function resolveBintang(?float $totalSkor, ?string $status = null): int
    {
        if ($status !== null && strtoupper($status) === 'UNVERIFIED') {
            return 0;
        }
        if ($totalSkor === null || $totalSkor <= 0) {
            return 0;
        }
        if ($totalSkor >= 85) return 5;
        if ($totalSkor >= 70) return 4;
        if ($totalSkor >= 50) return 3;
        if ($totalSkor >= 30) return 2;
        return 1;
    }

    /**
     * Kalkulasi jumlah bintang predikat dari total skor akhir.
     */
    private function calculateBintang(float $totalSkor): int
    {
        if ($totalSkor >= 85) return 5;
        if ($totalSkor >= 70) return 4;
        if ($totalSkor >= 50) return 3;
        if ($totalSkor >= 30) return 2;
        return 1;
    }

    /**
     * Render SVG Pie Chart Kontribusi Variabel secara dinamis.
     */
    public function renderPieChartSvg(int $pct1 = 30, int $pct2 = 30, int $pct3 = 40): string
    {
        $cx = 110; $cy = 110; $r = 96;
        $pTopX = $cx; $pTopY = $cy - $r;

        $rad18 = deg2rad(18);
        $p18X = round($cx + $r * cos($rad18), 1);
        $p18Y = round($cy + $r * sin($rad18), 1);

        $rad162 = deg2rad(162);
        $p162X = round($cx + $r * cos($rad162), 1);
        $p162Y = round($cy + $r * sin($rad162), 1);

        return <<<SVG
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
            <svg width="220" height="220" viewBox="0 0 220 220">
                <circle cx="{$cx}" cy="{$cy}" r="{$r}" fill="#ffffff" stroke="#2e7924" stroke-width="2.6" />
                <line x1="{$cx}" y1="{$cy}" x2="{$pTopX}" y2="{$pTopY}" stroke="#2e7924" stroke-width="2.2" />
                <line x1="{$cx}" y1="{$cy}" x2="{$p18X}" y2="{$p18Y}" stroke="#2e7924" stroke-width="2.2" />
                <line x1="{$cx}" y1="{$cy}" x2="{$p162X}" y2="{$p162Y}" stroke="#2e7924" stroke-width="2.2" />
                
                <text x="156" y="66" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="10.5" font-weight="bold" fill="#2d6d25">
                    <tspan x="156" dy="0">Patriotisme</tspan>
                    <tspan x="156" dy="14">Kebijakan</tspan>
                    <tspan x="156" dy="14">{$pct1}%</tspan>
                </text>
                
                <text x="64" y="66" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="10.5" font-weight="bold" fill="#2d6d25">
                    <tspan x="64" dy="0">Patriotisme</tspan>
                    <tspan x="64" dy="14">Kelembagaan</tspan>
                    <tspan x="64" dy="14">{$pct2}%</tspan>
                </text>
                
                <text x="110" y="138" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="10.5" font-weight="bold" fill="#2d6d25">
                    <tspan x="110" dy="0">Patriotisme</tspan>
                    <tspan x="110" dy="14">Mahasiswa</tspan>
                    <tspan x="110" dy="14">{$pct3}%</tspan>
                </text>
            </svg>
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: bold; color: #1a1a1a; text-align: center; margin-top: 6px; line-height: 1.4;">
                Kontribusi skor tiap variabel pada<br>total skor
            </div>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Kebijakan (5 sumbu, 1 titik pusat = 0, 5 garis = 1..5).
     */
    public function renderRadarKebijakanSvg(array $vals): string
    {
        $cx = 155; $cy = 145; $r = 95;
        $num = 5;
        $maxVal = 5;
        $labels = ['1.1.', '1.2.', '1.3.', '1.4.', '1.5.'];
        $levels = [1, 2, 3, 4, 5];
        $angleStep = (2 * M_PI) / $num;
        $startRad = -M_PI / 2;

        // 5 garis konsentris (1 = paling dekat titik pusat, ..., 5 = terluar)
        $gridSvg = '';
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $pts = [];
            for ($i = 0; $i < $num; $i++) {
                $a = $startRad + $i * $angleStep;
                $pts[] = round($cx + $lvlR * cos($a), 1) . ',' . round($cy + $lvlR * sin($a), 1);
            }
            $gridSvg .= '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="#cad8c8" stroke-width="1.2" />' . "\n";
        }

        // 1 titik pusat (menandakan skor 0)
        $centerDotSvg = "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"2.2\" fill=\"#7a8f77\" />\n";

        $spokesSvg = '';
        $labelsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $a = $startRad + $i * $angleStep;
            $sx = round($cx + $r * cos($a), 1);
            $sy = round($cy + $r * sin($a), 1);
            $spokesSvg .= "<line x1=\"{$cx}\" y1=\"{$cy}\" x2=\"{$sx}\" y2=\"{$sy}\" stroke=\"#cad8c8\" stroke-width=\"1\" />\n";

            $lx = round($cx + ($r + 20) * cos($a), 1);
            $ly = round($cy + ($r + 20) * sin($a) + ($i === 0 ? -4 : ($i === 2 || $i === 3 ? 14 : 5)), 1);
            $anchor = ($i === 0) ? 'middle' : (cos($a) > 0.1 ? 'start' : (cos($a) < -0.1 ? 'end' : 'middle'));
            $labelsSvg .= "<text x=\"{$lx}\" y=\"{$ly}\" text-anchor=\"{$anchor}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"13\" font-weight=\"bold\" fill=\"#111\">{$labels[$i]}</text>\n";
        }

        $levelNumbersSvg = "<text x=\"" . ($cx - 14) . "\" y=\"" . ($cy + 4) . "\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"11\" font-weight=\"bold\" fill=\"#555\">0</text>\n";
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $y = round($cy - $lvlR + 4, 1);
            $levelNumbersSvg .= "<text x=\"" . ($cx - 14) . "\" y=\"{$y}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"11\" font-weight=\"bold\" fill=\"#555\">{$lvl}</text>\n";
        }

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = min($maxVal, max(0, (float) ($vals[$i] ?? 0)));
            $a = $startRad + $i * $angleStep;
            $dist = ($v / $maxVal) * $r;
            $px = round($cx + $dist * cos($a), 1);
            $py = round($cy + $dist * sin($a), 1);
            $polyPts[] = "{$px},{$py}";
            $dotsSvg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"4.2\" fill=\"#2d6d25\" />\n";
        }
        $polygonPoints = implode(' ', $polyPts);

        return <<<SVG
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%;">
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; color: #1a1a1a; margin-bottom: 8px; text-align: center;">
                Patriotisme Kebijakan
            </div>
            <svg width="320" height="300" viewBox="0 0 320 300">
                {$gridSvg}
                {$spokesSvg}
                {$centerDotSvg}
                {$levelNumbersSvg}
                <polygon points="{$polygonPoints}" fill="rgba(45, 109, 37, 0.15)" stroke="#2d6d25" stroke-width="2.6" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Kelembagaan (20 sumbu, 1 titik pusat = 0, 5 garis = 1..5).
     */
    public function renderRadarKelembagaanSvg(array $vals): string
    {
        $cx = 150; $cy = 142; $r = 88;
        $num = 20;
        $maxVal = 5;
        $labels = [
            '2.1.', '2.2.', '2.3.', '2.4.', '2.5.', '2.6.', '2.7.', '2.8.', '2.9.', '2.10.',
            '2.11.', '2.12.', '2.13.', '2.14.', '2.15.', '2.16.', '2.17.', '2.18.', '2.19.', '2.20.'
        ];
        $levels = [1, 2, 3, 4, 5];
        $angleStep = (2 * M_PI) / $num;
        $startRad = -M_PI / 2;

        // 5 garis konsentris (1 = paling dekat titik pusat, ..., 5 = terluar)
        $gridSvg = '';
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $pts = [];
            for ($i = 0; $i < $num; $i++) {
                $a = $startRad + $i * $angleStep;
                $pts[] = round($cx + $lvlR * cos($a), 1) . ',' . round($cy + $lvlR * sin($a), 1);
            }
            $gridSvg .= '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="#e0cfc7" stroke-width="1" />' . "\n";
        }

        // 1 titik pusat (menandakan skor 0)
        $centerDotSvg = "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"2.2\" fill=\"#a88c7f\" />\n";

        $spokesSvg = '';
        $labelsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $a = $startRad + $i * $angleStep;
            $sx = round($cx + $r * cos($a), 1);
            $sy = round($cy + $r * sin($a), 1);
            $spokesSvg .= "<line x1=\"{$cx}\" y1=\"{$cy}\" x2=\"{$sx}\" y2=\"{$sy}\" stroke=\"#e0cfc7\" stroke-width=\"0.8\" />\n";

            $lx = round($cx + ($r + 16) * cos($a), 1);
            $ly = round($cy + ($r + 16) * sin($a) + 4, 1);
            $anchor = abs(cos($a)) < 0.2 ? 'middle' : (cos($a) > 0 ? 'start' : 'end');
            $labelsSvg .= "<text x=\"{$lx}\" y=\"{$ly}\" text-anchor=\"{$anchor}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"10.5\" font-weight=\"bold\" fill=\"#111\">{$labels[$i]}</text>\n";
        }

        // Angka skala 0 (pusat) hingga 5 (garis terluar)
        $levelNumbersSvg = "<text x=\"" . ($cx - 11) . "\" y=\"" . ($cy + 3.5) . "\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"9.5\" font-weight=\"bold\" fill=\"#555\">0</text>\n";
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $y = round($cy - $lvlR + 3.5, 1);
            $levelNumbersSvg .= "<text x=\"" . ($cx - 11) . "\" y=\"{$y}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"9.5\" font-weight=\"bold\" fill=\"#555\">{$lvl}</text>\n";
        }

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = min($maxVal, max(0, (float) ($vals[$i] ?? 0)));
            $a = $startRad + $i * $angleStep;
            $dist = ($v / $maxVal) * $r;
            $px = round($cx + $dist * cos($a), 1);
            $py = round($cy + $dist * sin($a), 1);
            $polyPts[] = "{$px},{$py}";
            $dotsSvg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"3.2\" fill=\"#df692a\" />\n";
        }
        $polygonPoints = implode(' ', $polyPts);

        return <<<SVG
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; margin-top: 10px;">
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: bold; color: #1a1a1a; margin-bottom: 5px; text-align: center;">
                Patriotisme Kelembagaan
            </div>
            <svg width="300" height="285" viewBox="0 0 300 285">
                {$gridSvg}
                {$spokesSvg}
                {$centerDotSvg}
                {$levelNumbersSvg}
                <polygon points="{$polygonPoints}" fill="rgba(223, 105, 42, 0.15)" stroke="#df692a" stroke-width="2.3" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Mahasiswa (15 sumbu, 1 titik pusat = 0, 5 garis = 1..5).
     */
    public function renderRadarMahasiswaSvg(array $vals): string
    {
        $cx = 150; $cy = 145; $r = 92;
        $num = 15;
        $maxVal = 5;
        $labels = [
            '3.1.', '3.2.', '3.3.', '3.4.', '3.5.', '3.6.', '3.7.', '3.8.', '3.9.', '3.10',
            '3.11.', '3.12.', '3.13.', '3.14.', '3.15.'
        ];
        $levels = [1, 2, 3, 4, 5];
        $angleStep = (2 * M_PI) / $num;
        $startRad = -M_PI / 2;

        // 5 garis konsentris (1 = paling dekat titik pusat, ..., 5 = terluar)
        $gridSvg = '';
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $pts = [];
            for ($i = 0; $i < $num; $i++) {
                $a = $startRad + $i * $angleStep;
                $pts[] = round($cx + $lvlR * cos($a), 1) . ',' . round($cy + $lvlR * sin($a), 1);
            }
            $gridSvg .= '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="#edd9b8" stroke-width="1" />' . "\n";
        }

        // 1 titik pusat (menandakan skor 0)
        $centerDotSvg = "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"2.2\" fill=\"#b89b6a\" />\n";

        $spokesSvg = '';
        $labelsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $a = $startRad + $i * $angleStep;
            $sx = round($cx + $r * cos($a), 1);
            $sy = round($cy + $r * sin($a), 1);
            $spokesSvg .= "<line x1=\"{$cx}\" y1=\"{$cy}\" x2=\"{$sx}\" y2=\"{$sy}\" stroke=\"#edd9b8\" stroke-width=\"0.8\" />\n";

            $lx = round($cx + ($r + 17) * cos($a), 1);
            $ly = round($cy + ($r + 17) * sin($a) + 4.5, 1);
            $anchor = abs(cos($a)) < 0.2 ? 'middle' : (cos($a) > 0 ? 'start' : 'end');
            $labelsSvg .= "<text x=\"{$lx}\" y=\"{$ly}\" text-anchor=\"{$anchor}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"11.5\" font-weight=\"bold\" fill=\"#111\">{$labels[$i]}</text>\n";
        }

        // Angka skala 0 (pusat) hingga 5 (garis terluar)
        $levelNumbersSvg = "<text x=\"" . ($cx - 12) . "\" y=\"" . ($cy + 3.5) . "\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"10\" font-weight=\"bold\" fill=\"#555\">0</text>\n";
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $y = round($cy - $lvlR + 3.5, 1);
            $levelNumbersSvg .= "<text x=\"" . ($cx - 12) . "\" y=\"{$y}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"10\" font-weight=\"bold\" fill=\"#555\">{$lvl}</text>\n";
        }

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = min($maxVal, max(0, (float) ($vals[$i] ?? 0)));
            $a = $startRad + $i * $angleStep;
            $dist = ($v / $maxVal) * $r;
            $px = round($cx + $dist * cos($a), 1);
            $py = round($cy + $dist * sin($a), 1);
            $polyPts[] = "{$px},{$py}";
            $dotsSvg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"3.2\" fill=\"#e59800\" />\n";
        }
        $polygonPoints = implode(' ', $polyPts);

        return <<<SVG
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; margin-top: 15px;">
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; color: #1a1a1a; margin-bottom: 6px; text-align: center;">
                Patriotisme Mahasiswa
            </div>
            <svg width="310" height="300" viewBox="0 0 310 300">
                {$gridSvg}
                {$spokesSvg}
                {$centerDotSvg}
                {$levelNumbersSvg}
                <polygon points="{$polygonPoints}" fill="rgba(229, 152, 0, 0.15)" stroke="#e59800" stroke-width="2.6" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }
}
