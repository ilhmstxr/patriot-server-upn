<?php

namespace App\Providers;

use App\Models\Agama;
use App\Models\Identitas;
use App\Services\PenugasanService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Data profil berubah → jawaban rubrik yang diturunkan dari profil (rumus persen &
        // soal otomatis) ikut dihitung ulang. Satu titik untuk semua penulis: edit profil
        // peserta, /verifikasi, dan edit dari panel admin.
        Identitas::saved(function (Identitas $identitas) {
            if ($identitas->Penugasan_id) {
                app(PenugasanService::class)->sinkronJawabanProfil((int) $identitas->Penugasan_id);
            }
        });

        // Jumlah penganut per agama ikut menentukan B.20 (jml_agama_aktif).
        Agama::saved(function (Agama $agama) {
            $penugasanId = Identitas::find($agama->identitas_id)?->Penugasan_id;
            if ($penugasanId) {
                app(PenugasanService::class)->sinkronJawabanProfil((int) $penugasanId);
            }
        });
    }
}
