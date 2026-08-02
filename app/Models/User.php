<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// `role` and `is_test_account` are deliberately NOT fillable: a registration
// payload must never be able to promote itself to operator_admin. They are set
// explicitly (forceFill) from the seeder or an admin action.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles the mobile app knows how to render a dashboard for. */
    public const ROLES = [
        'patient',
        'dependent_tutor',
        'doctor_provider',
        'operator_admin',
        'ambulance_driver',
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
            'is_test_account' => 'boolean',
        ];
    }

    /** Clinical identity behind a staff account, when there is one. */
    public function professional(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** Roles allowed to reach the staff endpoints from the app. */
    public function isStaff(): bool
    {
        return in_array($this->role, ['doctor_provider', 'operator_admin', 'admin', 'professional'], true);
    }

    public function isOperator(): bool
    {
        return in_array($this->role, ['operator_admin', 'admin'], true);
    }

    /**
     * Get the social accounts associated with the user.
     */
    public function socialAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
