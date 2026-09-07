<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WorkspaceInvitation extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceInvitationFactory> */
    use HasFactory;

    /** URLs expose the ULID, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected $fillable = [
        'ulid',
        'workspace_id',
        'email',
        'role',
        'token_hash',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'last_sent_at',
        'send_count',
    ];

    protected $hidden = ['token_hash'];

    /**
     * The plaintext token, held in memory only for the request that issued it.
     * Never persisted and never serialised - the database keeps the hash, and
     * the plaintext exists just long enough to be put in an email.
     */
    public ?string $plainToken = null;

    public function withPlainToken(string $token): static
    {
        $this->plainToken = $token;

        return $this;
    }

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation) {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Pending means it still reserves a seat (section 7): not accepted, not
     * revoked, not yet expired.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
