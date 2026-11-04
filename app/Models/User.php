<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Panel;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements HasName, FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function getFilamentName(): string
    {
        return $this->email;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return strtoupper($this->role) === 'ADMIN' && strtoupper($this->status) === 'ACTIVE';
    }



    public function penugasans()
    {
        return $this->hasMany(Penugasan::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->hasOne(Reviewer::class, 'user_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            if (strtoupper($user->role) === 'PESERTA') {
                // Find all penugasans to get the institution IDs
                $institutionIds = \DB::table('penugasans')
                    ->where('user_id', $user->id)
                    ->pluck('institution_id');

                // Delete the institutions (this will cascade delete the penugasans)
                if ($institutionIds->isNotEmpty()) {
                    \App\Models\Institusi::whereIn('id', $institutionIds)->delete();
                }
            }
        });
    }
}
