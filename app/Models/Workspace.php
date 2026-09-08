<?php

namespace App\Models;

use App\Enums\AccessStatus;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceDisplayState;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceFactory> */
    use HasFactory, SoftDeletes;

    /** URLs expose the ULID, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected $fillable = [
        'ulid',
        'slug',
        'name',
        'billing_status',
        'access_status',
        'over_limit_at',
        'over_limit_features',
        'suspended_at',
        'suspension_reason',
        'suspended_by_admin_id',
        'grace_ends_at',
        'dodo_customer_id',
        'settings',
        'purge_after',
        'anonymized_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_status' => BillingStatus::class,
            'access_status' => AccessStatus::class,
            'over_limit_at' => 'datetime',
            'over_limit_features' => 'array',
            'suspended_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'settings' => 'array',
            'purge_after' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $workspace) {
            $workspace->ulid ??= (string) Str::ulid();
        });
    }

    // ---------------------------------------------------------------- state

    /**
     * Collapse the two axes into section 6's single state.
     *
     * Precedence matters and is not arbitrary: over limit beats past due
     * because section 7's hard block is stricter than section 9's deliberate
     * "past due keeps full access". Getting this backwards lets an unpaid,
     * over-limit workspace keep writing.
     */
    public function displayState(): WorkspaceDisplayState
    {
        if ($this->trashed() || $this->access_status === AccessStatus::Deleted) {
            return WorkspaceDisplayState::Deleted;
        }

        if ($this->access_status === AccessStatus::Suspended) {
            return WorkspaceDisplayState::Suspended;
        }

        /*
         * Above the limit check, and that order matters as much as the one
         * below it. An expired workspace cannot write AT ALL, so telling the
         * customer they are over a seat limit would name a problem that is not
         * theirs to fix and hide the one that is - nobody is paying.
         */
        if (in_array($this->billing_status, [BillingStatus::Unpaid, BillingStatus::Canceled], true)) {
            return WorkspaceDisplayState::Expired;
        }

        if ($this->isOverLimit()) {
            return WorkspaceDisplayState::OverLimit;
        }

        return match ($this->billing_status) {
            BillingStatus::Trialing => WorkspaceDisplayState::Trialing,
            BillingStatus::Active => WorkspaceDisplayState::Active,
            BillingStatus::PastDue => WorkspaceDisplayState::PastDue,
            BillingStatus::Unpaid, BillingStatus::Canceled => WorkspaceDisplayState::Expired,
        };
    }

    public function isOverLimit(): bool
    {
        return $this->over_limit_at !== null;
    }

    public function canLogIn(): bool
    {
        return $this->displayState() !== WorkspaceDisplayState::Deleted;
    }

    public function canRead(): bool
    {
        return $this->displayState() !== WorkspaceDisplayState::Deleted;
    }

    /**
     * There is no free tier, so writing is something a live plan buys.
     *
     * Expired is deliberately absent: a workspace nobody is paying for keeps
     * everything it has and keeps it readable forever, but it cannot be added
     * to. Past due is deliberately present - section 9 keeps full access while
     * a card is being fixed, because a card problem is not misuse.
     */
    public function canWrite(): bool
    {
        return in_array($this->displayState(), [
            WorkspaceDisplayState::Trialing,
            WorkspaceDisplayState::Active,
            WorkspaceDisplayState::PastDue,
        ], true);
    }

    /** Section 6: a suspended workspace can still export everything. */
    public function canExport(): bool
    {
        return $this->displayState() !== WorkspaceDisplayState::Deleted;
    }

    // ----------------------------------------------------------------- seats

    /**
     * Section 7: a pending invitation reserves a seat. Counting only accepted
     * members would let ten pending invites pass a five seat check and blow
     * past the limit the moment they are accepted.
     */
    public function seatsUsed(): int
    {
        return $this->members()->count() + $this->invitations()->pending()->count();
    }

    // ------------------------------------------------------------- relations

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function owners(): HasMany
    {
        return $this->members()->where('role', WorkspaceRole::Owner);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The one live subscription, if any. Section 12: at most one. */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', array_column(SubscriptionStatus::live(), 'value'));
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(WorkspaceEntitlement::class);
    }

    public function entitlementOverrides(): HasMany
    {
        return $this->hasMany(WorkspaceEntitlementOverride::class);
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function invoiceSummaries(): HasMany
    {
        return $this->hasMany(InvoiceSummary::class);
    }

    public function suspendedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'suspended_by_admin_id');
    }
}
