<?php

namespace App\Repositories;

use App\Models\Kategori;

class RubrikRepository extends BaseRepository
{
    public function __construct(Kategori $model)
    {
        parent::__construct($model);
    }

    public function getRubrikWithQuestions()
    {
        return Kategori::with('pertanyaans.OpsiJawaban')->get();
    }
    
    public function getCategoryCountQuestion()
    {
        return Kategori::withCount('pertanyaans')->get();
    }

    public function getCategoryWeight()
    {
        return Kategori::sum('bobot');
    }

    /**
     * Baris ringkas kategori untuk fingerprint cache (lihat PenugasanService::getQuestionsVersion).
     */
    public function getKategoriVersionRows()
    {
        return Kategori::orderBy('id')->get(['id', 'nama_kategori', 'deskripsi'])->toArray();
    }
}
