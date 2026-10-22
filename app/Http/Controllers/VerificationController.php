<?php

namespace App\Http\Controllers;

use App\Models\Institusi;
use App\Models\Identitas;
use App\Models\Penugasan;
use App\Models\Agama;
use App\Models\Reviewer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\LogoUploadService;

class VerificationController extends Controller
{
    use ApiResponse;

    protected $penugasanRepository;
    protected $userRepository;
    protected $institusiRepository;
    protected $logoUploadService;

    public function __construct(
        \App\Repositories\PenugasanRepository $penugasanRepository,
        \App\Repositories\UserRepository $userRepository,
        \App\Repositories\InstitusiRepository $institusiRepository,
        LogoUploadService $logoUploadService
    ) {
        $this->penugasanRepository = $penugasanRepository;
        $this->userRepository = $userRepository;
        $this->institusiRepository = $institusiRepository;
        $this->logoUploadService = $logoUploadService;
    }

    /**
     * POST /api/auth/verification
     * Submit verification form with file uploads.
     * Max 5MB per file. Accepts PDF and images (JPG/PNG).
     */
    public function submit(Request $request)
    {
        try {
            // Get user from token or fallback
            $userId = AuthController::getAuthPeserta();
            $penugasan = $this->penugasanRepository->findActivePenugasanByUserId($userId);

            if (!$penugasan) {
                return $this->errorResponse('Data Penugasan tidak ditemukan. Silakan register terlebih dahulu.', 404);
            }

            // Helper validator untuk berkas PDF (baik upload langsung maupun via path /temp)
            $validatePdf = function ($attribute, $value, $fail) {
                $label = ucwords(str_replace('_', ' ', $attribute));
                if ($value instanceof \Illuminate\Http\UploadedFile) {
                    if ($value->getSize() > 5120 * 1024) {
                        $fail("Ukuran {$label} maksimal adalah 5MB.");
                    }
                    if (strtolower($value->getClientOriginalExtension()) !== 'pdf') {
                        $fail("Format {$label} harus berupa dokumen PDF.");
                    }
                } elseif (is_string($value)) {
                    $cleanPath = ltrim(preg_replace('#^/?storage/#', '', $value), '/');
                    if (!Storage::disk('public')->exists($cleanPath)) {
                        $fail("Berkas {$label} tidak ditemukan di server. Silakan unggah ulang.");
                        return;
                    }
                    if (Storage::disk('public')->size($cleanPath) > 5120 * 1024) {
                        $fail("Ukuran berkas {$label} melebihi batas maksimal 5MB.");
                    }
                    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
                    if ($ext !== 'pdf') {
                        $fail("Format {$label} harus berupa dokumen PDF.");
                    }
                }
            };

            // Helper validator untuk Logo (JPG, JPEG, PNG, WebP)
            $validateLogo = function ($attribute, $value, $fail) {
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if ($value instanceof \Illuminate\Http\UploadedFile) {
                    if ($value->getSize() > 5120 * 1024) {
                        $fail('Ukuran Logo Instansi maksimal adalah 5MB.');
                    }
                    if (!in_array(strtolower($value->getClientOriginalExtension()), $allowed)) {
                        $fail('Format Logo Instansi harus berupa JPG, JPEG, atau PNG.');
                    }
                } elseif (is_string($value)) {
                    $cleanPath = ltrim(preg_replace('#^/?storage/#', '', $value), '/');
                    if (!Storage::disk('public')->exists($cleanPath)) {
                        $fail('Berkas Logo Instansi tidak ditemukan di server. Silakan unggah ulang.');
                        return;
                    }
                    if (Storage::disk('public')->size($cleanPath) > 5120 * 1024) {
                        $fail('Ukuran berkas Logo Instansi melebihi batas maksimal 5MB.');
                    }
                    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed)) {
                        $fail('Format Logo Instansi harus berupa JPG, JPEG, atau PNG.');
                    }
                }
            };

            // Validate all inputs — Menerima string path /temp dari uploadTemp maupun file fisik
            $validated = $request->validate([
                // Files — Section 1: Dokumen Legal
                'surat_pernyataan' => ['required', $validatePdf],
                'sk_pendirian' => ['required', $validatePdf],
                // Files — Section 2: Berkas Profil
                'profil_pt' => ['required', $validatePdf],
                'logo_url' => ['required', $validateLogo],
                'struktur_organisasi' => ['required', $validatePdf],
                // Data Institusi — Section 3
                'nama_pt' => 'required|string|max:255',
                'jenis_pt' => 'required|string|max:100',
                'visi' => 'required|string',
                'misi' => 'required|string',
                'jumlah_fakultas' => 'required|integer|min:0',
                'jumlah_prodi' => 'required|integer|min:0',
                'jumlah_dosen' => 'required|integer|min:0',
                'jumlah_tendik' => 'required|integer|min:0',
                'jumlah_mahasiswa' => 'required|integer|min:0',
                'jumlah_ormawa' => 'required|integer|min:0',
                'jumlah_ukm' => 'required|integer|min:0',
                // Agama
                'agama_islam' => 'required|integer|min:0',
                'agama_kristen' => 'required|integer|min:0',
                'agama_katolik' => 'required|integer|min:0',
                'agama_hindu' => 'required|integer|min:0',
                'agama_buddha' => 'required|integer|min:0',
                'agama_konghucu' => 'required|integer|min:0',
                // PIC
                'nama_pic' => 'required|string|max:255',
                'jabatan_pic' => 'required|string|max:255',
                'no_hp_pic' => 'required|string|max:20',
                'email_pic' => 'required|email|max:255',
            ], [
                'surat_pernyataan.required' => 'Surat Pernyataan wajib diunggah.',
                'sk_pendirian.required' => 'SK Pendirian wajib diunggah.',
                'profil_pt.required' => 'Profil Perguruan Tinggi wajib diunggah.',
                'logo_url.required' => 'Logo Instansi wajib diunggah.',
                'struktur_organisasi.required' => 'Struktur Organisasi wajib diunggah.',
            ]);

