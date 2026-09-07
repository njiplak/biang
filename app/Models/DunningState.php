<?php

namespace App\Models;

use App\Enums\DunningResolution;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per failed-payment episode (section 9). */
class DunningState extends Model
{
    /** @use HasFactory<\Database\Factories\DunningStateFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id', 'subscription_id', 'started_at', 'attempt_count',
        'last_attempt_at', 'last_failure_code', 'last_failure_message',
        'grace_ends_at', 'resolved_at', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution' => DunningResolution::class,
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Section 9: when the grace period ends, the workspace goes read-only. */
    public function graceExpired(): bool
    {
        return $this->resolved_at === null && $this->grace_ends_at->isPast();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
