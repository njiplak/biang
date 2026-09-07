<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'ulid',
        'name',
        'email',
        'password',
        'current_workspace_id',
        'trial_consumed_at',
        'trial_consumed_workspace_id',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_consumed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Section 12: one trial per person, ever - not per workspace. Consumed by
     * the act of STARTING a trial, so a Path C invitee who joins someone
     * else's trialing workspace keeps their own.
     */
    public function hasConsumedTrial(): bool
    {
        return $this->trial_consumed_at !== null;
    }

    /**
     * Section 2: what someone may do is decided entirely by which workspace
     * they are currently looking at, never by who they are.
     */
    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        return $this->memberships()
            ->where('workspace_id', $workspace->id)
            ->first()?->role;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->roleIn($workspace) !== null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }
}
