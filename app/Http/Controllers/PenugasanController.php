<?php

namespace App\Http\Controllers;

use App\DTO\PenugasanDTO\BaselineDTO;
use App\DTO\PenugasanDTO\JawabanDTO;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\DTO\PenugasanDTO\PenugasanDTO;
use App\DTO\PenugasanDTO\QuestionDTO;
use App\Http\Requests\BaselinePesertaRequest;
use App\Services\PenugasanService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PenugasanController extends Controller
{
    use ApiResponse;

    protected $PenugasanService;

    public function __construct(PenugasanService $PenugasanService)
    {
        $this->PenugasanService = $PenugasanService;
    }


    private function getValidatedPenugasan(string $mode)
    {
        $userId = AuthController::getAuthPeserta();

        if (!$userId) {
            throw new \Exception("Unauthorized: Silakan login terlebih dahulu.", 401);
        }

        $authDto = new PenugasanDTO(['user_id' => (int) $userId]);

        // Kita panggil fungsi validate di service yang bertindak sebagai dispatcher
        return $this->PenugasanService->validate($authDto, $mode);
    }

    private function getErrorCode(\Throwable $e)
    {
        $code = $e->getCode();
        // Pastikan code adalah HTTP status code yang valid
        return (is_numeric($code) && $code >= 400 && $code < 600) ? $code : 500;
    }

    private function getAuthDTO(): PenugasanDTO
    {
        $userId = AuthController::getAuthPeserta();
        return new PenugasanDTO($userId);
    }

    public function storeBaseline(BaselinePesertaRequest $request)
    // DONE
    {
        // profil
        try {
            $validatedData = $request->validated();
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_WRITE);

            $dto = new BaselineDTO((int) $penugasan->user_id, $validatedData);

            // return $dto;
            // 2. Eksekusi Service (Orchestration)
            $result = $this->PenugasanService->upsertBaseline($dto);

            return $this->successResponse(null, 'Data baseline berhasil disimpan', 200);
        } catch (\Throwable $e) {
            $status = $e->getCode() == 403 ? 403 : 500;
            return $this->errorResponse($e->getMessage(), $status);
        }
    }

    /**
     * 1. Ambil Semua Pertanyaan (Single Form)
     * Menggantikan getQuestionsByCategory dan getSteps
     */
    public function getAllQuestions($penugasanId = null)
    {
        try {
            // Gunakan mode ANY agar status SUBMITTED tetap bisa melihat soal
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_ANY);

            $data = $this->PenugasanService->getAllQuestionsWithAnswers($penugasan);

            return $this->successResponse($data, 'Data berhasil diambil', 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("GET_QUESTIONS_ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    public function getProfilePeserta($pesertaId)
    {
        try {
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_ANY);

            $profile = $this->PenugasanService->getProfilePeserta($pesertaId);

            return $this->successResponse($profile, 'Data berhasil diambil', 200);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), $this->getErrorCode($th));
        }
    }

    public function saveJawaban(Request $request)
    {
        try {
            // 1. Otorisasi & Validasi Status via Helper (MODE_WRITE)
            // Jika status SUBMITTED/GRADED, akan otomatis lempar Exception 403
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_WRITE);

            $validated = $request->validate([
                'pertanyaan_id' => 'required|integer',
                'jawaban_id'    => 'nullable|integer',
                'jawaban_teks'  => 'nullable|string',
                'tautan_bukti'  => 'nullable|string',
                'note_reviewer' => 'nullable|string',
            ]);

            $dto = new JawabanDTO($penugasan->id, $validated);

            // 3. Eksekusi Service
            $result = $this->PenugasanService->storeJawaban($dto);

            return $this->successResponse($result, 'Jawaban berhasil disimpan.', 200);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    public function finalize(Request $request)
    {
        try {
            // PROTEKSI: Paksa harus mode WRITE
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_WRITE);

            $this->PenugasanService->lockPenugasan($penugasan);

            return $this->successResponse(null, 'Seluruh penugasan telah dikunci (Final Lock)', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }


    public function previewResults(Request $request)
    {
        try {
            // PROTEKSI: Paksa harus mode READ (Sudah Final)
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_READ);

            $previewData = $this->PenugasanService->calculateTotalPreview($penugasan);

            return $this->successResponse($previewData, 'Estimasi skor total berhasil dihitung', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    /**
     * 5. Get Progress (Untuk Progress Bar)
     * Mengembalikan { total_questions: 40, answered_questions: 25 }
     */
    public function getProgress(Request $request)
    {
        try {
            $dto = $this->getAuthDTO();

            $progress = $this->PenugasanService->getCurrentProgress($dto);

            return $this->successResponse($progress, 'Data progres pengisian berhasil diambil', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/penugasan/peserta/questions/version
     * Lightweight endpoint untuk validasi cache rubrik di frontend (stale-while-revalidate).
     */
    public function getQuestionsVersion(Request $request)
    {
        try {
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_ANY);
            $data = $this->PenugasanService->getQuestionsVersion($penugasan);
            return $this->successResponse($data, 'OK', 200);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    /**
     * GET /api/penugasan/peserta/hasil
     * Returns hasil (results) data for the dashboard with raw/validated scores
     */
    public function getHasil(Request $request)
    {
        try {
            $userId = AuthController::getAuthPeserta();
            $hasilData = $this->PenugasanService->getHasilData($userId);

            return $this->successResponse($hasilData, 'Data hasil berhasil diambil', 200);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    /**
     * POST /api/penugasan/peserta/save-draft
     * Save all answers in batch and update status to SUBMITTED
     */
    public function saveDraft(Request $request)
    {
        try {
            $penugasan = $this->getValidatedPenugasan(PenugasanService::MODE_WRITE);

            $validated = $request->validate([
                'answers' => 'required|array',
                'answers.*.pertanyaan_id' => 'required|integer',
                'answers.*.jawaban_id' => 'nullable|integer',
                'answers.*.jawaban_teks' => 'nullable|string',
                'answers.*.tautan_bukti' => 'nullable|url',
            ]);

            $result = $this->PenugasanService->saveDraftBatch($penugasan, $validated['answers']);

            return $this->successResponse($result, 'Semua jawaban berhasil disimpan dan di-submit.', 200);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    /**
     * GET /admin/api/penugasan/{id}
     * Retrieve detailed penugasan data for admin/reviewer view.
     */
    public function getAdminPenugasanDetail($id)
    {
        $penugasan = \App\Models\Penugasan::with([
            'institusi',
            'identitas.agamas',
            'jawabans.pertanyaan',
            'jawabans.jawabanOpsi',
            'user',
            'reviewer1',
            'reviewer2',
            'reviewer3',
        ])->findOrFail($id);
        $service = $this->PenugasanService;

        $allPertanyaan = app(\App\Repositories\PertanyaanRepository::class)->getPertanyaanWithOpsiJawaban();
        $identitas = $penugasan->identitas;
        $catatanMap = $service->parseCatatanPenilaian($penugasan->catatan_penilaian);

        $jawabanMap = [];
        foreach ($penugasan->jawabans as $jawaban) {
            $scoresArr = is_array($jawaban->skor_validasi_reviewer)
                ? $jawaban->skor_validasi_reviewer
                : ['r1' => $jawaban->skor_validasi_reviewer, 'r2' => null, 'r3' => null];
            $notesArr = is_array($jawaban->note_reviewer)
                ? $jawaban->note_reviewer
                : ['r1' => $jawaban->note_reviewer, 'r2' => null, 'r3' => null];

            $skorR1 = (isset($scoresArr['r1']) && $scoresArr['r1'] !== '') ? (int) $scoresArr['r1'] : null;
            $skorR2 = (isset($scoresArr['r2']) && $scoresArr['r2'] !== '') ? (int) $scoresArr['r2'] : null;
            $skorR3 = (isset($scoresArr['r3']) && $scoresArr['r3'] !== '') ? (int) $scoresArr['r3'] : null;

            $jawabanMap[$jawaban->pertanyaan_id] = [
                'jawaban_id' => $jawaban->jawaban_id,
                'jawaban_teks' => $service->formatJawabanTeksDisplay($jawaban->jawaban_teks),
                'tautan_bukti_drive' => $jawaban->tautan_bukti_drive,
                'skor_sistem' => $jawaban->skor_sistem,
                'skor_validasi_reviewer' => $jawaban->resolved_reviewer_score,
                'skor_r1' => $skorR1,
                'skor_r2' => $skorR2,
                'skor_r3' => $skorR3,
                'note_reviewer' => $notesArr['r1'] ?? $notesArr['r2'] ?? null,
                'note_r1' => $notesArr['r1'] ?? null,
                'note_r2' => $notesArr['r2'] ?? null,
                'note_r3' => $notesArr['r3'] ?? null,
                'opsi_dipilih' => $jawaban->jawabanOpsi ? [
                    'id' => $jawaban->jawabanOpsi->id,
                    'opsi_jawaban' => $jawaban->jawabanOpsi->opsi_jawaban,
                    'keterangan' => $jawaban->jawabanOpsi->keterangan,
                    'value' => $jawaban->jawabanOpsi->value,
                ] : null,
            ];
        }

        $rubrikData = [];
        foreach ($allPertanyaan as $pertanyaan) {
            $kategoriName = $pertanyaan->kategori->nama_kategori ?? 'Tanpa Kategori';
            if (!isset($rubrikData[$kategoriName])) {
                $prefix = strtoupper(substr(trim($kategoriName), 0, 2));
                $bobot = match ($prefix) {
                    'A.' => 20.0,
                    'B.' => 30.0,
                    'C.' => 50.0,
                    default => 0.0,
                };

                $rubrikData[$kategoriName] = [
                    'kategori' => $kategoriName,
                    'pertanyaan_count' => 0,
                    'bobot_maksimal' => 0,
                    'bobot_persentase' => $bobot,
                    'pertanyaan' => [],
                ];
            }
            $rubrikData[$kategoriName]['pertanyaan_count']++;
            $rubrikData[$kategoriName]['bobot_maksimal'] += 5;
            $rubrikData[$kategoriName]['pertanyaan'][] = [
                'id' => $pertanyaan->id,
                'kode_pertanyaan' => $pertanyaan->kode_pertanyaan,
                'teks_pertanyaan' => $pertanyaan->teks_pertanyaan,
                'kebutuhan_bukti' => $pertanyaan->kebutuhan_bukti,
                'tipe' => $pertanyaan->tipe,
                'opsi_jawaban' => $pertanyaan->OpsiJawaban->map(fn ($opsi) => [
                    'id' => $opsi->id,
                    'opsi_jawaban' => $opsi->opsi_jawaban,
                    'keterangan' => $opsi->keterangan,
                    'value' => $opsi->value,
                ])->toArray(),
                'jawaban_peserta' => $jawabanMap[$pertanyaan->id] ?? null,
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'Penugasan' => [
                'id' => $penugasan->id,
                'status' => $penugasan->status,
                'total_skor_sistem' => $penugasan->total_skor_sistem,
                'total_skor_akhir' => $penugasan->total_skor_akhir,
                'nilai_reviewer_1' => $penugasan->nilai_reviewer_1,
                'nilai_reviewer_2' => $penugasan->nilai_reviewer_2,
                'nilai_reviewer_3' => $penugasan->nilai_reviewer_3,
                'nilai_rata_rata' => $penugasan->nilai_rata_rata,
                'reviewer_1_id' => $penugasan->reviewer_1_id,
                'reviewer_2_id' => $penugasan->reviewer_2_id,
                'reviewer_3_id' => $penugasan->reviewer_3_id,
                'reviewer_1_name' => $penugasan->reviewer1?->nama_lengkap,
                'reviewer_2_name' => $penugasan->reviewer2?->nama_lengkap,
                'reviewer_3_name' => $penugasan->reviewer3?->nama_lengkap,
                'tahun_periode' => $penugasan->tahun_periode,
                'catatan_penilaian' => $catatanMap['r1'] ?? $catatanMap['r2'] ?? null,
                'catatan_penilaian_r1' => $catatanMap['r1'] ?? null,
                'catatan_penilaian_r2' => $catatanMap['r2'] ?? null,
            ],
            'institusi' => $penugasan->institusi,
            'profil_peserta' => $identitas ? [
                'visi' => $identitas->visi,
                'misi' => $identitas->misi,
                'jml_fakultas' => $identitas->jml_fakultas,
                'jml_prodi' => $identitas->jml_prodi,
                'jml_dosen' => $identitas->jml_dosen,
                'jml_tendik' => $identitas->jml_tendik,
                'jml_mhs' => $identitas->jml_mahasiswa,
                'jml_ukm' => $identitas->jml_ukm,
                'jml_ormawa' => $identitas->jml_ormawa ?? 0,
                'berkas_pendukung' => $identitas->legal_documents,
                'agama' => $identitas->agamas->mapWithKeys(fn ($item) => [strtolower($item->agama) => $item->jumlah]),
            ] : null,
            'rubrik' => array_values($rubrikData),
            'nama_pic' => $penugasan->nama_pic,
            'jabatan_pic' => $penugasan->jabatan_pic,
            'no_hp_pic' => $penugasan->no_hp_pic,
            'email_pic' => $penugasan->user->email ?? null,
        ]]);
    }

    /**
     * Preview resume evaluasi laporan (A4 format Blade/PDF) — khusus admin.
     */
    public function previewResumePdf($id, \App\Services\ResumeReportService $reportService)
    {
        if (!auth()->check() || strtoupper(auth()->user()->role ?? '') !== 'ADMIN') {
            abort(403, 'Akses preview/export resume PDF hanya tersedia untuk admin.');
        }

        $data = $reportService->generateReportData($id);
        return view('pdf.resume', $data);
    }
}

