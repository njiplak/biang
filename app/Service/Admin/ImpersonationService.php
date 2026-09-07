<?php

namespace App\Service\Admin;

use App\Contract\Admin\ImpersonationContract;
use App\Exceptions\Domain\AlreadyImpersonating;
use App\Exceptions\Domain\CannotImpersonate;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class ImpersonationService implements ImpersonationContract
{
    public function start(
        AdminUser $admin,
        User $user,
        Workspace $workspace,
        string $reason,
        ?string $ticketReference = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ImpersonationSession {
        return DB::transaction(function () use ($admin, $user, $workspace, $reason, $ticketReference, $ip, $userAgent) {
            // Checked here as well as by the partial unique index, so the staff
            // member gets a sentence rather than a constraint violation.
            if ($this->open($admin) !== null) {
                throw new AlreadyImpersonating($admin);
            }

            // Section 2: what someone may do is decided by which workspace they
            // are looking at. Entering as a person who is not in the workspace
            // would put them somewhere they have no role, and every policy
            // downstream would be answering a question that makes no sense.
            if (! $user->belongsToWorkspace($workspace)) {
                throw new CannotImpersonate('that person is not a member of this workspace');
            }

            // Section 6: a deleted workspace cannot be signed into at all, so we
            // cannot honestly enter it "as them" either.
            if (! $workspace->canLogIn()) {
                throw new CannotImpersonate('the workspace is closed');
            }

            $session = ImpersonationSession::create([
                'admin_user_id' => $admin->id,
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'reason' => $reason,
                'ticket_reference' => $ticketReference,
                'started_at' => now(),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            $this->record($session, 'impersonation.started');

            return $session;
        });
    }

    public function stop(ImpersonationSession $session): ImpersonationSession
    {
        return DB::transaction(function () use ($session) {
            // Idempotent on purpose: the banner button, the admin console and a
            // second tab can all arrive here, and re-closing must not move the
            // recorded end time.
            if ($session->ended_at !== null) {
                return $session;
            }

            $session->update(['ended_at' => now()]);
            $this->record($session, 'impersonation.ended');

            return $session->refresh();
        });
    }

    public function open(AdminUser $admin): ?ImpersonationSession
    {
        return ImpersonationSession::query()
            ->where('admin_user_id', $admin->id)
            ->open()
            ->with(['user', 'workspace'])
            ->first();
    }

    /**
     * The session row is the record staff read; this is the one the workspace's
     * own history reads, so a customer asking "who was in my account on the
     * 3rd" is answerable from their timeline rather than ours.
     *
     * Written here rather than through AuditContract because the session id has
     * to be on the row, and at `start` time it is not in the HTTP session yet -
     * the controller puts it there after this returns.
     */
    private function record(ImpersonationSession $session, string $action): void
    {
        AuditLog::create([
            'workspace_id' => $session->workspace_id,
            'actor_type' => AdminUser::class,
            'actor_id' => $session->admin_user_id,
            'impersonation_session_id' => $session->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $session->user_id,
            'changes' => ['reason' => $session->reason, 'ticket' => $session->ticket_reference],
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'created_at' => now(),
        ]);
    }
}
