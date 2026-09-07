<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
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
        ];
    }
}
