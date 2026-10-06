<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Immutable once sold. Changing a price INSERTs a new row and archives the old
 * one, so a subscription pointing at the old row is never repriced.
 */
class PlanPrice extends Model
{
    /** @use HasFactory<\Database\Factories\PlanPriceFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid', 'plan_id', 'billing_interval', 'currency',
        'amount_minor', 'dodo_product_id', 'dodo_upgrade_product_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $price) => $price->ulid ??= (string) Str::ulid());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isLifetime(): bool
    {
        return $this->billing_interval === BillingInterval::Lifetime;
    }

    /**
     * On sale at Dodo. A lifetime price is two products there - the purchase
     * and the upgrade a lower lifetime tier pays the difference through - and
     * is not fully on sale until both exist.
     */
    public function isPublished(): bool
    {
        return filled($this->dodo_product_id)
            && (! $this->isLifetime() || filled($this->dodo_upgrade_product_id));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
