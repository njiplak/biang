<?php

namespace App\Models;

use App\Models\Concerns\TwoFactorAuthenticatable;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * Platform staff. Section 3: a completely separate login, so a customer account
 * can never reach admin functions.
 *
 * This is where spatie/laravel-permission earns its keep - staff roles ARE
 * runtime-editable, unlike the fixed five workspace roles.
 */
class AdminUser extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\AdminUserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    protected string $guard_name = 'admin';

    protected $fillable = [
        'ulid',
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            // Encrypted at rest, same as the customer table.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $admin) {
            $admin->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * The default sends a link to the customer reset form, which checks tokens
     * against the `users` broker - the wrong table for a staff account.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new AdminPasswordResetNotification($token));
    }

    public function impersonationSessions()
    {
        return $this->hasMany(ImpersonationSession::class);
    }
}
