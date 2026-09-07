<?php

namespace App\Models;

use App\Enums\AddonKind;
use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Addon extends Model
{
    /** @use HasFactory<\Database\Factories\AddonFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid', 'key', 'name', 'description', 'kind',
        'feature_id', 'grant_per_unit', 'max_quantity', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AddonKind::class,
            'grant_per_unit' => 'integer',
            'max_quantity' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $addon) => $addon->ulid ??= (string) Str::ulid());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function activePriceFor(BillingInterval $interval, string $currency): ?AddonPrice
    {
        return $this->prices()
            ->whereNull('archived_at')
            ->where('billing_interval', $interval)
            ->where('currency', $currency)
            ->first();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_addons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
