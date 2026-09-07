<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only ledger for metered features. Dodo owns chargebacks (section 8),
 * and a counter cannot be un-aggregated when a bill is disputed.
 */
class UsageRecord extends Model
{
    /** @use HasFactory<\Database\Factories\UsageRecordFactory> */
    use BelongsToWorkspace, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'feature_key', 'quantity', 'occurred_at',
        'idempotency_key', 'metadata', 'reported_at', 'dodo_event_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'reported_at' => 'datetime',
        ];
    }

    public function scopeUnreported(Builder $query): Builder
    {
        return $query->whereNull('reported_at');
    }
}
