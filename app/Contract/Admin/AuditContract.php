<?php

namespace App\Contract\Admin;

use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 14 phase 6's audit trail.
 *
 * Deliberately called from controllers rather than from the services: the
 * actor, the request IP and the impersonation session all live in the request,
 * and threading them down through the domain layer would put HTTP concerns in
 * the one place that is currently free of them. The trade is that every staff
 * action has to remember to call this - which is why AuditTrailTest walks the
 * routes rather than trusting the call sites.
 */
interface AuditContract
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $action,
        ?Workspace $workspace = null,
        ?Model $subject = null,
        array $changes = [],
    ): AuditLog;
}
