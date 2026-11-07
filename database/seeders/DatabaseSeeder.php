<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'password' => bcrypt('admin'),
                'role' => 'ADMIN',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ]
        );

        // User::create([
        //     'email' => "peserta@test.com",
        //     'password' => bcrypt('peserta123'),
        //     'role' => 'PESERTA',
        //     'status' => 'ACTIVE',
        // ]);

        // User::create([
        //     'email' => "reviewer@admin.com",
        //     'password' => bcrypt('reviewer'),
        //     'role' => 'REVIEWER',
        //     'status' => 'ACTIVE',
        // ]);

        User::updateOrCreate(
            ['email' => 'upn@pic.com'],
            [
                'password' => bcrypt('upn123'),
                'role' => 'PESERTA',
                'status' => 'ACTIVE',
            ]
        );

        $this->call([
            KategoriSeeder::class,
            PertanyaanSeeder::class,
            // PengaturanCmsSeeder::class,
            ComproContentSeeder::class,
            BeritaSeeder::class,
            // Si   mulasiSeeder::class
            // InstitusiSeeder::class,
            // PenugasanSeeder::class,
            // IdentitasSeeder::class,
            // ResponPenugasanSeeder::class,
            // ReviewerSeeder::class,
        ]);

    }
}
