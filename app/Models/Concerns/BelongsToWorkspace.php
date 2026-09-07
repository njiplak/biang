<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level tenancy for models whose workspace_id is NOT NULL.
 *
 * Two behaviours, and both matter: reads are constrained to the current
 * workspace, and writes are stamped with it. Without the second, a correctly
 * scoped read is followed by an unscoped insert and the row goes nowhere
 * useful - or worse, somewhere wrong.
 *
 * Tables that DEFINE tenancy (workspace_members, workspace_invitations) and
 * central intake (webhook_events) deliberately do not use this. See
 * tests/Feature/Tenancy/TenancyConsistencyTest.php, which fails if a new table
 * carries workspace_id without being classified either way.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $current = app(CurrentWorkspace::class);

            if ($current->has()) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('workspace_id'),
                    $current->id()
                );
            }
        });

        static::creating(function (Model $model): void {
            $current = app(CurrentWorkspace::class);

            // Never overwrite an explicit value: system processes and the admin
            // console write on behalf of a workspace that is not the current one.
            if ($current->has() && $model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', $current->id());
            }
        });
    }

    /** The explicit, greppable escape hatch. */
    public function scopeWithoutWorkspaceScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('workspace');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
