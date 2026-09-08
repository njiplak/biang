<?php

namespace App\Http\Middleware;

use App\Contract\Admin\AnnouncementContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use App\Support\SiteSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Both guards are resolved EXPLICITLY. Using the default guard here
        // would hand an AdminUser to customer pages on any admin route, which
        // is exactly the confusion section 3 exists to prevent.
        $user = $request->user('web');
        $admin = $request->user('admin');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'admin' => $admin,
                'permissions' => ($admin ?? $user)?->getPermissionsViaRoles()->pluck('name')->toArray() ?? [],
            ],
            // Named `tenancy`, not `workspace(s)`, on purpose: a page prop of
            // the same name silently overrides a shared one, and pages
            // legitimately want both `workspace` and `workspaces`.
            'tenancy' => $this->workspaceContext($user),
            // Section 10: "with an obvious banner saying so". Shared rather than
            // per-page because there is no page where it would be acceptable to
            // forget we are inside someone else's account.
            'impersonation' => $this->impersonationContext($request),
            // Section 10: "Announce maintenance or a new feature to all
            // customers." Shared because maintenance is not news that should
            // wait until they happen to open the right page.
            'announcements' => $user === null
                ? []
                : app(AnnouncementContract::class)->forUser($user, app(CurrentWorkspace::class)->get()),
            /*
             * One-off notices raised on a request that then redirects - a
             * refused trial, a card form that could not be opened, a payment we
             * could not match. The page that finally renders is never the page
             * that knows about them, and a message nobody sees is the same as
             * no message at all.
             */
            'flash' => [
                'warning' => $request->hasSession() ? $request->session()->get('warning') : null,
            ],
            /*
             * Section 6 tells a suspended customer to "contact support", and
             * until this there was nothing to click. Shared rather than passed
             * per page because the banner that needs it lives in the layout,
             * so every customer screen can raise it.
             *
             * Only for signed-in customers: a guest has no banner to act on,
             * and staff have the console.
             */
            'support' => $user === null
                ? null
                : ['url' => SiteSettings::supportUrl()],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array<string, mixed>|null */
    private function impersonationContext(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(ImpersonationController::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $session = ImpersonationSession::with(['adminUser', 'user'])->find($id);

        // A row that has been closed from elsewhere leaves a stale key behind.
        // Treating that as "not impersonating" is the safe way round: the worst
        // case is a banner that disappears, not one that never appears.
        if ($session === null || ! $session->isActive()) {
            return null;
        }

        return [
            'admin_name' => $session->adminUser?->name,
            'user_name' => $session->user?->name,
            'user_email' => $session->user?->email,
            'reason' => $session->reason,
            'started_at' => $session->started_at,
        ];
    }

    /**
     * Section 2: the app always shows a workspace switcher, so the list of
     * workspaces and the current one's state travel with every page rather
     * than being re-fetched per screen.
     *
     * @return array<string, mixed>|null
     */
    private function workspaceContext(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $current = app(CurrentWorkspace::class)->get();

        return [
            'current' => $current === null ? null : $this->currentPayload($current),
            // Reads across every workspace: the switcher is the reason
            // workspace_members is deliberately never tenant-scoped.
            'available' => $user->memberships()->with('workspace')->get()
                ->filter(fn (WorkspaceMember $m) => $m->workspace !== null)
                ->map(fn (WorkspaceMember $m) => [
                    'ulid' => $m->workspace->ulid,
                    'name' => $m->workspace->name,
                    'role' => $m->role->value,
                    'role_label' => $m->role->label(),
                    'state' => $m->workspace->displayState()->value,
                    'state_label' => $m->workspace->displayState()->label(),
                ])
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function currentPayload(Workspace $workspace): array
    {
        $state = $workspace->displayState();
        $subscription = $workspace->subscription()->first();

        return [
            'ulid' => $workspace->ulid,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'state' => $state->value,
            'state_label' => $state->label(),
            // Everything the banners in section 6 and 9 need to say something
            // specific rather than "something is wrong".
            'can_write' => $workspace->canWrite(),
            'can_export' => $workspace->canExport(),
            'over_limit_features' => $workspace->over_limit_features,
            'grace_ends_at' => $workspace->grace_ends_at,
            'trial_ends_at' => $subscription?->trial_ends_at,
            /*
             * Counted here rather than in the banner. The countdown needs "now",
             * and reading the clock while React renders is impure - the same
             * component can render twice and disagree with itself. The server
             * already knows the date, so it can just answer the question.
             */
            'trial_days_left' => $subscription?->trial_ends_at === null
                ? null
                : max(0, (int) ceil(now()->diffInDays($subscription->trial_ends_at, false))),
        ];
    }
}
