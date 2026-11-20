<?php

namespace App\Jobs;

use App\Models\Penugasan;
use App\Services\PenugasanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SaveReviewerScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public int $backoff = 2;

    public int $penugasanId;
    public string $reviewerKey;
    public array $scores;
    public array $notes;

    /**
     * Create a new job instance.
     */
    public function __construct(int $penugasanId, string $reviewerKey, array $scores, array $notes)
    {
        $this->penugasanId = $penugasanId;
        $this->reviewerKey = $reviewerKey;
        $this->scores = $scores;
        $this->notes = $notes;
        $this->onQueue('penilaian');
    }

    /**
     * Execute the job.
     */
    public function handle(PenugasanService $penugasanService): void
    {
        try {
            $penugasan = Penugasan::find($this->penugasanId);
            if (!$penugasan) {
                Log::warning("SaveReviewerScoreJob: Penugasan ID {$this->penugasanId} tidak ditemukan.");
                return;
            }

            $penugasanService->saveReviewerScoresFromQueue(
                $penugasan,
                $this->reviewerKey,
                $this->scores,
                $this->notes
            );
        } catch (\Throwable $e) {
            Log::error("SaveReviewerScoreJob failed: " . $e->getMessage(), [
                'penugasan_id' => $this->penugasanId,
                'reviewer_key' => $this->reviewerKey,
                'trace'        => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to allow Laravel queue retry mechanism
        }
    }
}
