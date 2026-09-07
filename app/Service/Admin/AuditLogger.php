<?php

namespace App\Service\Admin;

use App\Contract\Admin\AuditContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger implements AuditContract
{
    public function __construct(private readonly Request $request) {}

    public function record(
        string $action,
        ?Workspace $workspace = null,
        ?Model $subject = null,
        array $changes = [],
    ): AuditLog {
        $actor = $this->actor();

        return AuditLog::create([
            'workspace_id' => $workspace?->id,
            'actor_type' => $actor === null ? null : $actor::class,
            'actor_id' => $actor?->getKey(),
            // Keeps "the customer did this" separable from "we did this as
            // them" - the reason audit_logs carries this column at all.
            'impersonation_session_id' => $this->impersonationSession(),
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Staff first. On an admin route both guards may hold a session at once,
     * and the person taking the action is the staff member - attributing it to
     * the customer they happen to also be signed in as would be a lie.
     */
    private function actor(): ?Model
    {
        return $this->request->user('admin') ?? $this->request->user('web');
    }

    private function impersonationSession(): ?int
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        return $this->request->session()->get(ImpersonationController::SESSION_KEY);
    }
}
