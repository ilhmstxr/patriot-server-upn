<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penugasan extends Model
{
    /** @use HasFactory<\Database\Factories\PenugasanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'institution_id', 'tahun_periode', 'status', 
        'total_skor_sistem', 'total_skor_akhir', 'skor_rekap_json', 
        'catatan_penilaian',
        'nama_pic', 'jabatan_pic', 'no_hp_pic',
        'reviewer_1_id', 'reviewer_2_id', 'reviewer_3_id',
        'nilai_reviewer_1', 'nilai_reviewer_2', 'nilai_reviewer_3', 'nilai_rata_rata'
    ];

    protected $casts = [
        'skor_rekap_json' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $nilai_1 = (float) ($model->nilai_reviewer_1 ?? 0);
            $nilai_2 = (float) ($model->nilai_reviewer_2 ?? 0);
            $nilai_3 = (float) ($model->nilai_reviewer_3 ?? 0);
            $model->nilai_rata_rata = round(($nilai_1 + $nilai_2) / 2, 2);

            // Jika status divalidasi dan nilai final diubah manual, ubah status ke FINALIZED
            if ($model->isDirty('total_skor_akhir') && $model->status === 'VALIDATING') {
                $model->status = 'FINALIZED';
            }

            // Jika nilai final (total_skor_akhir) diubah secara manual di request ini, simpan langsung
            if ($model->isDirty('total_skor_akhir')) {
                return;
            }

            // Jika nilai reviewer tidak berubah, dan nilai final sudah diset ke R1 atau R2, jangan timpa
            $final = (float) ($model->total_skor_akhir ?? 0);
            $r1_atau_r2_terpilih = ($final === $nilai_1 || $final === $nilai_2);
            if (!$model->isDirty('nilai_reviewer_1') && 
                !$model->isDirty('nilai_reviewer_2') && 
                !$model->isDirty('nilai_reviewer_3') && 
                $final > 0 && 
                $r1_atau_r2_terpilih
            ) {
                return;
            }

            if ($nilai_3 > 0) {
                // Selalu ambil R1 atau R2 yang paling mendekati R3 jika R3 sudah mengisi
                $diff1 = abs($nilai_1 - $nilai_3);
                $diff2 = abs($nilai_2 - $nilai_3);

                if ($diff1 < $diff2) {
                    $model->total_skor_akhir = $nilai_1;
                } elseif ($diff2 < $diff1) {
                    $model->total_skor_akhir = $nilai_2;
                } else {
                    // Jika selisihnya sama, ambil Reviewer 1
                    $model->total_skor_akhir = $nilai_1;
                }
            } else {
                // Jika R3 belum mengisi, gunakan nilai rata-rata R1 & R2
                $model->total_skor_akhir = $model->nilai_rata_rata;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }



    public function reviewer1()
    {
        return $this->belongsTo(Reviewer::class, 'reviewer_1_id');
    }

    public function reviewer2()
    {
        return $this->belongsTo(Reviewer::class, 'reviewer_2_id');
    }

    public function reviewer3()
    {
        return $this->belongsTo(Reviewer::class, 'reviewer_3_id');
    }

    public function jawabans()
    {
        return $this->hasMany(ResponPenugasan::class, 'penugasan_id');
    }

    public function identitas()
    {
        return $this->hasOne(Identitas::class, 'Penugasan_id');
    }

    public function institusi()
    {
        return $this->belongsTo(Institusi::class, 'institution_id');
    }
}
