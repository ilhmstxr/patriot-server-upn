<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Evaluasi - {{ $nama_institusi ?? 'Perguruan Tinggi' }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            margin: 0;
            padding: 24px 0;
            background-color: #4a4e51;
            font-family: Arial, Helvetica, sans-serif;
            color: #111111;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
        }

        /* Fixed Toolbar */
        .no-print-toolbar {
            position: fixed;
            top: 14px;
            right: 24px;
            z-index: 9999;
            display: flex;
            gap: 12px;
        }

        .btn-print {
            background-color: #2e6b27;
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            font-size: 14.5px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(0,0,0,0.35);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-print:hover {
            background-color: #23541e;
            transform: translateY(-1px);
        }

        /* A4 Page Container */
        .page {
            width: 210mm;
            height: 297mm;
            min-height: 297mm;
            max-height: 297mm;
            background: #ffffff;
            padding: 12mm 16mm;
            box-shadow: 0 5px 20px rgba(0,0,0,0.35);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        @media print {
            body {
                background: none;
                padding: 0;
                gap: 0;
            }
            .no-print-toolbar {
                display: none !important;
            }
            .page {
                box-shadow: none !important;
                margin: 0 !important;
                width: 210mm !important;
                height: 297mm !important;
                min-height: 297mm !important;
                max-height: 297mm !important;
                page-break-after: always !important;
                page-break-inside: avoid !important;
                break-after: page !important;
                break-inside: avoid !important;
                padding: 12mm 16mm !important;
            }
            .page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
        }

        /* Typography & Components */
        .main-title {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin: 0 0 16px 0;
            color: #000000;
        }

        .section-banner {
            font-size: 15.5px;
            font-weight: bold;
            color: #ffffff;
            padding: 8.5px 14px;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .banner-green { background-color: #2f6d28; }
        .banner-orange { background-color: #df692a; }
        .banner-yellow { background-color: #f5a623; }

        .section-desc {
            font-size: 13px;
            line-height: 1.45;
            color: #111111;
            margin: 0 0 16px 0;
        }

        /* Table Styling */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            color: #000000;
        }

        table.data-table th, table.data-table td {
            border: 1px solid #111111;
            vertical-align: middle;
        }

        table.data-table th {
            font-weight: bold;
            text-align: center;
        }

        .th-green { background-color: #a7cca4; }
        .th-orange { background-color: #f5bba5; }
        .th-yellow { background-color: #fee199; }

        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }

        /* Progress Bar */
        .progress-container {
            margin-bottom: 14px;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .progress-bar-wrap {
            width: 100%;
            height: 20px;
            position: relative;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <!-- Print Button (Screen only, Admin only) -->
    @if (auth()->check() && strtoupper(auth()->user()->role ?? '') === 'ADMIN')
        <div class="no-print-toolbar">
            <button class="btn-print" onclick="window.print()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
                    <path d="M6 14h12v8H6z"/>
                </svg>
                Cetak / Simpan PDF
            </button>
        </div>
    @endif

    <!-- ================= PAGE 1 ================= -->
    <div class="page" id="page-1">
        <!-- Title -->
        <div class="main-title">PROFIL PERGURUAN TINGGI</div>

        <!-- Logo & University Meta -->
        <div style="display: flex; align-items: center; gap: 24px; margin-bottom: 22px;">
            <div style="width: 120px; height: 120px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">
                @if (!empty($logo_base64))
                    <img src="{{ $logo_base64 }}" alt="Logo {{ $nama_institusi }}" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                @else
                    <div style="width: 100px; height: 100px; border-radius: 50%; border: 2px dashed #999; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #666; text-align: center;">
                        Logo Instansi
                    </div>
                @endif
            </div>
            <div style="flex-grow: 1;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; line-height: 1.55;">
                    <tr>
                        <td style="font-weight: bold; width: 215px; padding: 2.5px 0;">NAMA</td>
                        <td style="width: 18px; text-align: center; font-weight: bold;">:</td>
                        <td style="font-weight: 500;">{{ strtoupper($nama_institusi) }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 2.5px 0;">JUMLAH DOSEN</td>
                        <td style="text-align: center; font-weight: bold;">:</td>
                        <td style="font-weight: 500;">{{ number_format($jml_dosen, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 2.5px 0; vertical-align: top;">
                            JUMLAH TENAGA<br>KEPENDIDIKAN
                        </td>
                        <td style="text-align: center; font-weight: bold; vertical-align: top;">:</td>
                        <td style="font-weight: 500; vertical-align: top;">{{ number_format($jml_tendik, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 2.5px 0;">JUMLAH MAHASISWA</td>
                        <td style="text-align: center; font-weight: bold;">:</td>
                        <td style="font-weight: 500;">{{ number_format($jml_mahasiswa, 0, ',', '.') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Predikat Card -->
        <div style="display: flex; height: 95px; margin-bottom: 22px; border: 1px solid #d4c8b6;">
            <!-- Left: Predikat & Stars -->
            <div style="flex: 1.6; background-color: #f3ecdb; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 8px;">
                <div style="font-size: 15.5px; font-weight: bold; color: #1a1a1a; margin-bottom: 4px; letter-spacing: 0.3px;">
                    PREDIKAT PERGURUAN TINGGI
                </div>
                <div style="display: flex; gap: 8px; margin-bottom: 4px;">
                    @for ($i = 0; $i < ($bintang ?? 3); $i++)
                        <svg width="38" height="38" viewBox="0 0 24 24" fill="url(#starGrad)" stroke="#c2850a" stroke-width="0.5">
                            <defs>
                                <linearGradient id="starGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" stop-color="#fed646" />
                                    <stop offset="100%" stop-color="#e0950a" />
                                </linearGradient>
                            </defs>
                            <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26" />
                        </svg>
                    @endfor
                </div>
                <div style="font-size: 13.5px; font-weight: bold; color: #1a1a1a;">
                    BINTANG {{ $bintang ?? 3 }}
                </div>
            </div>
            <!-- Right: Total Skor -->
            <div style="flex: 1; background-color: #2f6d28; display: flex; flex-direction: column; justify-content: center; padding-left: 24px;">
                <div style="color: #ffffff; font-size: 14px; margin-bottom: 2px;">
                    Total skor
                </div>
                <div style="color: #ffffff; font-size: 30px; font-weight: bold; letter-spacing: 0.5px;">
                    {{ number_format($total_skor, 2, ',', '.') }}
                </div>
            </div>
        </div>

        <!-- Progress Bars & Pie Chart -->
        <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 22px;">
            <!-- Left: Progress Bars -->
            <div style="flex: 1.25;">
                <!-- Variabel 1 -->
                <div class="progress-container">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 5px;">
                        {{ $variabel_1['nama'] }}
                    </div>
                    <div class="progress-header">
                        <span>Skor saat ini: {{ number_format($variabel_1['skor_saat_ini'], 0) }}</span>
                        <span>Skor maksimal: {{ $variabel_1['skor_maksimal'] }}</span>
                    </div>
                    <div class="progress-bar-wrap" style="background-color: #a8dca5;">
                        <div class="progress-bar-fill" style="width: {{ $variabel_1['persentase'] }}%; background-color: #3b822d; color: #ffffff;">
                            {{ $variabel_1['persentase'] }}%
                        </div>
                    </div>
                </div>

                <!-- Variabel 2 -->
                <div class="progress-container">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 5px;">
                        {{ $variabel_2['nama'] }}
                    </div>
                    <div class="progress-header">
                        <span>Skor saat ini: {{ number_format($variabel_2['skor_saat_ini'], 2, ',', '.') }}</span>
                        <span>Skor maksimal: {{ $variabel_2['skor_maksimal'] }}</span>
                    </div>
                    <div class="progress-bar-wrap" style="background-color: #f7bbb1;">
                        <div class="progress-bar-fill" style="width: {{ $variabel_2['persentase'] }}%; background-color: #cc5d39; color: #ffffff;">
                            {{ $variabel_2['persentase'] }}%
                        </div>
                    </div>
                </div>

                <!-- Variabel 3 (Labeled VARIABEL 2 in PDF) -->
                <div class="progress-container" style="margin-bottom: 0;">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 5px;">
                        {{ $variabel_3['nama'] }}
                    </div>
                    <div class="progress-header">
                        <span>Skor saat ini: {{ number_format($variabel_3['skor_saat_ini'], 2, ',', '.') }}</span>
                        <span>Skor maksimal: {{ $variabel_3['skor_maksimal'] }}</span>
                    </div>
                    <div class="progress-bar-wrap" style="background-color: #fce899;">
                        <div class="progress-bar-fill" style="width: {{ $variabel_3['persentase'] }}%; background-color: #eab318; color: #000000;">
                            {{ $variabel_3['persentase'] }}%
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Pie Chart -->
            <div style="flex: 0.95; display: flex; justify-content: center;">
                {!! $pie_chart_svg !!}
            </div>
        </div>

        <!-- Catatan Penilaian -->
        <div style="margin-top: 4px;">
            <div class="section-banner banner-green">
                CATATAN PENILAIAN
            </div>
            <div style="font-size: 13px; line-height: 1.62; text-align: justify; color: #111111; margin-top: 8px;">
                {!! $catatan_penilaian !!}
            </div>
        </div>
    </div>

    <!-- ================= PAGE 2 ================= -->
    <div class="page" id="page-2">
        <div class="main-title">SKOR BERDASARKAN VARIABEL</div>

        <div class="section-banner banner-green">
            PATRIOTISME KEBIJAKAN
        </div>

        <div class="section-desc">
            Menilai keberadaan dan implementasi kebijakan dan komitmen perguruan tinggi yang sejalan dengan nilai-nilai bela negara.
        </div>

        <!-- Two Columns: Table & Radar -->
        <div style="display: flex; gap: 24px; align-items: flex-start; margin-top: 6px;">
            <!-- Left: Table -->
            <div style="flex: 1.15;">
                <table class="data-table" style="font-size: 13.5px;">
                    <thead>
                        <tr>
                            <th class="th-green" style="width: 48px; padding: 10px 6px;">No</th>
                            <th class="th-green" style="text-align: center; padding: 10px 10px;">Indikator</th>
                            <th class="th-green" style="width: 58px; padding: 10px 6px;">Skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variabel_1['indikators'] as $item)
                            <tr>
                                <td class="text-center font-bold" style="padding: 12px 6px;">{{ $item['no'] }}</td>
                                <td style="padding: 12px 10px; line-height: 1.48;">{{ $item['indikator'] }}</td>
                                <td class="text-center font-bold" style="padding: 12px 6px; font-size: 14.5px;">{{ $item['skor'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Right: Radar Chart -->
            <div style="flex: 0.85; display: flex; justify-content: center;">
                {!! $radar_kebijakan_svg !!}
            </div>
        </div>
    </div>

    <!-- ================= PAGE 3 ================= -->
    <div class="page" id="page-3">
        <div class="section-banner banner-orange">
            PATRIOTISME KELEMBAGAAN
        </div>

        <div class="section-desc">
            Menilai kinerja perguruan tinggi dalam penyelenggaraan Tridharma Perguruan Tinggi dan penyediaan infrastruktur
        </div>

        <!-- Two Columns Layout -->
        <div style="display: flex; gap: 16px; align-items: flex-start;">
            <!-- Left Table: 2.1 to 2.12 -->
            <div style="flex: 1;">
                <table class="data-table" style="font-size: 11.2px;">
                    <thead>
                        <tr>
                            <th class="th-orange" style="width: 38px; padding: 6px 4px; font-size: 11.5px;">No</th>
                            <th class="th-orange" style="text-align: center; padding: 6px 6px; font-size: 11.5px;">Indikator</th>
                            <th class="th-orange" style="width: 48px; padding: 6px 4px; font-size: 11.5px;">Skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variabel_2['indikators_left'] as $item)
                            <tr>
                                <td class="text-center font-bold" style="padding: 4.8px 4px;">{{ $item['no'] }}</td>
                                <td style="padding: 4.8px 6px; line-height: 1.34;">{{ $item['indikator'] }}</td>
                                <td class="text-center" style="padding: 4.8px 4px;">{{ $item['skor'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Right Column: Table 2.13-2.20 & Radar Chart -->
            <div style="flex: 1; display: flex; flex-direction: column;">
                <table class="data-table" style="font-size: 11.2px;">
                    <thead>
                        <tr>
                            <th class="th-orange" style="width: 38px; padding: 6px 4px; font-size: 11.5px;">No</th>
                            <th class="th-orange" style="text-align: center; padding: 6px 6px; font-size: 11.5px;">Kategori dan Indikator</th>
                            <th class="th-orange" style="width: 48px; padding: 6px 4px; font-size: 11.5px;">Skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variabel_2['indikators_right'] as $item)
                            <tr>
                                <td class="text-center font-bold" style="padding: 4.8px 4px;">{{ $item['no'] }}</td>
                                <td style="padding: 4.8px 6px; line-height: 1.34;">{{ $item['indikator'] }}</td>
                                <td class="text-center" style="padding: 4.8px 4px;">{{ $item['skor'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <!-- Radar Chart -->
                <div style="display: flex; justify-content: center;">
                    {!! $radar_kelembagaan_svg !!}
                </div>
            </div>
        </div>
    </div>

    <!-- ================= PAGE 4 ================= -->
    <div class="page" id="page-4">
        <div class="section-banner banner-yellow">
            PATRIOTISME MAHASISWA
        </div>

        <div class="section-desc">
            Menilai tingkat internalisasi, ekspresi perilaku, dan kontribusi nyata mahasiswa terhadap nilai-nilai bela negara dalam menjaga kedaulatan negara, keutuhan wilayah, serta keselamatan bangsa.
        </div>

        <!-- Two Columns: Table & Radar -->
        <div style="display: flex; gap: 16px; align-items: flex-start;">
            <!-- Left: Table 3.1 to 3.15 -->
            <div style="flex: 1.15;">
                <table class="data-table" style="font-size: 11px;">
                    <thead>
                        <tr>
                            <th class="th-yellow" style="width: 36px; padding: 5.5px 4px; font-size: 11.5px;">No</th>
                            <th class="th-yellow" style="text-align: center; padding: 5.5px 6px; font-size: 11.5px;">Indikator</th>
                            <th class="th-yellow" style="width: 48px; padding: 5.5px 4px; font-size: 11.5px;">Skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variabel_3['indikators'] as $item)
                            <tr>
                                <td class="text-center font-bold" style="padding: 4.8px 4px;">{{ $item['no'] }}</td>
                                <td style="padding: 4.8px 6px; line-height: 1.32;">{{ $item['indikator'] }}</td>
                                <td class="text-center" style="padding: 4.8px 4px;">{{ $item['skor'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Right: Radar Chart -->
            <div style="flex: 0.85; display: flex; justify-content: center;">
                {!! $radar_mahasiswa_svg !!}
            </div>
        </div>
    </div>

</body>
</html>