            // Create safe folder name for file storage
            $safeFolderName = Str::slug($validated['nama_pt']) . '-' . date('Y');
            $directoryPath = 'verifikasi/' . $safeFolderName;

            if (!Storage::disk('public')->exists($directoryPath)) {
                Storage::disk('public')->makeDirectory($directoryPath);
            }

            // Process all PDF files (dukung file upload langsung atau file temp yang dipindahkan)
            $files = [];
            $pdfFields = ['surat_pernyataan', 'sk_pendirian', 'profil_pt', 'struktur_organisasi'];

            foreach ($pdfFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $safeFileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.pdf';
                    $storedPath = $file->storeAs($directoryPath, $safeFileName, 'public');
                    $files[$field] = '/storage/' . $storedPath;
                } elseif ($request->filled($field)) {
                    $rawPath = $request->input($field);
                    $cleanPath = ltrim(preg_replace('#^/?storage/#', '', $rawPath), '/');

                    if (Storage::disk('public')->exists($cleanPath)) {
                        // Jika file berada di folder temp, pindahkan ke folder permanen verifikasi
                        // Storage::move memindahkan file fisik dan otomatis menghapusnya dari /temp
                        if (str_starts_with($cleanPath, 'temp/')) {
                            $targetFileName = time() . '_' . basename($cleanPath);
                            $targetPath = $directoryPath . '/' . $targetFileName;
                            Storage::disk('public')->move($cleanPath, $targetPath);
                            $files[$field] = '/storage/' . $targetPath;
                        } else {
                            $files[$field] = '/storage/' . $cleanPath;
                        }
                    } else {
                        $files[$field] = str_starts_with($rawPath, '/storage/') ? $rawPath : '/storage/' . $rawPath;
                    }
                }
            }

            // Upload & convert logo (dukung upload langsung atau dari folder temp)
            if ($request->hasFile('logo_url')) {
                $files['logo_url'] = $this->logoUploadService->uploadAndConvert(
                    $request->file('logo_url'),
                    $directoryPath
                );
            } elseif ($request->filled('logo_url')) {
                $rawPath = $request->input('logo_url');
                $cleanPath = ltrim(preg_replace('#^/?storage/#', '', $rawPath), '/');

                if (Storage::disk('public')->exists($cleanPath)) {
                    if (str_starts_with($cleanPath, 'temp/')) {
                        $fullSourcePath = storage_path('app/public/' . $cleanPath);
                        $files['logo_url'] = $this->logoUploadService->convertFromPath(
                            $fullSourcePath,
                            $directoryPath
                        );
                        // Hapus file asli di folder temp setelah dikonversi ke WebP di folder tujuan
                        Storage::disk('public')->delete($cleanPath);
                    } else {
                        $files['logo_url'] = '/storage/' . $cleanPath;
                    }
                } else {
                    $files['logo_url'] = str_starts_with($rawPath, '/storage/') ? $rawPath : '/storage/' . $rawPath;
                }
            }



            // Update or create Institusi
            $this->institusiRepository->update($penugasan->institution_id, [
                'nama_institusi' => $validated['nama_pt'],
                'jenis_institusi' => $validated['jenis_pt'],
                'logo_url' => $files['logo_url'] ?? null, // Will be ignored if null in update
            ]);

            // Cari ID reviewer tester
            $testerUser = $this->userRepository->findByEmail('reviewer@admin.com');
            $testerReviewer = $testerUser ? \App\Models\Reviewer::where('user_id', $testerUser->id)->first() : null;
            
            // Update Penugasan status to ACTIVE after verification
            $this->penugasanRepository->update($penugasan->id, [
                'nama_pic' => $validated['nama_pic'],
                'jabatan_pic' => $validated['jabatan_pic'],
                'no_hp_pic' => $validated['no_hp_pic'],
                'status' => 'ACTIVE',
                'reviewer_1_id' => $testerReviewer ? $testerReviewer->id : null,
            ]);

            // Create or update Identitas
            $identitas = $this->penugasanRepository->upsertIdentitas($penugasan->id, [
                'visi' => $validated['visi'],
                'misi' => $validated['misi'],
                'jml_mahasiswa' => $validated['jumlah_mahasiswa'],
                'jml_dosen' => $validated['jumlah_dosen'],
                'jml_tendik' => $validated['jumlah_tendik'],
                'jml_prodi' => $validated['jumlah_prodi'],
                'jml_fakultas' => $validated['jumlah_fakultas'],
                'jml_ukm' => $validated['jumlah_ukm'],
                'jml_ormawa' => $validated['jumlah_ormawa'],
                'legal_documents' => $files,
                'is_verified' => false,
            ]);

            // Upsert Agama data
            $agamaData = [
                'islam' => $validated['agama_islam'],
                'kristen' => $validated['agama_kristen'],
                'katolik' => $validated['agama_katolik'],
                'hindu' => $validated['agama_hindu'],
                'buddha' => $validated['agama_buddha'],
                'konghucu' => $validated['agama_konghucu'],
            ];

            foreach ($agamaData as $namaAgama => $jumlah) {
                $this->penugasanRepository->upsertAgama($identitas->id, $namaAgama, $jumlah);
            }

            return $this->successResponse(null, 'Verifikasi berhasil dikirim', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validasi gagal.', 422, $e->errors());
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 500);
        }
    }

    /**
     * POST /api/auth/verification/upload-temp
     * Upload single file to temporary directory before final submission.
     */
    public function uploadTemp(Request $request)
    {
        try {
            $userId = AuthController::getAuthPeserta();
            if (!$userId) {
                return $this->errorResponse('Sesi tidak valid. Silakan login kembali.', 401);
            }

            $request->validate([
                'field' => 'required|string|in:surat_pernyataan,sk_pendirian,profil_pt,logo_url,struktur_organisasi',
                'file' => 'required|file|max:5120',
            ], [
                'field.required' => 'Field berkas wajib ditentukan.',
                'file.required' => 'File wajib diunggah.',
                'file.max' => 'Ukuran berkas maksimal adalah 5MB.',
                'file.uploaded' => 'Berkas gagal diunggah ke server. Pastikan ukuran file tidak melebihi batas upload server.',
            ]);

            $field = $request->input('field');
            $file = $request->file('file');

            // Format validation based on field
            if ($field === 'logo_url') {
                $request->validate([
                    'file' => 'mimes:jpeg,jpg,png',
                ], [
                    'file.mimes' => 'Format logo harus berupa JPG, JPEG, atau PNG.',
                ]);
            } else {
                $request->validate([
                    'file' => 'mimes:pdf',
                ], [
                    'file.mimes' => 'Format berkas harus berupa dokumen PDF.',
                ]);
            }

            $safeFileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            $tempDirectory = 'temp/' . $userId;

            $storedPath = $file->storeAs($tempDirectory, $safeFileName, 'public');

            return $this->successResponse([
                'field' => $field,
                'temp_path' => $storedPath,
                'file_url' => '/storage/' . $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ], 'Berkas berhasil diunggah sementara.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validasi berkas gagal.', 422, $e->errors());
        } catch (\Throwable $th) {
            return $this->errorResponse('Gagal mengunggah berkas: ' . $th->getMessage(), 500);
        }
    }

    /**
     * POST /api/auth/verification/upload-logo
     * Dedicated endpoint to upload and convert logo.
     */
    public function uploadLogo(Request $request)
    {
        try {
            $request->validate([
                'logo_url' => 'required|file|mimes:jpeg,jpg,png|max:5120',
                'nama_pt' => 'nullable|string|max:255',
            ]);

            $namaPt = $request->input('nama_pt', 'default-pt');
            $safeFolderName = Str::slug($namaPt) . '-' . date('Y');
            $directoryPath = 'verifikasi/' . $safeFolderName;

            $logoUrl = $this->logoUploadService->uploadAndConvert(
                $request->file('logo_url'),
                $directoryPath
            );

            return $this->successResponse([
                'logo_url' => $logoUrl
            ], 'Logo berhasil diupload dan dikonversi');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validasi gagal.', 422, $e->errors());
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 500);
        }
    }

    /**
     * GET /daftar
     * Menampilkan form registrasi jika timeline sudah dibuka, atau 404 jika belum dibuka.
     */
    public function showRegistrationForm()
    {
        $cmsRepository = app(\App\Repositories\PengaturanCmsRepository::class);
        $activePeriodSetting = $cmsRepository->getByKey('active_period');
        $activePeriod = $activePeriodSetting ? $activePeriodSetting->value : date('Y');

        $timeline = \App\Models\SubmissionTimeline::where('tahun_periode', $activePeriod)->first();
        $now = \Illuminate\Support\Carbon::now();

        if (!$timeline || ($timeline->opens_at && $now->lt($timeline->opens_at))) {
            abort(404);
        }

        return view('auth.daftar');
    }
}

