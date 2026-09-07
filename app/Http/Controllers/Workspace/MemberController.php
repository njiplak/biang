<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceMemberContract;
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
            relation: ['user'],
            perPage: (int) request()->get('per_page', 10),
        );

        if ($data instanceof \Throwable) {
            return response()->json(['message' => $data->getMessage()], 400);
        }

        return response()->json($data);
    }

    public function index(Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        return Inertia::render('workspace/members/index', [
            'workspace' => $workspace->only(['ulid', 'name', 'slug']),
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
            // the priced offer. Null on the free tier, where the answer is an
            // upgrade rather than an add-on (section 12).
            'seat_offer' => $this->seatOffer($workspace),
            // Inviting is gated on a verified address (routes/web/workspace.php),
            // so the form has to know - otherwise someone who has just changed
            // their email types out an invitation and is bounced to the notice
            // page with no idea why.
            'must_verify_email' => ! request()->user()->hasVerifiedEmail(),
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

        $this->service->changeRole($member, $request->role());

        return back();
    }

    public function destroy(WorkspaceMember $member): RedirectResponse
    {
        Gate::authorize('delete', $member);

        $this->service->remove($member);

        return back();
    }
}
