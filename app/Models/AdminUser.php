<?php

namespace App\Models;

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
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $admin) {
            $admin->ulid ??= (string) Str::ulid();
        });
    }

    public function impersonationSessions()
    {
        return $this->hasMany(ImpersonationSession::class);
    }
}
