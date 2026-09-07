<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Send-idempotency ledger, NOT Laravel's `notifications` inbox - MailChannel
 * never writes a row anywhere, and `notifications` has no uniqueness to lean on.
 *
 * unique(dedupe_key) is what makes a re-run of the scheduled command, or a
 * queue retry, physically unable to send a trial warning twice (section 16).
 */
class NotificationLog extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'user_id', 'type', 'dedupe_key', 'channel', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public static function alreadySent(string $dedupeKey): bool
    {
        return static::where('dedupe_key', $dedupeKey)->exists();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
