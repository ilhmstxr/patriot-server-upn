<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Identitas extends Model
{
    /** @use HasFactory<\Database\Factories\IdentitasFactory> */
    use HasFactory;

    protected $fillable = [
        'Penugasan_id',
        'jml_mahasiswa',
        'jml_dosen',
        'jml_tendik',
        'jml_prodi',
        'jml_ukm',
        'jml_fakultas',
        'jml_ormawa',
        // 'jml_agama' & 'admin_note' tidak ada di tabel identitas (lihat migrasi):
        // penganut disimpan per baris di tabel `agama`, admin_note masih dikomentari.
        'visi',
        'misi',
        'legal_documents',
        'is_verified',
    ];

    protected $casts = [
        'legal_documents' => 'array',
        'is_verified' => 'boolean',
    ];

    public function Penugasan()
    {
        return $this->belongsTo(Penugasan::class, 'Penugasan_id');
    }

    public function agamas()
    {
        return $this->hasMany(Agama::class, 'identitas_id');
    }
}
