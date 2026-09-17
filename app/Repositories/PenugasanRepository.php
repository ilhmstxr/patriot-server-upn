<?php

namespace App\Repositories;

use App\Models\Agama;
use App\Models\Identitas;
use App\Models\Institusi;
use App\Models\Penugasan; // Gunakan PascalCase
use App\Models\ResponPenugasan;
use App\Models\Kategori;
use App\Models\Pertanyaan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PenugasanRepository extends BaseRepository
{
    public function __construct(Penugasan $model)
    {
        parent::__construct($model);
    }

    public function findActivePenugasanByUserId($userId)
    {
        return $this->model
            ->where('user_id', $userId)
            ->latest()
            ->first();
    }

    public function findActivePenugasanByUserIdAndYear($userId, $year)
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('tahun_periode', $year)
            ->with(['institusi', 'identitas.agamas'])
            ->first();
    }

    public function findLatestPenugasanByUserId($userId)
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('tahun_periode', 'desc')
            ->first();
    }

    public function getAgamasByIdentitasId($identitasId)
    {
        return Agama::where('identitas_id', $identitasId)->get();
    }

    /**
     * AUTO-SAVE (Upsert Batch)
     */
    // Contoh penggunaan di PenugasanRepository.php
    public function upsertJawaban(array $payload)
    {
        // Set default 0 hanya jika skor_sistem tidak dikirim dari service
        $payload['skor_sistem'] = $payload['skor_sistem'] ?? 0;

        return ResponPenugasan::updateOrCreate(
            [
                'penugasan_id' => $payload['penugasan_id'],
                'pertanyaan_id' => $payload['pertanyaan_id']
            ],
            $payload
        );
    }
    /**
     * COUNT: Total jawaban valid (sudah diisi claim_value dan evidence_url)
     */
    public function countValidAnswers($penugasan)
    {
        return ResponPenugasan::where('penugasan_id', $penugasan->id)
            ->whereNotNull('jawaban_id')
            // ->whereNotNull('evidence_url') // Aktifkan jika URL wajib
            ->count();
    }

    /**
     * PREVIEW: Mengambil seluruh jawaban dari satu Penugasan
     */
    public function getAllAnswersByPenugasan($penugasanId)
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)->get();
    }

    /**
     * Mencari record identitas berdasarkan Penugasan_id.
     */
    public function findIdentitasByPenugasanId(int $PenugasanId)
    {
        return Identitas::where('Penugasan_id', $PenugasanId)->first();
    }

    public function findInstitusiById(string $id)
    {
        return Institusi::find($id);
    }

    public function updateInstitusi(string $id, array $data)
    {
        return Institusi::where('id', $id)->update($data);
    }
    /**
     * Logic Create or Update (Upsert) untuk tabel Identitas.
     */
    public function upsertIdentitas(int $PenugasanId, array $data)
    {
        return Identitas::updateOrCreate(
            ['Penugasan_id' => $PenugasanId],
            $data
        );
    }

    /**
     * Logic Upsert untuk tabel Agama berdasarkan identitas_id dan nama agama.
     */
    public function upsertAgama(int $identitasId, string $agama, int $jumlah)
    {
        return Agama::updateOrCreate(
            [
                'identitas_id' => $identitasId,
                'agama' => $agama
            ],
            ['jumlah' => $jumlah]
        );
    }

    public function getAssignedPenugasansByReviewer(int $reviewerId)
    {
        return $this->model
            ->where(function ($query) use ($reviewerId) {
                $query->where('reviewer_1_id', $reviewerId)
                      ->orWhere('reviewer_2_id', $reviewerId)
                      ->orWhere('reviewer_3_id', $reviewerId);
            })
            // ->whereIn('status', ['ACTIVE', 'IN_PROGRESS', 'SUBMITTED', 'GRADED']) // Reviewer tidak boleh melihat yang masih ACTIVE/IN_PROGRESS
            ->with(['institusi' => function ($query) {
                $query->select('id', 'nama_institusi', 'jenis_institusi');
            }])
            ->orderBy('updated_at', 'desc') // Yang terbaru diubah ada di atas
            ->get();
    }

    public function isUserActive(int $userId): bool
    {
        $user = User::find($userId);
        return $user && $user->status === 'ACTIVE';
    }

    public function getProfilePeserta(int $pesertaId)
    {
        return $this->model
            ->where('user_id', $pesertaId)
            ->with(['institusi', 'identitas.agamas', 'identitas'])
            ->first();  
    }

    public function getDetailPenugasanByReviewer(int $reviewerId, int $pesertaId)
    {
        return $this->model
            ->where(function ($query) use ($reviewerId) {
                $query->where('reviewer_1_id', $reviewerId)
                      ->orWhere('reviewer_2_id', $reviewerId)
                      ->orWhere('reviewer_3_id', $reviewerId);
            })
            ->where('id', $pesertaId)
            ->with([
                'user',
                'institusi',
                'identitas.agamas',
                'jawabans.pertanyaan.kategori',
                'jawabans.pertanyaan.OpsiJawaban',
                'jawabans.jawabanOpsi',
            ])
            ->first();
    }

    /**
     * Get all answers for an penugasan grouped by category, including pertanyaan & opsi data
     */
    public function getAnswersGroupedByCategory(int $penugasanId)
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)
            ->with([
                'pertanyaan.kategori',
                'pertanyaan.OpsiJawaban',
                'jawabanOpsi',
            ])
            ->get();
    }

    /**
     * Update status of an penugasan
     */
    public function updateStatusPenugasan(int $penugasanId, string $status)
    {
        $penugasan = $this->model->find($penugasanId);
        if ($penugasan) {
            $penugasan->update(['status' => $status]);
        }
        return true;
    }
    public function getIdentitasWithAgama(int $penugasanId)
    {
        return Identitas::with('agamas')
            ->where('Penugasan_id', $penugasanId)
            ->first();
    }

    /**
     * Baris jawaban peserta untuk fingerprint cache (lihat PenugasanService::getQuestionsVersion).
     */
    public function getJawabanVersionRows(int $penugasanId)
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)
            ->orderBy('pertanyaan_id')
            ->get([
                'pertanyaan_id',
                'jawaban_id',
                'jawaban_teks',
                'skor_sistem',
                'skor_validasi_reviewer',
                'note_reviewer',
                'updated_at',
            ])
            ->toArray();
    }

    /**
     * Jawaban penugasan untuk sekumpulan pertanyaan, di-key by pertanyaan_id
     * (dipakai resync soal turunan profil).
     */
    public function getJawabanByPenugasan(int $penugasanId, array $pertanyaanIds)
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)
            ->whereIn('pertanyaan_id', $pertanyaanIds)
            ->get()
            ->keyBy('pertanyaan_id');
    }

    /**
     * Apakah sudah ada nilai dari reviewer? Kalau ya, data jawaban tidak boleh diubah otomatis.
     */
    public function adaNilaiReviewer(int $penugasanId): bool
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)
            ->whereNotNull('skor_validasi_reviewer')
            ->exists();
    }

    public function updateRekapSkor(int $penugasanId, array $rekap)
    {
        return $this->model->where('id', $penugasanId)->update([
            'skor_rekap_json' => json_encode($rekap),
        ]);
    }

    public function batchUpdateStatusByYear(string $tahun, array $fromStatuses, string $toStatus)
    {
        // Ambil hanya penugasan yang statusnya memang perlu diubah (bukan sudah $toStatus)
        // Ini mencegah updated_at berubah sia-sia dan memicu false-positive version check di frontend
        $penugasans = $this->model
            ->where('tahun_periode', $tahun)
            ->whereIn('status', $fromStatuses)
            ->where('status', '!=', $toStatus)
            ->get();

        if ($penugasans->isEmpty()) {
            return 0;
        }

        $ids     = $penugasans->pluck('id')->all();

        // Batch update langsung — lebih efisien dan updated_at hanya berubah jika ada row nyata
        $this->model->whereIn('id', $ids)->update(['status' => $toStatus]);

        return count($ids);
    }

    public function countValidReviewerScores(int $penugasanId)
    {
        return ResponPenugasan::where('penugasan_id', $penugasanId)
            ->whereNotNull('skor_validasi_reviewer')
            ->whereNotNull('note_reviewer')
            ->whereRaw('CHAR_LENGTH(TRIM(note_reviewer)) >= 20')
            ->count();
    }
}
