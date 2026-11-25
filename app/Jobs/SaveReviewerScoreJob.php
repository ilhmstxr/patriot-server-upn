<?php

namespace App\Jobs;

use App\Models\Penugasan;
use App\Services\PenugasanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SaveReviewerScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Hanya 1 percobaan: retry ditangani oleh browser (yang selalu mengirim nilai terbaru).
     * Retry di sisi server berisiko menimpa nilai baru dengan payload lama.
     *
     * @var int
     */
    public int $tries = 1;

    /** Lama status penyimpanan disimpan di cache (detik). */
    public const STATUS_TTL = 3600;

    public const DB_ERROR_MESSAGE = 'Maaf, sistem sedang mengalami gangguan sehingga nilai belum tersimpan. Silakan hubungi admin.';
    public const GENERAL_ERROR_MESSAGE = 'Nilai belum berhasil disimpan. Silakan coba lagi atau hubungi admin.';

    public int $penugasanId;
    public string $reviewerKey;
    public array $scores;
    public array $notes;
    public ?string $statusId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(int $penugasanId, string $reviewerKey, array $scores, array $notes, ?string $statusId = null)
    {
        $this->penugasanId = $penugasanId;
        $this->reviewerKey = $reviewerKey;
        $this->scores = $scores;
        $this->notes = $notes;
        $this->statusId = $statusId;
        $this->onQueue('penilaian');
    }

    public static function statusKey(string $statusId): string
    {
        return 'reviewer_save_status:' . $statusId;
    }

    public static function isDatabaseError(\Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof \PDOException;
    }

    /**
     * Perbarui status penyimpanan yang dipantau browser.
     * Gagal menulis status (mis. DB/cache mati) tidak boleh menutupi error aslinya.
     */
    private function updateStatus(string $status, ?string $errorType = null, ?string $message = null): void
    {
        if (!$this->statusId) {
            return;
        }
        try {
            $key = self::statusKey($this->statusId);
            $current = Cache::get($key, []);
            Cache::put($key, array_merge($current, [
                'status'     => $status,
                'error_type' => $errorType,
                'message'    => $message,
            ]), self::STATUS_TTL);
        } catch (\Throwable $e) {
            Log::warning('SaveReviewerScoreJob: gagal memperbarui status penyimpanan: ' . $e->getMessage());
        }
    }

    /**
     * Execute the job.
     */
    public function handle(PenugasanService $penugasanService): void
    {
        $this->updateStatus('processing');

        try {
            $penugasan = Penugasan::find($this->penugasanId);
            if (!$penugasan) {
                Log::warning("SaveReviewerScoreJob: Penugasan ID {$this->penugasanId} tidak ditemukan.");
                $this->updateStatus('failed', 'general', 'Penugasan tidak ditemukan. Data belum tersimpan.');
                return;
            }

            $penugasanService->saveReviewerScoresFromQueue(
                $penugasan,
                $this->reviewerKey,
                $this->scores,
                $this->notes
            );

            $this->updateStatus('success');
        } catch (\Throwable $e) {
            Log::error("SaveReviewerScoreJob failed: " . $e->getMessage(), [
                'penugasan_id' => $this->penugasanId,
                'reviewer_key' => $this->reviewerKey,
                'trace'        => $e->getTraceAsString(),
            ]);

            if (self::isDatabaseError($e)) {
                $this->updateStatus('failed', 'database', self::DB_ERROR_MESSAGE);
            } else {
                // Detail teknis hanya di log, bukan untuk pengguna
                $this->updateStatus('failed', 'general', self::GENERAL_ERROR_MESSAGE);
            }

            throw $e; // Re-throw agar tercatat di failed_jobs
        }
    }
}
