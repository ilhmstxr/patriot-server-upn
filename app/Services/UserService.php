<?php

namespace App\Services;

use App\DTO\AuthDTO\LoginDTO;
use App\DTO\AuthDTO\RegisterDTO;
use App\Models\Institusi;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\EmailVerificationService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;


/**
 * @property \App\Repositories\UserrRepository $repository
 */
class UserService extends BaseService
{
    protected EmailVerificationService $emailVerificationService;

    /**
     * UserService constructor.
     * Otomatis melakukan injection Repository terkait.
     */
    public function __construct(UserRepository $repository, EmailVerificationService $emailVerificationService)
    {
        parent::__construct($repository);
        $this->emailVerificationService = $emailVerificationService;
    }
    public function register(RegisterDTO $dto)
    {
        $user = DB::transaction(function () use ($dto) {
            // 1. Buat User (PIC) melalui Repository
            // Password tidak perlu di-hash manual karena Model User memiliki cast 'hashed'
            $user = $this->repository->createUser([
                'email' => $dto->email,
                'password' => $dto->password,
                'role' => 'PESERTA',
                'status' => 'UNVERIFIED',
            ]);

            // 2. Buat Institusi terkait (simpan domain email untuk enforcement 1-instansi-1-akun)
            $fullDomain = strtolower(substr(strrchr($dto->email, '@') ?: '@', 1));
            $domainParts = explode('.', $fullDomain);
            $domain = count($domainParts) >= 3 ? implode('.', array_slice($domainParts, -3)) : $fullDomain;
            $institusi = $this->repository->createInstitusi([
                'nama_institusi' => $dto->namaPt,
                'jenis_institusi' => $dto->jenisPt,
                'domain_email' => $domain ?: null,
                'logo_url' => $dto->logoUrl ?: 'assets/images/blank-profile-picture-973460_1280.webp',
            ]);

            // 3. Buat Data Penugasan untuk tahun ini (menggunakan active_period dari CMS jika tersedia)
            $activePeriodSetting = \App\Models\PengaturanCms::where('key', 'active_period')->first();
            $activePeriod = $activePeriodSetting ? $activePeriodSetting->value : date('Y');

            $this->repository->createPenugasan([
                'user_id' => $user->id,
                'institution_id' => $institusi->id,
                'tahun_periode' => $activePeriod,
                'status' => 'UNVERIFIED',
                'nama_pic' => $dto->namaPic,
                'jabatan_pic' => $dto->jabatanPic,
                'no_hp_pic' => $dto->noHpPic,
            ]);

            return $user;
        });

        // 4. Kirim email verifikasi SETELAH transaction commit (data sudah tersimpan)
        try {
            $this->emailVerificationService->generateAndSendVerification($user, $dto->namaPt);
        } catch (\Throwable $e) {
            Log::error('Failed to send verification email during registration', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    /**
     * LOGIN: Pengecekan Status & Kredensial.
     *
     * Single-session tetap dijaga: semua token lama dihapus sebelum membuat token baru.
     * Param $remember hanya mempengaruhi durasi token (8 jam vs 30 hari).
     */
    public function login(LoginDTO $dto, bool $remember = false)
    {
        // 1. Cek User berdasarkan email
        $user = $this->repository->findByEmail($dto->email);

        if (!$user || !Hash::check($dto->password, $user->password)) {
            throw new Exception("Email atau password salah.", 401);
        }

        // 3. Invalidate Session Lama (Single Session Policy)
        // Reviewer boleh login di beberapa device sekaligus; role lain (mis. peserta) tetap single session.
        if (strtolower((string) $user->role) !== 'reviewer') {
            $user->tokens()->delete();
        } else {
            // Bersihkan token reviewer yang sudah kedaluwarsa agar tidak menumpuk
            $user->tokens()->where('expires_at', '<', now())->delete();
        }

        // 4. Generate Token dengan masa berlaku berbeda berdasarkan flag "Ingat saya"
        $expiresAt = $remember ? now()->addDays(30) : now()->addHours(8);
        $token = $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * LOGOUT: Pembersihan Token & Sesi
     */
    public function logout(User $user)
    {
        // Hapus token saat ini
        return $user->currentAccessToken()->delete();
    }
}
