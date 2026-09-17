<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Pertanyaan;
use App\Models\Kategori;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pertanyaan>
 */
class PertanyaanFactory extends Factory
{
    protected $model = Pertanyaan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode_pertanyaan' => $this->faker->unique()->regexify('[A-Z]{3}-[0-9]{4}'),
            'category_id' => Kategori::factory(),
            'teks_pertanyaan' => $this->faker->sentence(),
            'kebutuhan_bukti' => $this->faker->sentence(),
            'tipe' => $this->faker->randomElement(['pilihan_ganda', 'essay', 'file']),
            // 'skor_maksimal' => $this->faker->numberBetween(1, 100),
        ];
    }
}
