<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** The example product feature. See App\Service\Project\ProjectService. */
class Project extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['ulid', 'workspace_id', 'name', 'description', 'created_by_user_id'];

    protected static function booted(): void
    {
        static::creating(fn (self $project) => $project->ulid ??= (string) Str::ulid());
    }

    /** URLs expose the ULID, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Unscoped on purpose: binding runs before ResolveWorkspace sets the
     * tenant, so the scope would filter by whatever was set last. The
     * controller checks the project belongs to the current workspace.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::withoutWorkspaceScope()->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
