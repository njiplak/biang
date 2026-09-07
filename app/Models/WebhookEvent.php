<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central: a webhook arrives with no tenant context, so workspace_id is
 * resolved after parsing. unique(provider, event_id) gives idempotency,
 * because webhooks are redelivered by design.
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookEventFactory> */
    use HasFactory;

    protected $fillable = [
        'provider', 'event_id', 'event_type', 'payload', 'signature_verified',
        'occurred_at', 'received_at', 'processed_at', 'failed_at',
        'attempts', 'error', 'workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    /** An unverified event must never be allowed to move billing state. */
    public function isTrustworthy(): bool
    {
        return $this->signature_verified === true;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
