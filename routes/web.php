<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\BeritaController;
use App\Http\Controllers\CmsAssetController;
use App\Http\Controllers\ComproPreviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PenugasanController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\VerificationController;
use App\Services\RubrikService;
use App\Services\SubmissionService;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;



// CMS assets (public, no auth)
Route::get('/cms-assets/{path}', [CmsAssetController::class, 'show'])
    ->where('path', '.*')
    ->name('cms.asset');

// public routes / compro
Route::get('/', [ComproPreviewController::class, 'welcome']);

Route::get('/profile', function () {
    return view('profile');
});

Route::get('/tim', function () {
    return view('tim');
});

Route::get('/penghargaan', function () {
    return view('penghargaan');
});

Route::get('/pengumuman', function () {
    return view('pengumuman');
});

Route::get('/berita', [BeritaController::class, 'index']);
Route::get('/berita/{judul}', [BeritaController::class, 'show'])->name('berita.show');

Route::get('/panduan', function () {
    return view('panduan');
});

// Admin preview route for compro pages (authenticated only)
Route::get('/admin/compro-preview/{page}', [ComproPreviewController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('compro.preview');

// Auth pages (public - no middleware needed, auth is handled via API token)
Route::get('/masuk', function () {
    return view('auth.masuk');
})->name('login');

Route::get('/daftar', [VerificationController::class, 'showRegistrationForm']);

Route::get('/lupa-sandi', fn() => view('auth.lupa-sandi'));

Route::get('/reset-password/{token}', fn($token) => view('auth.reset-password', compact('token')));

Route::get('/verifikasi', function () {
    return view('auth.verifikasi');
})->name('verifikasi');

Route::get('/cek-email', function () {
    return view('auth.cek-email');
})->name('cek-email');

Route::get('/verifikasi-gagal', function () {
    return view('auth.verifikasi-gagal');
})->name('verifikasi-gagal');

// Dashboard pages (peserta)
Route::prefix('dashboard')->group(function () {
    Route::get('/', function () {
        return view('dashboard.index');
    })->name('dashboard.index');

    Route::get('/rubrik', function () {
        return view('dashboard.rubrik');
    })->name('dashboard.rubrik');

    Route::get('/hasil', function () {
        return view('dashboard.hasil');
    })->name('dashboard.hasil');

    Route::get('/panduan', function () {
        return view('dashboard.panduan');
    })->name('dashboard.panduan');
});

// Reviewer pages
Route::prefix('reviewer')->group(function () {
    Route::get('/', function () {
        return view('reviewer.panduan');
    })->name('reviewer.index');

    Route::get('/dashboard', function () {
        return view('reviewer.index');
    })->name('reviewer.dashboard');

    Route::get('/peserta/{id}', function ($id) {
        return view('reviewer.detail', compact('id'));
    })->name('reviewer.peserta_detail');
});

// Admin penugasan detail (readonly reviewer view)
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/penugasan-detail/{id}', function ($id) {
        return view('reviewer.detail', ['id' => $id, 'adminReadonly' => true]);
    })->name('admin.penugasan.detail');

    Route::get('/admin/api/penugasan/{id}', [PenugasanController::class, 'getAdminPenugasanDetail'])->name('admin.penugasan.api');
});

// Resume Laporan PDF Preview & Export
Route::get('/penugasan/{id}/resume-pdf', [PenugasanController::class, 'previewResumePdf'])->name('penugasan.resume.pdf');
