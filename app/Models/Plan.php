<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid', 'code', 'name', 'description',
        'is_public', 'is_free', 'sort_order', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_free' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $plan) => $plan->ulid ??= (string) Str::ulid());
    }

    /** Section 10: a retired plan keeps working for customers already on it. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->active()->where('is_public', true);
    }

    public function activePriceFor(BillingInterval $interval, string $currency): ?PlanPrice
    {
        return $this->prices()
            ->whereNull('archived_at')
            ->where('billing_interval', $interval)
            ->where('currency', $currency)
            ->first();
    }

    /** Null means unlimited, and also means "this plan does not define it". */
    public function limitFor(string $featureKey): ?int
    {
        $feature = $this->features->firstWhere('key', $featureKey);

        return $feature?->pivot->value === null ? null : (int) $feature->pivot->value;
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'plan_addons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
