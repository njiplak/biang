<?php

namespace App\Models;

use App\Enums\WebhookEventSource;
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
        'provider', 'source', 'event_id', 'event_type', 'payload', 'signature_verified',
        'occurred_at', 'received_at', 'processed_at', 'failed_at',
        'attempts', 'error', 'workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source' => WebhookEventSource::class,
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

    /**
     * An unverified event must never be allowed to move billing state.
     *
     * A PULLED event is trustworthy without a signature, and deliberately so:
     * it is the answer to a request we made to Dodo, so there was no
     * untrusted caller in the path to authenticate. Everything that arrives
     * unsolicited still has to prove itself.
     */
    public function isTrustworthy(): bool
    {
        return $this->signature_verified === true
            || $this->source?->isSelfOriginated() === true;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
