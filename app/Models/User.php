<?php

namespace App\Models;

use App\Enums\ExperienceLevel;
use App\Enums\Goal;
use App\Enums\Role;
use App\Enums\Sex;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $birth_date
 * @property Sex|null $sex
 * @property string|null $height_cm
 * @property Goal|null $goal
 * @property ExperienceLevel|null $experience_level
 * @property Role $role
 * @property UserStatus $status
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $age
 */
// role e status ficam fora do $fillable de propósito: só o admin muda, explicitamente.
#[Fillable(['name', 'email', 'password', 'birth_date', 'sex', 'height_cm', 'goal', 'experience_level'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'role' => 'user',
        'status' => 'active',
    ];

    protected $appends = ['age'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'birth_date' => 'date:Y-m-d',
            'height_cm' => 'decimal:1',
            'sex' => Sex::class,
            'goal' => Goal::class,
            'experience_level' => ExperienceLevel::class,
            'role' => Role::class,
            'status' => UserStatus::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isBlocked(): bool
    {
        return $this->status === UserStatus::Blocked;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    /** Só contas aprovadas e não bloqueadas podem usar o app. */
    public function canSignIn(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Idade calculada a partir da data de nascimento. */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }
}
