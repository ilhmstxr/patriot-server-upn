<?php

namespace App\Providers;

use App\Http\Controllers\DaftarNilaiExportController;
use App\Models\Agama;
use App\Models\Identitas;
use App\Services\PenugasanService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Rate limiter untuk export Daftar Nilai:
        // Maksimal 4 chunk/detik x 25 PT/chunk = maksimal 100 data PT per detik agar server tidak overload.
        RateLimiter::for('daftar-nilai-export', function (Request $request) {
            $key = 'daftar-nilai-export:' . ($request->user()?->id ?? $request->ip());

            return Limit::perSecond(DaftarNilaiExportController::MAX_REQUESTS_PER_SECOND)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Batas permintaan export per detik tercapai. Menunggu jeda otomatis...',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 1),
                    ], 429, $headers);
                });
        });

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
