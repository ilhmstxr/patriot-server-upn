<?php

namespace App\Http\Controllers;

use App\Jobs\SaveReviewerScoreJob;
use App\Services\PenugasanService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReviewerController extends Controller
{
    use ApiResponse;

    protected $penugasanService;
    protected $reviewerRepository;
    protected $penugasanRepository;

    public function __construct(
        PenugasanService $penugasanService,
        \App\Repositories\ReviewerRepository $reviewerRepository,
        \App\Repositories\PenugasanRepository $penugasanRepository
    ) {
        $this->penugasanService = $penugasanService;
        $this->reviewerRepository = $reviewerRepository;
        $this->penugasanRepository = $penugasanRepository;
    }

    private function getErrorCode(\Throwable $e)
    {
        $code = $e->getCode();
        return (is_numeric($code) && $code >= 400 && $code < 600) ? $code : 500;
    }

    /**
     * Endpoint: GET /api/reviewer/tasks
     * Mengambil daftar tugas (plottingan) institusi yang harus dinilai.
     */
    public function getAssignedTasks(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user || strtolower($user->role) !== 'reviewer') {
                throw new \Exception("Unauthorized: Akses khusus untuk Reviewer.", 403);
            }
            
            $reviewer = $this->reviewerRepository->findByUserId($user->id);
            if (!$reviewer) {
                throw new \Exception("Profil Reviewer tidak ditemukan.", 404);
            }
            $reviewerId = $reviewer->id;

            // Eksekusi Service (Kirim $user->id untuk pengecekan status aktif user)
            $result = $this->penugasanService->getAssignedReviews($reviewerId, $user->id);

            return $this->successResponse($result, 'Daftar plottingan tugas berhasil diambil.', 200);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    public function getDetailTasks(Request $request, $pesertaId)
    {
        try {
            $user = $request->user();
            if (!$user || strtolower($user->role) !== 'reviewer') {
                throw new \Exception("Unauthorized: Akses khusus untuk Reviewer.", 403);
            }
            
            $reviewer = $this->reviewerRepository->findByUserId($user->id);
            if (!$reviewer) {
                throw new \Exception("Profil Reviewer tidak ditemukan.", 404);
            }
            $reviewerId = $reviewer->id;

            // Eksekusi Service (Kirim $user->id untuk pengecekan status aktif user)
            $result = $this->penugasanService->getDetailReviewTasks($reviewerId, (int) $pesertaId, $user->id);

            return $this->successResponse($result, 'Daftar plottingan tugas berhasil diambil.', 200);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }

    /**
     * Endpoint: POST /api/penugasan/reviewer/tasks/{pesertaId}/save-scores
     * Menyimpan skor validasi reviewer per indikator dan mengupdate rekap skor JSON.
     * Body: { scores: { pertanyaan_id: skor, ... }, notes: { pertanyaan_id: catatan, ... } }
     */
    public function saveScores(Request $request, $pesertaId)
    {
        try {
            $user = $request->user();
            if (!$user || strtolower($user->role) !== 'reviewer') {
                throw new \Exception("Unauthorized: Akses khusus untuk Reviewer.", 403);
            }

            $penugasan = $this->penugasanRepository->find($pesertaId);
            if (!$penugasan || !in_array($penugasan->status, ['SUBMITTED', 'IN_PROGRESS', 'GRADED'])) {
                 throw new \Exception("Penugasan tidak ditemukan atau tidak dapat dinilai.", 404);
            }

            $reviewer = $this->reviewerRepository->findByUserId($user->id);
            if (!$reviewer) {
                throw new \Exception("Profil Reviewer tidak ditemukan.", 404);
            }
            $reviewerId = $reviewer->id;

            $reviewerKey = null;
            if ($reviewerId === $penugasan->reviewer_1_id) {
                $reviewerKey = 'r1';
            } elseif ($reviewerId === $penugasan->reviewer_2_id) {
                $reviewerKey = 'r2';
            } elseif ($reviewerId === $penugasan->reviewer_3_id) {
                $reviewerKey = 'r3';
            }

            if (!$reviewerKey) {
                throw new \Exception("Akses ditolak: Anda tidak ditugaskan sebagai reviewer pada penugasan ini.", 403);
            }

            $scores = $request->input('scores', []);
            $notes  = $request->input('notes', []);

            // Status dicatat agar browser bisa memastikan data benar-benar masuk database
            $statusId = (string) Str::uuid();
            Cache::put(SaveReviewerScoreJob::statusKey($statusId), [
                'status'       => 'queued',
                'error_type'   => null,
                'message'      => null,
                'user_id'      => $user->id,
                'penugasan_id' => (int) $penugasan->id,
            ], SaveReviewerScoreJob::STATUS_TTL);

            // Dispatch ke antrean FIFO 'penilaian' agar tidak membebani koneksi MySQL
            SaveReviewerScoreJob::dispatch(
                (int) $penugasan->id,
                $reviewerKey,
                $scores,
                $notes,
                $statusId
            )->onQueue('penilaian');

            return $this->successResponse([
                'queued' => true,
                'job_id' => $statusId,
            ], 'Skor dimasukkan ke antrean penyimpanan.');
        } catch (\Throwable $e) {
            return $this->saveErrorResponse($e);
        }
    }

    /**
     * Endpoint: GET /api/penugasan/reviewer/tasks/{pesertaId}/save-status/{jobId}
     * Mengecek apakah job penyimpanan skor sudah benar-benar tersimpan ke database.
     * Status: queued | processing | success | failed
     */
    public function saveStatus(Request $request, $pesertaId, string $jobId)
    {
        try {
            $user = $request->user();
            if (!$user || strtolower($user->role) !== 'reviewer') {
                throw new \Exception("Unauthorized: Akses khusus untuk Reviewer.", 403);
            }

            $status = Cache::get(SaveReviewerScoreJob::statusKey($jobId));
            if (!$status
                || ($status['user_id'] ?? null) !== $user->id
                || (int) ($status['penugasan_id'] ?? 0) !== (int) $pesertaId) {
                throw new \Exception("Status penyimpanan tidak ditemukan atau sudah kedaluwarsa.", 404);
            }

            return $this->successResponse([
                'status'     => $status['status'] ?? 'queued',
                'error_type' => $status['error_type'] ?? null,
                'message'    => $status['message'] ?? null,
            ], 'Status penyimpanan.');
        } catch (\Throwable $e) {
            return $this->saveErrorResponse($e);
        }
    }

    /**
     * Respons error penyimpanan. Error database diberi pesan khusus "hubungi admin"
     * tanpa membocorkan detail SQL ke browser.
     */
    private function saveErrorResponse(\Throwable $e)
    {
        if (SaveReviewerScoreJob::isDatabaseError($e)) {
            Log::error('Reviewer save error (database): ' . $e->getMessage());
            return $this->errorResponse(SaveReviewerScoreJob::DB_ERROR_MESSAGE, 503, ['error_type' => 'database']);
        }
        $code = $this->getErrorCode($e);
        if ($code >= 500) {
            // Error tak terduga: sembunyikan detail teknis dari pengguna
            Log::error('Reviewer save error: ' . $e->getMessage());
            return $this->errorResponse(SaveReviewerScoreJob::GENERAL_ERROR_MESSAGE, $code, ['error_type' => 'general']);
        }
        // 4xx adalah pesan yang memang kita tulis untuk pengguna (akses ditolak, dll.)
        return $this->errorResponse($e->getMessage(), $code, ['error_type' => 'general']);
    }

    /**
     * Endpoint: POST /api/penugasan/reviewer/tasks/{pesertaId}/finalize
     * Memfinalisasi penilaian: set status GRADED dan lock rekap skor akhir.
     */
    public function finalizeReview(Request $request, $pesertaId)
    {
        try {
            $user = $request->user();
            if (!$user || strtolower($user->role) !== 'reviewer') {
                throw new \Exception("Unauthorized: Akses khusus untuk Reviewer.", 403);
            }

            $request->validate([
                'catatan_penilaian' => 'required|string|min:50',
            ], [
                'catatan_penilaian.required' => 'Catatan penilaian / executive summary wajib diisi.',
                'catatan_penilaian.min' => 'Catatan penilaian / executive summary minimal 50 karakter.',
            ]);

            $catatanPenilaian = $request->input('catatan_penilaian');

            $penugasan = $this->penugasanRepository->find($pesertaId);
            if (!$penugasan || !in_array($penugasan->status, ['SUBMITTED', 'IN_PROGRESS'])) {
                 throw new \Exception("Penugasan tidak ditemukan atau tidak dapat difinalisasi.", 404);
            }

            $reviewer = $this->reviewerRepository->findByUserId($user->id);
            if (!$reviewer) {
                throw new \Exception("Profil Reviewer tidak ditemukan.", 404);
            }
            $reviewerId = $reviewer->id;

            $reviewerKey = null;
            if ($reviewerId === $penugasan->reviewer_1_id) {
                $reviewerKey = 'r1';
            } elseif ($reviewerId === $penugasan->reviewer_2_id) {
                $reviewerKey = 'r2';
            } elseif ($reviewerId === $penugasan->reviewer_3_id) {
                $reviewerKey = 'r3';
            }

            if (!$reviewerKey) {
                throw new \Exception("Akses ditolak: Anda tidak ditugaskan sebagai reviewer pada penugasan ini.", 403);
            }

            // Tunggu jika masih ada antrean penyimpanan yang sedang diproses untuk penugasan ini (max 3 detik)
            $waitAttempts = 3;
            while ($this->penugasanService->hasPendingReviewerJobs((int) $penugasan->id) && $waitAttempts > 0) {
                sleep(1);
                $waitAttempts--;
            }

            $this->penugasanService->finalizeReview($penugasan, $reviewerKey, $catatanPenilaian);

            return $this->successResponse([], 'Penilaian berhasil difinalisasi. Status peserta berubah menjadi GRADED.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), $this->getErrorCode($e));
        }
    }
}
