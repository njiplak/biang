<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AddonPrice extends Model
{
    /** @use HasFactory<\Database\Factories\AddonPriceFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid', 'addon_id', 'billing_interval', 'currency',
        'amount_minor', 'dodo_product_id', 'dodo_addon_id', 'archived_at',
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

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
