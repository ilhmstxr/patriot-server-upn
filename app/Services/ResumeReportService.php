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

        // 2. Map Jawaban Peserta & Skor R1
        $jawabanMap = [];
        if ($penugasan->jawabans) {
            foreach ($penugasan->jawabans as $jawaban) {
                $kode = $jawaban->pertanyaan?->kode_pertanyaan;
                if ($kode) {
                    $r1 = null;
                    if (is_array($jawaban->skor_validasi_reviewer)) {
                        $r1 = $jawaban->skor_validasi_reviewer['r1'] ?? null;
                    } elseif (is_numeric($jawaban->skor_validasi_reviewer)) {
                        $r1 = $jawaban->skor_validasi_reviewer;
                    }

                    $skorSistem = $jawaban->skor_sistem ?? 0;
                    $jawabanMap[$kode] = [
                        'skor_r1' => $r1 !== null ? (float)$r1 : (float)$skorSistem,
                        'skor_sistem' => (float)$skorSistem,
                        'jawaban' => $jawaban
                    ];
                }
            }
        }

        // 3. Proses Variabel 1: Patriotisme Kebijakan (A.1 - A.5 / 1.1 - 1.5)
        $kebijakanData = $this->processKebijakan($jawabanMap);

        // 4. Proses Variabel 2: Patriotisme Kelembagaan (B.1 - B.20 / 2.1 - 2.20)
        $kelembagaanData = $this->processKelembagaan($jawabanMap);

        // 5. Proses Variabel 3: Patriotisme Mahasiswa (C.1 - C.15 / 3.1 - 3.15)
        $mahasiswaData = $this->processMahasiswa($jawabanMap);

        // 6. Total Skor & Predikat Bintang
        $totalSkor = round($kebijakanData['skor_saat_ini'] + $kelembagaanData['skor_saat_ini'] + $mahasiswaData['skor_saat_ini'], 2);
        if ($totalSkor <= 0 && $penugasan->total_skor_akhir) {
            $totalSkor = (float) $penugasan->total_skor_akhir;
        } elseif ($totalSkor <= 0 && $penugasan->nilai_reviewer_1) {
            $totalSkor = (float) $penugasan->nilai_reviewer_1;
        }

        $bintang = $this->calculateBintang($totalSkor);

        // 7. Generate SVG Diagrams
        $pieChartSvg = $this->renderPieChartSvg(
            $kebijakanData['persentase'],
            $kelembagaanData['persentase'],
            $mahasiswaData['persentase']
        );

        $radarKebijakanSvg = $this->renderRadarKebijakanSvg($kebijakanData['raw_scores']);
        $radarKelembagaanSvg = $this->renderRadarKelembagaanSvg($kelembagaanData['raw_scores']);
        $radarMahasiswaSvg = $this->renderRadarMahasiswaSvg($mahasiswaData['raw_scores']);

        // 8. Catatan Penilaian (Status: TBD)
        $catatanPenilaian = $penugasan->catatan_penilaian ?? 
            "Penerapan indikator implementasi {$namaInstitusi} secara umum memerlukan improvisasi di poin penelitian, pengabdian masyarakat, serta kegiatan civitas akademika yang berkegiatan di luar di mana kegiatan tersebut mencerminkan esensi dan nilai Bela Negara yang diusung oleh universitas terkait. Beberapa bukti juga masih memerlukan kelengkapan sebagai perwujudan implementasi nilai Bela Negara. Pemanfaatan fasilitas penunjang di kampus untuk disabilitas juga memerlukan perhatian dan peningkatan sehingga mencapai poin yang diinginkanS. Untuk mencapai poin yang lebih optimal, maka diperlukan evaluasi serta penetapan target capaian implementasi nilai – nila bela negara di segala lini, serta pemanfaatan sumber daya manusia yang ada secara optimal. Optimalisasi kegiatan dosen dan tenaga pendidik di bidang pengabdian masyarakat dan penelitian juga perlu dilakukan secara lebih optimal. Output yang diharapkan juga sebaiknya memiliki nilai keberlanjutan yang dampaknya bisa dirasakan oleh masyarakat luas secara berkelanjutan.";

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
        $totalSkor = 0;

        foreach ($definisi as $kode => $meta) {
            $skor = isset($jawabanMap[$kode]) ? $jawabanMap[$kode]['skor_r1'] : $meta['default'];
            // Pastikan integer / float bersahabat
            $rawScores[] = $skor;
            $totalSkor += $skor;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => (int) round($skor),
            ];
        }

        $skorMaksimal = 20;
        $persentase = $skorMaksimal > 0 ? (int) round(($totalSkor / $skorMaksimal) * 100) : 0;

        return [
            'nama' => 'VARIABEL 1: PATRIOTISME KEBIJAKAN',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators' => $indikators,
            'raw_scores' => $rawScores,
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
        $totalSkor = 0;

        foreach ($definisi as $kode => $meta) {
            if (isset($jawabanMap[$kode])) {
                $raw = $jawabanMap[$kode]['skor_r1'];
                // Jika nilai mentah 0-5, konversi ke bobot 1.50
                $skor = $raw <= 5 ? round(($raw / 5) * 1.50, 2) : round($raw, 2);
            } else {
                $skor = $meta['default'];
            }

            $rawScores[] = $skor;
            $totalSkor += $skor;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => number_format($skor, 2, '.', ''),
            ];
        }

        $skorMaksimal = 30;
        $totalSkor = round($totalSkor, 2);
        $persentase = $skorMaksimal > 0 ? (int) round(($totalSkor / $skorMaksimal) * 100) : 0;

        return [
            'nama' => 'VARIABEL 2: PATRIOTISME KELEMBAGAAN',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators_left' => array_slice($indikators, 0, 12),
            'indikators_right' => array_slice($indikators, 12, 8),
            'raw_scores' => $rawScores,
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
        $totalSkor = 0;

        foreach ($definisi as $kode => $meta) {
            if (isset($jawabanMap[$kode])) {
                $raw = $jawabanMap[$kode]['skor_r1'];
                $skor = $raw <= 5 ? round(($raw / 5) * (50 / 15), 2) : round($raw, 2);
            } else {
                $skor = $meta['default'];
            }

            $rawScores[] = $skor;
            $totalSkor += $skor;

            $indikators[] = [
                'kode' => $kode,
                'no' => $meta['no'],
                'indikator' => $meta['judul'],
                'skor' => number_format($skor, 2, ',', ''),
            ];
        }

        $skorMaksimal = 50;
        $totalSkor = round($totalSkor, 2);
        $persentase = $skorMaksimal > 0 ? (int) round(($totalSkor / $skorMaksimal) * 100) : 0;

        return [
            'nama' => 'VARIABEL 2: PATRIOTISME MAHASISWA',
            'skor_saat_ini' => $totalSkor,
            'skor_maksimal' => $skorMaksimal,
            'persentase' => $persentase,
            'indikators' => $indikators,
            'raw_scores' => $rawScores,
        ];
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
                    <tspan x="156" dy="14">30%</tspan>
                </text>
                
                <text x="64" y="66" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="10.5" font-weight="bold" fill="#2d6d25">
                    <tspan x="64" dy="0">Patriotisme</tspan>
                    <tspan x="64" dy="14">Kelembagaan</tspan>
                    <tspan x="64" dy="14">30%</tspan>
                </text>
                
                <text x="110" y="138" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="10.5" font-weight="bold" fill="#2d6d25">
                    <tspan x="110" dy="0">Patriotisme</tspan>
                    <tspan x="110" dy="14">Mahasiswa</tspan>
                    <tspan x="110" dy="14">40%</tspan>
                </text>
            </svg>
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: bold; color: #1a1a1a; text-align: center; margin-top: 6px; line-height: 1.4;">
                Kontribusi skor tiap variabel pada<br>total skor
            </div>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Kebijakan (5 sumbu).
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

        $levelNumbersSvg = "<text x=\"" . ($cx - 14) . "\" y=\"" . ($cy + 4) . "\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"11.5\" font-weight=\"bold\" fill=\"#555\">0</text>\n";
        foreach ($levels as $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $y = round($cy - $lvlR + 4, 1);
            $levelNumbersSvg .= "<text x=\"" . ($cx - 14) . "\" y=\"{$y}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"11.5\" font-weight=\"bold\" fill=\"#555\">{$lvl}</text>\n";
        }

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = $vals[$i] ?? 0;
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
                {$levelNumbersSvg}
                <polygon points="{$polygonPoints}" fill="rgba(45, 109, 37, 0.15)" stroke="#2d6d25" stroke-width="2.6" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Kelembagaan (20 sumbu).
     */
    public function renderRadarKelembagaanSvg(array $vals): string
    {
        $cx = 150; $cy = 142; $r = 88;
        $num = 20;
        $maxVal = 1.5;
        $labels = [
            '2.1.', '2.2.', '2.3.', '2.4.', '2.5.', '2.6.', '2.7.', '2.8.', '2.9.', '2.10.',
            '2.11.', '2.12.', '2.13.', '2.14.', '2.15.', '2.16.', '2.17.', '2.18.', '2.19.', '2.20.'
        ];
        $levels = [0.5, 1.0, 1.5];
        $angleStep = (2 * M_PI) / $num;
        $startRad = -M_PI / 2;

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

        $scaleA = $startRad - $angleStep * 1.5;
        $levelNumbersSvg = "<text x=\"" . ($cx - 8) . "\" y=\"" . ($cy + 4) . "\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"10.5\" font-weight=\"bold\" fill=\"#555\">0</text>\n";
        foreach ($levels as $idx => $lvl) {
            $lvlR = ($lvl / $maxVal) * $r;
            $lx = round($cx + $lvlR * cos($scaleA), 1);
            $ly = round($cy + $lvlR * sin($scaleA), 1);
            $txt = $idx === 0 ? '0,5' : ($idx === 1 ? '1' : '1,5');
            $levelNumbersSvg .= "<text x=\"{$lx}\" y=\"{$ly}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"10.5\" font-weight=\"bold\" fill=\"#555\">{$txt}</text>\n";
        }

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = $vals[$i] ?? 0;
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
                {$levelNumbersSvg}
                <polygon points="{$polygonPoints}" fill="rgba(223, 105, 42, 0.15)" stroke="#df692a" stroke-width="2.3" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }

    /**
     * Render SVG Radar Patriotisme Mahasiswa (15 sumbu).
     */
    public function renderRadarMahasiswaSvg(array $vals): string
    {
        $cx = 150; $cy = 145; $r = 92;
        $num = 15;
        $maxVal = 3.5;
        $labels = [
            '3.1.', '3.2.', '3.3.', '3.4.', '3.5.', '3.6.', '3.7.', '3.8.', '3.9.', '3.10',
            '3.11.', '3.12.', '3.13.', '3.14.', '3.15.'
        ];
        $levels = [1.0, 2.0, 3.0, 3.5];
        $angleStep = (2 * M_PI) / $num;
        $startRad = -M_PI / 2;

        $gridSvg = '';
        foreach ($levels as $lvl) {
            $lvlR = round(($lvl / $maxVal) * $r, 1);
            $gridSvg .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"{$lvlR}\" fill=\"none\" stroke=\"#edd9b8\" stroke-width=\"1\" />\n";
        }

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

        $polyPts = [];
        $dotsSvg = '';
        for ($i = 0; $i < $num; $i++) {
            $v = $vals[$i] ?? 0;
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
                <polygon points="{$polygonPoints}" fill="rgba(229, 152, 0, 0.15)" stroke="#e59800" stroke-width="2.6" />
                {$dotsSvg}
                {$labelsSvg}
            </svg>
        </div>
        SVG;
    }
}
