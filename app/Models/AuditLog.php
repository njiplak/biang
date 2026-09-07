<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only. The actor is polymorphic across two guards (User and AdminUser),
 * and impersonation_session_id keeps "the customer did this" separable from
 * "we did this as them".
 */
class AuditLog extends Model
{
    /** @use HasFactory<\Database\Factories\AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'actor_type', 'actor_id', 'impersonation_session_id',
        'action', 'subject_type', 'subject_id', 'changes', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function impersonationSession(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class);
    }
}
