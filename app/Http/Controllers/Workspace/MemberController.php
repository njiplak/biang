<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Admin\AuditContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceMemberContract;
use App\Enums\WorkspaceRole;
use App\Exports\WorkspaceMembersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\MemberRoleRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MemberController extends Controller
{
    public function __construct(
        private readonly MembershipContract $service,
        private readonly EntitlementContract $entitlements,
        private readonly WorkspaceMemberContract $members,
        private readonly InvitationContract $invitations,
        /*
         * Section 10 keeps a permanent record of what STAFF did, and support is
         * asked the same question about customers: who changed this, and when.
         * AuditLogger already falls back to the web guard and tags anything
         * done through impersonation, so recording a customer action needs
         * nothing more than asking.
         */
        private readonly AuditContract $audit,
    ) {}

    /**
     * JSON feed for NextTable. Scoped to the workspace by condition rather than
     * by the tenancy global scope, because workspace_members is one of the
     * tables that DEFINES tenancy and is therefore never auto-scoped.
     */
    public function fetch(Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $data = $this->members->getWithCondition(
            conditions: ['workspace_id', $workspace->id],
            allowedFilters: ['role'],
            allowedSorts: ['id', 'role', 'joined_at'],
            withPaginate: true,
            // Named columns, not the whole row. A colleague's pending email
            // address, trial history and whether they have a second factor set
            // up are none of a workspace Viewer's business.
            relation: ['user:id,name,email'],
            // BaseService::resolvePerPage caps this. It matters more here than
            // on other tables: withRowAbilities() below asks the policies a
            // question per row, so an unbounded page multiplies the queries.
            perPage: (int) request()->get('per_page', 10),
        );

        if ($data instanceof \Throwable) {
            return response()->json(['message' => $data->getMessage()], 400);
        }

        return response()->json($this->withRowAbilities($data, $workspace));
    }

    /**
     * Answer, per row, the questions the table's controls ask.
     *
     * Asked of the policies rather than recomputed here, because the rules are
     * not simple enough to restate safely: the last owner may be neither
     * demoted nor removed, anyone may remove themselves, and only an owner may
     * mint another owner. A second copy of that in the controller - or worse in
     * TypeScript - is a copy that drifts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRowAbilities(array $data, Workspace $workspace): array
    {
        foreach ($data['items'] as $member) {
            // Already loaded and already authorised. Without this every policy
            // call below lazy-loads the same workspace row again, per member.
            $member->setRelation('workspace', $workspace);

            $member->can_change_role = Gate::allows('update', $member);
            /*
             * Only asked when there is a picker to put the option in. Not a
             * shortcut through the policy - a role that cannot be changed at
             * all offers no roles, owner included.
             */
            $member->can_assign_owner = $member->can_change_role
                && Gate::allows('assignRole', [$member, WorkspaceRole::Owner]);
            $member->can_remove = Gate::allows('delete', $member);
        }

        return $data;
    }

    public function index(Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        return Inertia::render('workspace/members/index', [
            'workspace' => $workspace->only(['ulid', 'name', 'slug']),
            /*
             * The page-wide half of the same question the rows ask. Both
             * policies already refuse these; sending the answer ahead of the
             * click is what stops the screen offering a Viewer an invite form
             * that can only ever come back 403.
             */
            'can' => [
                'invite' => Gate::allows('inviteMembers', $workspace),
                'invite_blocked_by' => $this->inviteBlockedBy($workspace),
                // Section 6 keeps export available to a suspended or
                // over-limit workspace, so this follows canExport, not write.
                'export' => $workspace->canExport(),
            ],
            'invitations' => $workspace->invitations()->pending()->get()
                ->map(fn ($invitation) => [
                    'id' => $invitation->ulid,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at,
                ]),
            // Section 7: the seat position is shown up front so hitting the
            // limit is never a surprise.
            'seats' => [
                'used' => $workspace->seatsUsed(),
                'limit' => $this->entitlements->limitFor($workspace, Features::SEATS),
            ],
            // Section 7: a seat limit is a sales moment, so the page carries
            // the priced offer. Null without a subscription, where the answer is an
            // upgrade rather than an add-on (section 12).
            'seat_offer' => $this->seatOffer($workspace),
        ]);
    }

    /**
     * Gated on `view`, not `write`. Section 6 keeps export available to a
     * suspended or over-limit workspace on purpose - refusing to hand back
     * their own data because they are over a limit would be indefensible.
     */
    public function export(Workspace $workspace): BinaryFileResponse
    {
        Gate::authorize('view', $workspace);

        abort_unless($workspace->canExport(), 403);

        return Excel::download(
            new WorkspaceMembersExport($workspace),
            Str::slug($workspace->name).'-members.xlsx',
        );
    }

    /**
     * Which half of `inviteMembers` said no - the person's role, or the
     * workspace's state.
     *
     * Both end in the same missing form, and the page has to name the right
     * one: "ask an owner" and "nobody is paying for this workspace" send the
     * customer to different people. Role wins when both apply, because a
     * viewer who is told to choose a plan is being pointed at a billing page
     * their role cannot open either.
     *
     * @return 'role'|'state'|null null when nothing is blocking
     */
    private function inviteBlockedBy(Workspace $workspace): ?string
    {
        if (Gate::allows('inviteMembers', $workspace)) {
            return null;
        }

        return request()->user()->roleIn($workspace)?->canManageMembers() === true
            ? 'state'
            : 'role';
    }

    /** @return array<string, mixed>|null */
    private function seatOffer(Workspace $workspace): ?array
    {
        $price = $this->invitations->seatAddonPrice($workspace);

        if ($price === null) {
            return null;
        }

        return [
            'name' => $price->addon->name,
            'amount_minor' => $price->amount_minor,
            'currency' => $price->currency,
            'interval' => $price->billing_interval->value,
        ];
    }

    public function update(MemberRoleRequest $request, WorkspaceMember $member): RedirectResponse
    {
        // assignRole covers both halves: may this actor edit this membership at
        // all, and may they hand out THIS role in particular.
        Gate::authorize('assignRole', [$member, $request->role()]);

        // Read before the change, or there is nothing left to compare against.
        $from = $member->role;

        $this->service->changeRole($member, $request->role());

        $this->audit->record('workspace.member_role_changed', $member->workspace, $member, [
            'from' => $from->value,
            'to' => $request->role()->value,
        ]);

        return back();
    }

    public function destroy(WorkspaceMember $member): RedirectResponse
    {
        Gate::authorize('delete', $member);

        // Held before remove() deletes the row and the relation goes with it.
        $workspace = $member->workspace;

        $this->service->remove($member);

        /*
         * The membership row is the subject, and it carries the user_id -
         * enough to answer "who was removed" without copying an email address
         * into a log that outlives the account.
         */
        $this->audit->record('workspace.member_removed', $workspace, $member);

        return back();
    }
}
