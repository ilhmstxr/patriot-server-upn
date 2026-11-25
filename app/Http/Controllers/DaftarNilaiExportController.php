<?php

namespace App\Http\Controllers;

use App\Services\ResumeReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

class DaftarNilaiExportController extends Controller
{
    /**
     * Batas keras jumlah PT per request.
     * Dikombinasikan dengan rate limiter 4 req/detik => maksimal 100 data PT/detik.
     */
    public const DEFAULT_CHUNK_SIZE = 25;
    public const MAX_CHUNK_SIZE = 25;
    public const MAX_REQUESTS_PER_SECOND = 4;
    public const CLIENT_PACING_MS = 260;

    private function checkAdminAccess(): ?JsonResponse
    {
        if (!auth()->check() || strtoupper((string) (auth()->user()->role ?? '')) !== 'ADMIN') {
            return response()->json([
                'success' => false,
                'message' => 'Akses export daftar nilai hanya tersedia untuk admin.',
            ], 403);
        }

        return null;
    }

    /**
     * Terapkan batas aman maksimal 4 request/detik per admin (4 x 25 = 100 data PT/detik).
     * Mengembalikan HTTP 429 JSON secara langsung agar tidak tertelan exception handler global.
     */
    private function checkRateLimit(Request $request): ?JsonResponse
    {
        $key = 'daftar-nilai-export:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS_PER_SECOND)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'success' => false,
                'message' => 'Batas permintaan export per detik tercapai. Menunggu jeda otomatis...',
                'retry_after' => $retryAfter,
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) self::MAX_REQUESTS_PER_SECOND,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 1);

        return null;
    }

    /**
     * GET /admin/api/daftar-nilai/export-meta
     * Mengirim metadata ringan (total data, parameter pacing, daftar periode, dan skema rubrik sekali saja).
     */
    public function meta(Request $request): JsonResponse
    {
        if ($denied = $this->checkAdminAccess()) {
            return $denied;
        }
        if ($limited = $this->checkRateLimit($request)) {
            return $limited;
        }

        $tahunPeriode = $request->filled('tahun_periode') ? (int) $request->input('tahun_periode') : null;
        $status = $request->filled('status') ? strtoupper(trim((string) $request->input('status'))) : null;
        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;

        $countQuery = DB::table('penugasans as p')
            ->leftJoin('institusis as i', 'i.id', '=', 'p.institution_id');

        if ($tahunPeriode !== null) {
            $countQuery->where('p.tahun_periode', $tahunPeriode);
        }
        if ($status !== null && $status !== '') {
            $countQuery->where('p.status', $status);
        }
        if ($search !== null && $search !== '') {
            $countQuery->where('i.nama_institusi', 'like', '%' . $search . '%');
        }

        $total = (int) $countQuery->count('p.id');

        $availableYears = DB::table('penugasans')
            ->select('tahun_periode')
            ->distinct()
            ->orderByDesc('tahun_periode')
            ->pluck('tahun_periode')
            ->map(fn ($y) => (int) $y)
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'chunk_size' => self::DEFAULT_CHUNK_SIZE,
                'max_per_second' => self::MAX_CHUNK_SIZE * self::MAX_REQUESTS_PER_SECOND,
                'delay_ms' => self::CLIENT_PACING_MS,
                'available_years' => $availableYears,
                'rubrik_schema' => ResumeReportService::getRubrikSchema(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /admin/api/daftar-nilai/export-chunk
     * Mengirim potongan (chunk) data PT berbasis keyset pagination (O(1) index seek, max 25 PT/request).
     * Seluruh perhitungan detail, rendering diagram Canvas, dan pembuatan file Excel/CSV dilakukan di client.
     */
    public function chunk(Request $request): JsonResponse
    {
        if ($denied = $this->checkAdminAccess()) {
            return $denied;
        }
        if ($limited = $this->checkRateLimit($request)) {
            return $limited;
        }

        $cursor = max(0, (int) $request->input('cursor', 0));
        $limit = min(self::MAX_CHUNK_SIZE, max(1, (int) $request->input('limit', self::DEFAULT_CHUNK_SIZE)));
        $mode = $request->input('mode') === 'summary' ? 'summary' : 'full';
        $tahunPeriode = $request->filled('tahun_periode') ? (int) $request->input('tahun_periode') : null;
        $status = $request->filled('status') ? strtoupper(trim((string) $request->input('status'))) : null;
        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;

        // Query 1: Ambil data ringkas penugasan + institusi + reviewer (+ identitas jika mode full)
        $selectColumns = [
            'p.id',
            'p.institution_id',
            'p.tahun_periode',
            'p.status',
            'p.nilai_reviewer_1',
            'p.nilai_reviewer_2',
            'p.nilai_reviewer_3',
            'p.nilai_rata_rata',
            'p.total_skor_akhir',
            'i.nama_institusi',
            'r1.nama_lengkap as nama_penilai_1',
            'r2.nama_lengkap as nama_penilai_2',
            'r3.nama_lengkap as nama_penilai_3',
        ];

        $query = DB::table('penugasans as p')
            ->leftJoin('institusis as i', 'i.id', '=', 'p.institution_id')
            ->leftJoin('reviewers as r1', 'r1.id', '=', 'p.reviewer_1_id')
            ->leftJoin('reviewers as r2', 'r2.id', '=', 'p.reviewer_2_id')
            ->leftJoin('reviewers as r3', 'r3.id', '=', 'p.reviewer_3_id')
            ->where('p.id', '>', $cursor);

        if ($mode === 'full') {
            $query->leftJoin('identitas as idn', 'idn.Penugasan_id', '=', 'p.id');
            if ($this->hasCatatanPenilaianColumn()) {
                $selectColumns[] = 'p.catatan_penilaian';
            }
            $selectColumns[] = 'idn.jml_dosen';
            $selectColumns[] = 'idn.jml_tendik';
            $selectColumns[] = 'idn.jml_mahasiswa';
        }

        if ($tahunPeriode !== null) {
            $query->where('p.tahun_periode', $tahunPeriode);
        }
        if ($status !== null && $status !== '') {
            $query->where('p.status', $status);
        }
        if ($search !== null && $search !== '') {
            $query->where('i.nama_institusi', 'like', '%' . $search . '%');
        }

        // Ambil limit + 1 agar tahu has_more tanpa query COUNT tambahan
        $rows = $query
            ->orderBy('p.id', 'asc')
            ->limit($limit + 1)
            ->select($selectColumns)
            ->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit)->values();
        }

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                    'next_cursor' => null,
                    'has_more' => false,
                ],
            ]);
        }

        // Query 2: Batch lookup Nilai & Bintang Tahun Lalu (tahun_periode - 1) untuk institusi di chunk ini
        $institutionIds = $rows->pluck('institution_id')->filter()->unique()->values()->all();
        $prevYears = $rows->pluck('tahun_periode')
            ->filter()
            ->map(fn ($y) => ((int) $y) - 1)
            ->unique()
            ->values()
            ->all();

        $prevYearMap = [];
        if (!empty($institutionIds) && !empty($prevYears)) {
            $prevRows = DB::table('penugasans')
                ->whereIn('institution_id', $institutionIds)
                ->whereIn('tahun_periode', $prevYears)
                ->select(['institution_id', 'tahun_periode', 'status', 'total_skor_akhir', 'nilai_rata_rata'])
                ->get();

            foreach ($prevRows as $prev) {
                $key = $prev->institution_id . ':' . ((int) $prev->tahun_periode);
                $prevYearMap[$key] = $prev;
            }
        }

        // Query 3 (khusus mode full / Excel): Batch ambil skor reviewer per indikator untuk chunk ini saja
        $scoresByPenugasan = [];
        if ($mode === 'full') {
            $penugasanIds = $rows->pluck('id')->all();
            $responRows = DB::table('respon_penugasans as rp')
                ->join('pertanyaans as q', 'q.id', '=', 'rp.pertanyaan_id')
                ->whereIn('rp.penugasan_id', $penugasanIds)
                ->whereNotNull('rp.skor_validasi_reviewer')
                ->select(['rp.penugasan_id', 'q.kode_pertanyaan', 'rp.skor_validasi_reviewer'])
                ->get();

            foreach ($responRows as $rp) {
                $kode = trim((string) ($rp->kode_pertanyaan ?? ''));
                if ($kode === '') {
                    continue;
                }

                [$r1, $r2] = $this->extractReviewerPair($rp->skor_validasi_reviewer);
                if ($r1 === null && $r2 === null) {
                    continue;
                }

                $scoresByPenugasan[(int) $rp->penugasan_id][$kode] = [$r1, $r2];
            }
        }

        // Susun payload super ringan
        $items = [];
        $lastId = $cursor;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $lastId = $id;

            $rowStatus = strtoupper((string) ($row->status ?? ''));
            $isUnverified = $rowStatus === 'UNVERIFIED';

            $nilaiP1 = $row->nilai_reviewer_1 !== null ? round((float) $row->nilai_reviewer_1, 2) : null;
            $nilaiP2 = $row->nilai_reviewer_2 !== null ? round((float) $row->nilai_reviewer_2, 2) : null;
            $nilaiP3 = $row->nilai_reviewer_3 !== null ? round((float) $row->nilai_reviewer_3, 2) : null;
            $nilaiRataRata = $row->nilai_rata_rata !== null ? round((float) $row->nilai_rata_rata, 2) : null;
            $skorAkhir = $row->total_skor_akhir !== null ? round((float) $row->total_skor_akhir, 2) : null;

            $bintang = ResumeReportService::resolveBintang($skorAkhir, $rowStatus);

            // Tahun lalu
            $prevKey = $row->institution_id . ':' . (((int) $row->tahun_periode) - 1);
            $prevRow = $prevYearMap[$prevKey] ?? null;
            $nilaiTahunLalu = null;
            $bintangTahunLalu = 0;

            if ($prevRow !== null) {
                $prevFinal = $prevRow->total_skor_akhir !== null ? round((float) $prevRow->total_skor_akhir, 2) : null;
                $prevStatus = strtoupper((string) ($prevRow->status ?? ''));
                if ($prevFinal !== null && $prevFinal > 0) {
                    $nilaiTahunLalu = $prevFinal;
                    $bintangTahunLalu = ResumeReportService::resolveBintang($prevFinal, $prevStatus);
                } elseif ($prevFinal !== null && $prevFinal == 0.0 && $prevStatus !== '') {
                    $nilaiTahunLalu = 0.0;
                    $bintangTahunLalu = 0;
                }
            }

            $item = [
                'id' => $id,
                'nama_institusi' => $row->nama_institusi ?: 'Perguruan Tinggi #' . $id,
                'tahun_periode' => (int) $row->tahun_periode,
                'status' => $rowStatus,
                'is_unverified' => $isUnverified,
                'nilai_rata_rata' => $nilaiRataRata,
                'total_skor_akhir' => $skorAkhir,
                'bintang' => $bintang,
                'nama_penilai_1' => $row->nama_penilai_1 ?: null,
                'nilai_p1' => $nilaiP1,
                'nama_penilai_2' => $row->nama_penilai_2 ?: null,
                'nilai_p2' => $nilaiP2,
                'nama_penilai_3' => $row->nama_penilai_3 ?: null,
                'nilai_p3' => $nilaiP3,
                'nilai_tahun_lalu' => $nilaiTahunLalu,
                'bintang_tahun_lalu' => $bintangTahunLalu,
            ];

            if ($mode === 'full') {
                $item['jml_dosen'] = $row->jml_dosen !== null ? (int) $row->jml_dosen : null;
                $item['jml_tendik'] = $row->jml_tendik !== null ? (int) $row->jml_tendik : null;
                $item['jml_mahasiswa'] = $row->jml_mahasiswa !== null ? (int) $row->jml_mahasiswa : null;
                $item['catatan_penilaian'] = $this->formatCatatan($row->catatan_penilaian ?? null);
                $item['scores'] = $scoresByPenugasan[$id] ?? new \stdClass();
            }

            $items[] = $item;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'next_cursor' => $hasMore ? $lastId : null,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Cek aman apakah kolom catatan_penilaian sudah dimigrasi di tabel penugasans.
     * Mencegah error SQLSTATE[42S22] jika migrasi terbaru belum dijalankan di DB aktif.
     */
    private function hasCatatanPenilaianColumn(): bool
    {
        static $cache = [];
        $conn = DB::getDefaultConnection();
        $dbName = (string) config("database.connections.{$conn}.database", $conn);
        $key = $conn . ':' . $dbName;

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasColumn('penugasans', 'catatan_penilaian');
        }

        return $cache[$key];
    }

    /**
     * Ekstrak pasangan [r1, r2] dari JSON skor_validasi_reviewer (range 0..5).
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function extractReviewerPair(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (is_array($decoded)) {
            $r1 = (isset($decoded['r1']) && $decoded['r1'] !== '' && is_numeric($decoded['r1']))
                ? round(min(5.0, max(0.0, (float) $decoded['r1'])), 2)
                : null;
            $r2 = (isset($decoded['r2']) && $decoded['r2'] !== '' && is_numeric($decoded['r2']))
                ? round(min(5.0, max(0.0, (float) $decoded['r2'])), 2)
                : null;

            return [$r1, $r2];
        }

        if (is_numeric($decoded)) {
            return [round(min(5.0, max(0.0, (float) $decoded)), 2), null];
        }

        return [null, null];
    }

    /**
     * Parse catatan penilaian (mendukung format JSON multi-reviewer r1/r2 atau teks biasa).
     */
    private function formatCatatan(?string $raw): ?string
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && (array_key_exists('r1', $decoded) || array_key_exists('r2', $decoded) || array_key_exists('r3', $decoded))) {
            $parts = [];
            $r1 = trim((string) ($decoded['r1'] ?? ''));
            $r2 = trim((string) ($decoded['r2'] ?? ''));
            $r3 = trim((string) ($decoded['r3'] ?? ''));

            if ($r1 !== '' && $r2 !== '') {
                $parts[] = "Catatan Penilai 1:\n" . $r1;
                $parts[] = "Catatan Penilai 2:\n" . $r2;
                if ($r3 !== '') {
                    $parts[] = "Catatan Penilai 3:\n" . $r3;
                }
                return implode("\n\n", $parts);
            }

            $single = $r1 ?: ($r2 ?: $r3);
            return $single !== '' ? $single : null;
        }

        return $trimmed;
    }
}
