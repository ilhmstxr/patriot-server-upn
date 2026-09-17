<?php

namespace Database\Factories;

use App\Models\Penugasan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Identitas>
 */
class IdentitasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'Penugasan_id' => Penugasan::factory(),
            'jml_mahasiswa' => fake()->numberBetween(1000, 50000),
            'jml_dosen' => fake()->numberBetween(100, 2000),
            'jml_tendik' => fake()->numberBetween(50, 1000),
            'jml_prodi' => fake()->numberBetween(10, 100),
            'jml_ukm' => fake()->numberBetween(5, 50),
            // 'jml_agama' tidak ada di tabel identitas — jumlah penganut disimpan per baris di tabel `agama`
            'visi' => fake()->paragraph(),
            'misi' => fake()->paragraphs(3, true),
            'legal_documents' => [
                'sk_rektor' => fake()->url(),
                'aipt' => fake()->url(),
            ],
            'is_verified' => fake()->boolean(),
            // 'admin_note' juga belum ada di tabel identitas (dikomentari di migrasi)
        ];
    }
}
