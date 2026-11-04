<?php

namespace App\Http\Controllers;

use App\DTO\PenugasanDTO\PenugasanDTO;
use App\DTO\AuthDTO\LoginDTO;
use App\DTO\AuthDTO\RegisterDTO;
use App\Models\Institusi;
use App\Models\Penugasan;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    use ApiResponse;
    protected $userService;
    protected $penugasanRepository;
    protected $institusiRepository;
    protected $cmsRepository;
    protected $userRepository;

    public function __construct(
        UserService $userService,
        \App\Repositories\PenugasanRepository $penugasanRepository,
        \App\Repositories\InstitusiRepository $institusiRepository,
        \App\Repositories\PengaturanCmsRepository $cmsRepository,
        \App\Repositories\UserRepository $userRepository
    ) {
        $this->userService = $userService;
        $this->penugasanRepository = $penugasanRepository;
        $this->institusiRepository = $institusiRepository;
        $this->cmsRepository = $cmsRepository;
        $this->userRepository = $userRepository;
    }

    public function register(Request $request)
    {
        try {
            $activePeriodSetting = $this->cmsRepository->getByKey('active_period');
            $activePeriod = $activePeriodSetting ? $activePeriodSetting->value : date('Y');

            $timeline = \App\Models\SubmissionTimeline::where('tahun_periode', $activePeriod)->first();
            $now = \Illuminate\Support\Carbon::now();

            if (!$timeline || ($timeline->opens_at && $now->lt($timeline->opens_at))) {
                return $this->errorResponse('Pendaftaran belum dibuka untuk periode saat ini.', 404);
            }

            if ($timeline->is_locked) {
                $reason = $timeline->note ? ': ' . $timeline->note : '.';
                return $this->errorResponse('Pendaftaran saat ini dikunci oleh admin' . $reason, 400);
            }

            if ($timeline->closes_at && $now->gt($timeline->closes_at)) {
                return $this->errorResponse('Pendaftaran sudah ditutup pada ' . $timeline->closes_at->translatedFormat('d M Y H:i') . '.', 400);
            }

            $validated = $request->validate([
                'nama_pt' => 'required|string|max:255',
                'jenis_pt' => 'required|string|max:100',
                'nama_pic' => 'required|string|max:255',
                'no_hp_pic' => 'required|string|max:20',
                'jabatan_pic' => 'required|string|max:100',
                'email' => ['required', 'email', 'regex:/@[a-z0-9.-]+\.ac\.id$/i', 'unique:users,email'],
                'password' => 'required|string|min:8|confirmed',
            ], [
                'email.regex' => 'Email harus menggunakan domain institusi resmi (.ac.id).',
                'email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau gunakan email lain.',
            ]);

            // 1 instansi 1 akun: cek nama_institusi & domain email
            $fullDomain = strtolower(substr(strrchr($validated['email'], '@'), 1));
            $domainParts = explode('.', $fullDomain);
            $domain = count($domainParts) >= 3 ? implode('.', array_slice($domainParts, -3)) : $fullDomain;

            if ($this->institusiRepository->existsByName($validated['nama_pt'])) {
                return $this->errorResponse('Institusi dengan nama tersebut sudah memiliki akun terdaftar. Silakan masuk atau hubungi admin.', 422);
            }

            if ($this->institusiRepository->existsByDomain($domain)) {
                return $this->errorResponse('Domain email institusi ini sudah memiliki akun terdaftar. Satu institusi hanya boleh memiliki satu akun.', 422);
            }

            $dto = new RegisterDTO($validated);
            $user = $this->userService->register($dto);

            // Create Sanctum token for auto-login after registration
            $token = $user->createToken('auth_token', ['*'], now()->addHours(8))->plainTextToken;

            return $this->successResponse([
                'user' => $user,
                'token' => $token,
                'user_status' => 'UNVERIFIED',
                'penugasan_status' => 'UNVERIFIED',
                'redirect_to' => '/cek-email',
            ], 'Registrasi berhasil. Silakan verifikasi email Anda.', 201);
        } catch (\Throwable $th) {
            $code = (is_numeric($th->getCode()) && $th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
            return $this->errorResponse($th->getMessage(), $code);
        }
    }

    /**
     * GET /api/auth/check-institusi?nama=...&email=...
     * Public endpoint untuk cek ketersediaan saat user mengisi form daftar.
     */
    public function checkInstitusi(Request $request)
    {
        $nama = trim((string) $request->query('nama', ''));
        $email = trim((string) $request->query('email', ''));

        $reasons = [];

        if ($nama !== '') {
            if ($this->institusiRepository->existsByName($nama)) {
                 $reasons[] = 'Nama institusi sudah terdaftar.';
            }
        }

        if ($email !== '') {
            $fullDomain = strtolower(substr(strrchr($email, '@'), 1));
            $domainParts = explode('.', $fullDomain);
            $domain = count($domainParts) >= 3 ? implode('.', array_slice($domainParts, -3)) : $fullDomain;
            
            if ($this->institusiRepository->existsByDomain($domain)) {
                 $reasons[] = 'Domain email institusi ini sudah terdaftar.';
            }
        }

        return $this->successResponse([
            'exists' => count($reasons) > 0,
            'message' => count($reasons) > 0 ? implode(' ', $reasons) : 'Tersedia.',
        ], 'OK', 200);
    }

    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $dto = new LoginDTO($validated);
            $result = $this->userService->login($dto, false);

            // Determine redirect path based on user status and penugasan status
            $user = $result['user'];
            $penugasan = $this->penugasanRepository->findActivePenugasanByUserId($user->id);

            $redirectTo = '/dashboard';
            if ($user->status === 'UNVERIFIED') {
                $redirectTo = '/cek-email';
            } elseif ($penugasan && $penugasan->status === 'UNVERIFIED') {
                $redirectTo = '/verifikasi';
            }

            // Include user role for reviewer redirect
            if ($user->role === 'REVIEWER') {
                $redirectTo = '/reviewer';
            }

            $userData = $user->toArray();
            if (strtoupper($user->role) === 'REVIEWER') {
                $reviewer = $user->reviewer;
                if ($reviewer) {
                    $userData['nama_lengkap'] = $reviewer->nama_lengkap;
                }
            }

            return $this->successResponse([
                'user' => array_merge($userData, [
                    'nama_institusi' => $penugasan?->institusi?->nama_institusi
                ]),
                'token' => $result['token'],
                'token_expires_at' => $result['expires_at'] ?? null,
                'redirect_to' => $redirectTo,
                'user_status' => $user->status,
                'penugasan_status' => $penugasan?->status,
            ], 'Login berhasil.', 200);
        } catch (\Throwable $th) {
            $code = (is_numeric($th->getCode()) && $th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 401;
            return $this->errorResponse($th->getMessage(), $code);
        }
    }

    /**
     * POST /api/auth/change-password
     * Mengubah kata sandi user yang sedang login. Token aktif lain dihapus
     * (single-session policy tetap dijaga: token saat ini dipertahankan).
     */
    public function changePassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ], [
                'new_password.min' => 'Kata sandi baru minimal 8 karakter.',
                'new_password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
            ]);

            $user = $request->user();
            if (!$user) return $this->errorResponse('Unauthorized.', 401);

            if (!Hash::check($validated['current_password'], $user->password)) {
                return $this->errorResponse('Kata sandi lama tidak sesuai.', 422);
            }

            $user->password = Hash::make($validated['new_password']);
            $user->save();

            // Cabut token lain (selain yang aktif sekarang) untuk keamanan.
            $currentTokenId = optional($request->user()->currentAccessToken())->id;
            if ($currentTokenId) {
                $user->tokens()->where('id', '!=', $currentTokenId)->delete();
            }

            return $this->successResponse(null, 'Kata sandi berhasil diperbarui.', 200);
        } catch (\Throwable $th) {
            $code = (is_numeric($th->getCode()) && $th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
            return $this->errorResponse($th->getMessage(), $code);
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            if ($user) {
                $this->userService->logout($user);
            }
            return $this->successResponse(null, 'Logout berhasil.', 200);
        } catch (\Throwable $th) {
            $code = (is_numeric($th->getCode()) && $th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
            return $this->errorResponse($th->getMessage(), $code);
        }
    }

    /**
     * GET /api/auth/me — Return current user info + Penugasan status
     * Used by frontend to determine redirect after login
     */
    public function me(Request $request)
    {
        try {
            $userId = self::getAuthPeserta();
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return $this->errorResponse('User tidak ditemukan.', 404);
            }

            $activePeriodSetting = $this->cmsRepository->getByKey('active_period');
            $activePeriod = $activePeriodSetting ? $activePeriodSetting->value : date('Y');

            $penugasan = $this->penugasanRepository->findActivePenugasanByUserIdAndYear($user->id, $activePeriod);

            if (!$penugasan) {
                // Find previous Penugasan
                $prevPenugasan = $this->penugasanRepository->findLatestPenugasanByUserId($user->id);
                
                if ($prevPenugasan) {
                    // Create new Penugasan bypassing verification
                    $penugasan = $this->penugasanRepository->create([
                        'user_id' => $user->id,
                        'institution_id' => $prevPenugasan->institution_id,
                        'tahun_periode' => $activePeriod,
                        'status' => 'ACTIVE',
                        'nama_pic' => $prevPenugasan->nama_pic,
                        'jabatan_pic' => $prevPenugasan->jabatan_pic,
                        'no_hp_pic' => $prevPenugasan->no_hp_pic,
                    ]);

                    // Copy Identitas
                    $prevIdentitas = $this->penugasanRepository->findIdentitasByPenugasanId($prevPenugasan->id);
                    if ($prevIdentitas) {
                        $identitasData = [
                            'Penugasan_id' => $penugasan->id,
                            'visi' => $prevIdentitas->visi,
                            'misi' => $prevIdentitas->misi,
                            'jml_mahasiswa' => $prevIdentitas->jml_mahasiswa,
                            'jml_dosen' => $prevIdentitas->jml_dosen,
                            'jml_tendik' => $prevIdentitas->jml_tendik,
                            'jml_prodi' => $prevIdentitas->jml_prodi,
                            'jml_fakultas' => $prevIdentitas->jml_fakultas,
                            'jml_ukm' => $prevIdentitas->jml_ukm,
                            'jml_ormawa' => $prevIdentitas->jml_ormawa,
                            'legal_documents' => $prevIdentitas->legal_documents,
                            'is_verified' => $prevIdentitas->is_verified,
                        ];
                        $newIdentitas = $this->penugasanRepository->upsertIdentitas($penugasan->id, $identitasData);
                        
                        // Copy Agama
                        $agamas = $this->penugasanRepository->getAgamasByIdentitasId($prevIdentitas->id);
                        foreach ($agamas as $agama) {
                            $this->penugasanRepository->upsertAgama($newIdentitas->id, $agama->agama, $agama->jumlah);
                        }
                    }
                    $penugasan = $this->penugasanRepository->findActivePenugasanByUserIdAndYear($user->id, $activePeriod);
                } else {
                    $penugasan = $this->penugasanRepository->findActivePenugasanByUserId($user->id);
                }
            }
            
            $editSetting = $this->cmsRepository->getByKey('is_peserta_edit_enabled');
            $isEditEnabled = $editSetting ? filter_var($editSetting->value, FILTER_VALIDATE_BOOLEAN) : true;

            $profileEditSetting = $this->cmsRepository->getByKey('is_peserta_profile_edit_enabled');
            $isProfileEditEnabled = $profileEditSetting ? filter_var($profileEditSetting->value, FILTER_VALIDATE_BOOLEAN) : true;

            $reviewerNamaLengkap = null;
            if (strtoupper($user->role) === 'REVIEWER') {
                $reviewerNamaLengkap = $user->reviewer?->nama_lengkap;
            }

            return $this->successResponse([
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'nama_lengkap' => $reviewerNamaLengkap,
                    'nama_institusi' => $penugasan?->institusi?->nama_institusi,
                ],
                'penugasan' => $penugasan ? [
                    'id' => $penugasan->id,
                    'status' => $penugasan->status,
                    'tahun_periode' => $penugasan->tahun_periode,
                    'nama_pic' => $penugasan->nama_pic,
                    'jabatan_pic' => $penugasan->jabatan_pic,
                    'no_hp_pic' => $penugasan->no_hp_pic,
                    'email_pic' => $user->email,
                    'institusi' => $penugasan->institusi,
                    'identitas' => $penugasan->identitas,
                    'agamas' => $penugasan->identitas?->agamas,
                ] : null,
                'is_edit_enabled' => $isEditEnabled,
                'is_peserta_profile_edit_enabled' => $isProfileEditEnabled,
                'active_period' => $activePeriod,
            ], 'Data user berhasil diambil.', 200);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 500);
        }
    }

    /**
     * Helper: Get authenticated reviewer ID.
     */
    public static function getAuthReviewer()
    {
        return Auth::id();
    }

    /**
     * Helper: Get authenticated peserta ID.
     */
    public static function getAuthPeserta()
    {
        return Auth::id();
    }

    public function getAuth()
    {
        return self::getAuthReviewer();
    }

    /**
     * PUT /api/auth/profile
     * Update seluruh data profil peserta: data PIC, identitas (visi, misi, data numerik), dan demografi agama.
     * Hanya dapat dilakukan jika CMS setting 'is_peserta_profile_edit_enabled' bernilai true.
     */
    public function updateProfile(Request $request)
    {
        try {
            // Cek permission dari CMS
            $profileEditSetting = $this->cmsRepository->getByKey('is_peserta_profile_edit_enabled');
            $isProfileEditEnabled = $profileEditSetting ? filter_var($profileEditSetting->value, FILTER_VALIDATE_BOOLEAN) : true;

            if (!$isProfileEditEnabled) {
                return $this->errorResponse('Fitur edit profil saat ini dinonaktifkan oleh admin.', 403);
            }

            $userId = self::getAuthPeserta();
            if (!$userId) {
                return $this->errorResponse('Unauthorized.', 401);
            }

            $validated = $request->validate([
                'nama_pic'      => 'required|string|max:255',
                'jabatan_pic'   => 'nullable|string|max:100',
                'no_hp_pic'     => 'required|string|max:20',
                'email'         => ['nullable', 'email', 'regex:/@[a-z0-9.-]+\.ac\.id$/i', Rule::unique('users', 'email')->ignore($userId)],
                'visi'          => 'nullable|string',
                'misi'          => 'nullable|string',
                'jml_fakultas'  => 'nullable|integer|min:0',
                'jml_prodi'     => 'nullable|integer|min:0',
                'jml_dosen'     => 'nullable|integer|min:0',
                'jml_tendik'    => 'nullable|integer|min:0',
                'jml_mahasiswa' => 'nullable|integer|min:0',
                'jml_ormawa'    => 'nullable|integer|min:0',
                'jml_ukm'       => 'nullable|integer|min:0',
                'agamas'        => 'nullable|array',
            ], [
                'email.regex' => 'Email harus menggunakan domain institusi resmi (.ac.id).',
                'email.unique' => 'Email tersebut sudah digunakan akun lain.',
            ]);

            // Ambil Penugasan aktif milik peserta
            $activePeriodSetting = $this->cmsRepository->getByKey('active_period');
            $activePeriod = $activePeriodSetting ? $activePeriodSetting->value : date('Y');

            $penugasan = $this->penugasanRepository->findActivePenugasanByUserIdAndYear($userId, $activePeriod);

            if (!$penugasan) {
                return $this->errorResponse('Data Penugasan tidak ditemukan.', 404);
            }

            // 1. Update data PIC di Penugasan
            $this->penugasanRepository->update($penugasan->id, [
                'nama_pic'    => $validated['nama_pic'],
                'jabatan_pic' => $validated['jabatan_pic'] ?? $penugasan->jabatan_pic,
                'no_hp_pic'   => $validated['no_hp_pic'],
            ]);

            // 1b. Update email user (akun) jika dikirim & berbeda
            if (!empty($validated['email'])) {
                $user = $this->userRepository->find($userId);
                if ($user && $user->email !== $validated['email']) {
                    $this->userRepository->update($userId, ['email' => $validated['email']]);
                }
            }

            // 2. Update data Identitas
            $identitas = $this->penugasanRepository->findIdentitasByPenugasanId($penugasan->id);
            if ($identitas) {
                $this->penugasanRepository->upsertIdentitas($penugasan->id, [
                    'visi'          => $validated['visi']          ?? $identitas->visi,
                    'misi'          => $validated['misi']          ?? $identitas->misi,
                    'jml_fakultas'  => $validated['jml_fakultas']  ?? $identitas->jml_fakultas,
                    'jml_prodi'     => $validated['jml_prodi']     ?? $identitas->jml_prodi,
                    'jml_dosen'     => $validated['jml_dosen']     ?? $identitas->jml_dosen,
                    'jml_tendik'    => $validated['jml_tendik']    ?? $identitas->jml_tendik,
                    'jml_mahasiswa' => $validated['jml_mahasiswa'] ?? $identitas->jml_mahasiswa,
                    'jml_ormawa'    => $validated['jml_ormawa']    ?? $identitas->jml_ormawa,
                    'jml_ukm'       => $validated['jml_ukm']       ?? $identitas->jml_ukm,
                ]);

                // 3. Update demografi agama (upsert per agama)
                if (!empty($validated['agamas']) && is_array($validated['agamas'])) {
                    foreach ($validated['agamas'] as $agamaKey => $jumlah) {
                        $this->penugasanRepository->upsertAgama($identitas->id, $agamaKey, (int) ($jumlah ?: 0));
                    }
                }
            }

            $freshPenugasan = $this->penugasanRepository->find($penugasan->id);
            $freshUser = $this->userRepository->find($userId);

            return $this->successResponse([
                'nama_pic'    => $freshPenugasan->nama_pic,
                'jabatan_pic' => $freshPenugasan->jabatan_pic,
                'no_hp_pic'   => $freshPenugasan->no_hp_pic,
                'email'       => $freshUser->email,
            ], 'Profil berhasil diperbarui.', 200);
        } catch (\Throwable $th) {
            $code = (is_numeric($th->getCode()) && $th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
            return $this->errorResponse($th->getMessage(), $code);
        }
    }

    /**
     * Helper internal untuk mem-build DTO dengan konteks User Auth yang aman.
     */
    public function getAuthDTO(): PenugasanDTO
    {
        $userId = $this->getAuth();
        return new PenugasanDTO($userId);
    }
}
