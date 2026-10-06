<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\TwoFactorAuthenticatable;
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
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'ulid',
        'name',
        'email',
        'password',
        'current_workspace_id',
        'trial_consumed_at',
        'trial_consumed_workspace_id',
        'trial_checkout_at',
        'trial_checkout_workspace_id',
        'last_seen_at',
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_consumed_at' => 'datetime',
            'trial_checkout_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            // Encrypted at rest: a leaked backup must not hand over the seeds
            // needed to generate working codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
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
     * How long a trial checkout holds this person's one trial before the claim
     * lapses.
     *
     * Long enough for a card form plus the webhook that follows it, short
     * enough that somebody who changed their mind is not locked out of
     * trialling a different workspace for the rest of the day.
     */
    public const TRIAL_CHECKOUT_MINUTES = 30;

    /**
     * Whether this person has a trial checkout in flight for some OTHER
     * workspace.
     *
     * Section 12's rule is one trial per person, ever, but hasConsumedTrial()
     * cannot answer it on its own: consumption is recorded by Dodo's webhook,
     * and until that lands the answer is false. Two tabs on two workspaces both
     * passed and both got fourteen free days on one card.
     *
     * Scoped to a DIFFERENT workspace on purpose. Somebody who abandoned a
     * checkout and is trying again on the same workspace is doing nothing
     * wrong, and must never be locked out of their own retry.
     */
    public function hasTrialCheckoutPendingElsewhere(Workspace $workspace): bool
    {
        return $this->trial_checkout_at !== null
            && $this->trial_checkout_at->gt(now()->subMinutes(self::TRIAL_CHECKOUT_MINUTES))
            && $this->trial_checkout_workspace_id !== null
            && $this->trial_checkout_workspace_id !== $workspace->id;
    }

    /** Stake the claim, at the moment the card form is handed over. */
    public function rememberTrialCheckout(Workspace $workspace): void
    {
        $this->update([
            'trial_checkout_at' => now(),
            'trial_checkout_workspace_id' => $workspace->id,
        ]);
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

    /**
     * Workspaces this person owns, closed ones included until they are
     * anonymised - a closed workspace can still be restored, and restoring must
     * not take them past config('workspace.max_owned').
     */
    public function ownedWorkspaceCount(): int
    {
        return Workspace::withTrashed()
            ->whereNull('anonymized_at')
            ->whereIn('id', $this->memberships()->where('role', WorkspaceRole::Owner)->select('workspace_id'))
            ->count();
    }

    /** One customer, one workspace for now (config workspace.max_owned). */
    public function canCreateWorkspace(): bool
    {
        return $this->ownedWorkspaceCount() < (int) config('workspace.max_owned');
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
