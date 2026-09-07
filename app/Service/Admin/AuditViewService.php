<?php

namespace App\Service\Admin;

use App\Contract\Admin\AuditViewContract;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AuditViewService implements AuditViewContract
{
    public function search(?string $term, ?string $action, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->when(filled($action), fn (Builder $query) => $query->where('action', $action))
            ->when(filled($term), fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->whereLike('action', "%{$term}%")
                    // Searching by customer is how support actually arrives
                    // here: they have a workspace, not an action name.
                    ->orWhereHas('workspace', fn (Builder $w) => $w
                        ->whereLike('name', "%{$term}%")
                        ->orWhereLike('ulid', "%{$term}%"))
            ))
            ->with(['workspace', 'actor', 'impersonationSession'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->actor?->name,
            // Staff and customers both write here. Which side acted is the
            // first thing a reader needs, and the class name is the only
            // reliable answer - names collide.
            'actor_kind' => match ($log->actor_type) {
                AdminUser::class => 'staff',
                null => 'system',
                default => 'customer',
            },
            'workspace_name' => $log->workspace?->name,
            'workspace_ulid' => $log->workspace?->ulid,
            'subject' => $log->subject_type === null
                ? null
                : class_basename($log->subject_type).' #'.$log->subject_id,
            'changes' => $log->changes,
            // Section 10: this is what keeps "the customer did this" separable
            // from "we did this as them".
            'via_impersonation' => $log->impersonation_session_id !== null,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
        ];
    }

    public function impersonations(?Workspace $workspace, int $limit = 50): array
    {
        return ImpersonationSession::query()
            ->when($workspace !== null, fn (Builder $query) => $query->where('workspace_id', $workspace->id))
            ->with(['adminUser', 'user', 'workspace'])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (ImpersonationSession $session) => [
                'id' => $session->id,
                'admin' => $session->adminUser?->name,
                'user' => $session->user?->name,
                'user_email' => $session->user?->email,
                'workspace_name' => $session->workspace?->name,
                'workspace_ulid' => $session->workspace?->ulid,
                'reason' => $session->reason,
                'ticket_reference' => $session->ticket_reference,
                'started_at' => $session->started_at,
                'ended_at' => $session->ended_at,
                // An open session is somebody inside a customer account right
                // now, which is the one row worth reacting to.
                'is_active' => $session->isActive(),
                'ip_address' => $session->ip_address,
            ])
            ->values()
            ->all();
    }

    public function actions(): array
    {
        return AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }
}
