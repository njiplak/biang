<?php

namespace App\Models;

use App\Enums\BillingSource;
use App\Enums\CancellationFeedback;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * OUR subscription state, reconciled from Dodo - not a mirror of theirs.
 * Section 8: our records are the source of truth for access, theirs for money.
 */
class Subscription extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'ulid', 'workspace_id', 'plan_id', 'plan_price_id', 'status',
        'billing_source', 'dodo_subscription_id', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'canceled_at', 'cancellation_feedback', 'cancellation_comment',
        'ended_at', 'provider_event_at',
        'granted_by_admin_id', 'grant_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_source' => BillingSource::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'cancellation_feedback' => CancellationFeedback::class,
            'ended_at' => 'datetime',
            'provider_event_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $sub) => $sub->ulid ??= (string) Str::ulid());
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', array_column(SubscriptionStatus::live(), 'value'));
    }

    /**
     * Section 4 and 16: the trial auto-charges, so the 3-day and 1-day warning
     * emails are a launch blocker. This is the query that drives them.
     */
    public function scopeTrialsEndingBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return $query->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$from, $to]);
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at?->isFuture();
    }

    /**
     * A data-integrity alarm, not a business state. A `manual` subscription is
     * SUPPOSED to have no provider record (section 8: a comped account has no
     * payment behind it); a `dodo` one without an id means the sync broke.
     */
    public function isMissingProviderRecord(): bool
    {
        return $this->billing_source->requiresProviderId()
            && $this->dodo_subscription_id === null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    /**
     * Whether Dodo is holding this subscription, as opposed to a plan a staff
     * member granted by hand (section 10) or a trial with no card behind it.
     *
     * The distinction decides who does the billing: anything true here is
     * theirs to charge, prorate and renew, and ours only to reflect.
     */
    public function isHeldWithProvider(): bool
    {
        return filled($this->dodo_subscription_id);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function dunningStates(): HasMany
    {
        return $this->hasMany(DunningState::class);
    }

    public function invoiceSummaries(): HasMany
    {
        return $this->hasMany(InvoiceSummary::class);
    }

    public function grantedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'granted_by_admin_id');
    }
}
