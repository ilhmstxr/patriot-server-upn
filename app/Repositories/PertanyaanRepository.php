<?php


namespace App\Repositories;

use App\Models\OpsiJawaban;
use App\Models\Pertanyaan;

class PertanyaanRepository extends BaseRepository
{
    public function __construct(Pertanyaan $model)
    {
        parent::__construct($model);
    }
    /**
     * SINGLE FORM: Mengambil seluruh pertanyaan + relasi jawaban jika sudah ada
     */
    public function getAllQuestionsWithExistingAnswers($penugasan)
    {
        return $this->model->with([
            'kategori', // Pastikan relasi ini ada di Model Pertanyaan
            'OpsiJawaban', // <--- WAJIB DITAMBAHKAN AGAR OPSI PILIHAN GANDA MUNCUL DI JSON
            'jawaban' => function ($query) use ($penugasan) {
                // Filter jawaban spesifik untuk submission ini
                $query->where('penugasan_id', $penugasan->id);
            }
        ])->get();
    }


    /**
     * Mengambil data opsi jawaban tunggal berdasarkan ID
     */
    public function findOpsiById($id)
    {
        return OpsiJawaban::find($id);
    }

    /**
     * Logic Pencocokan (Kondisi 2): Mencari opsi_jawaban berdasarkan value
     * Kolom `value` = BATAS ATAS bucket.
     * Alur: ambil opsi dengan value TERKECIL yang >= input.
     *       input <= 0  → opsi value NULL ("Tidak ada")
     *       input > semua batas → fallback opsi value TERBESAR
     * Catatan: pakai (float), bukan (int) — persentase < 1% (mis. 0.87) harus
     * tetap masuk bucket, bukan dipotong jadi 0 lalu dianggap "Tidak ada".
     */
    public function findMatchingOpsiByValue($pertanyaanId, $inputValue)
    {
        $input = (float) $inputValue;

        if ($input <= 0) {
            return OpsiJawaban::where('pertanyaan_id', $pertanyaanId)
                ->whereNull('value')
                ->first();
        }

        $match = OpsiJawaban::where('pertanyaan_id', $pertanyaanId)
            ->whereNotNull('value')
            ->where('value', '>=', $input)
            ->orderBy('value', 'asc')
            ->first();

        if (!$match) {
            $match = OpsiJawaban::where('pertanyaan_id', $pertanyaanId)
                ->whereNotNull('value')
                ->orderBy('value', 'desc')
                ->first();
        }

        return $match;
    }

    public function getPertanyaanWithOpsiJawaban()
    {
        return $this->model->with(
            'kategori',
            'OpsiJawaban'
        )->get();
    }

    public function findPertanyaanById($id)
    {
        return $this->model->findOrFail($id);
    }

    /**
     * COUNT: Total soal wajib yang harus dijawab
     */
    public function countTotalMandatoryQuestions()
    {
        // Sesuaikan jika ada pertanyaan yang tidak wajib (is_mandatory = false)
        return $this->model->count();
    }

    public function getAllCategoriesWithPertanyaans()
    {
        return \App\Models\Kategori::with('pertanyaans')->get();
    }

    /**
     * Baris ringkas rubrik untuk fingerprint cache (lihat PenugasanService::getQuestionsVersion).
     */
    public function getVersionRows()
    {
        return $this->model->orderBy('id')
            ->get(['id', 'category_id', 'kode_pertanyaan', 'teks_pertanyaan', 'tipe', 'kebutuhan_bukti'])
            ->toArray();
    }

    public function getOpsiVersionRows()
    {
        return OpsiJawaban::orderBy('id')
            ->get(['id', 'pertanyaan_id', 'opsi_jawaban', 'value', 'keterangan'])
            ->toArray();
    }

    /**
     * Pertanyaan berdasarkan kode (dipakai resync soal turunan profil).
     */
    public function getByKodes(array $kodes)
    {
        return $this->model->whereIn('kode_pertanyaan', $kodes)->get();
    }
}
