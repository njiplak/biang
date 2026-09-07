<?php

use App\Http\Middleware\ResolveWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;

function runMiddleware(?User $user, array $session = []): void
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    foreach ($session as $key => $value) {
        $request->session()->put($key, $value);
    }

    $request->setUserResolver(fn () => $user);

    app(ResolveWorkspace::class)->handle($request, fn () => response('ok'));
}

it('leaves the context empty for a guest', function () {
    runMiddleware(null);

    expect(app(CurrentWorkspace::class)->has())->toBeFalse();
});

it('resolves the workspace the user last selected', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->for($user)->create();
    $user->update(['current_workspace_id' => $ws->id]);

    runMiddleware($user->fresh());

    expect(app(CurrentWorkspace::class)->id())->toBe($ws->id);
});

it('prefers an explicitly switched workspace from the session', function () {
    $user = User::factory()->create();
    $last = Workspace::factory()->create();
    $switched = Workspace::factory()->create();
    WorkspaceMember::factory()->for($last)->for($user)->create();
    WorkspaceMember::factory()->for($switched)->for($user)->create();
    $user->update(['current_workspace_id' => $last->id]);

    runMiddleware($user->fresh(), ['current_workspace_id' => $switched->id]);

    expect(app(CurrentWorkspace::class)->id())->toBe($switched->id);
});

// The security-critical case. A session value is user-controlled, so membership
// has to be re-checked on every request - otherwise switching workspace is a
// horizontal privilege escalation.
it('refuses a workspace the user does not belong to', function () {
    $user = User::factory()->create();
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    WorkspaceMember::factory()->for($mine)->for($user)->create();

    runMiddleware($user->fresh(), ['current_workspace_id' => $theirs->id]);

    expect(app(CurrentWorkspace::class)->id())->toBe($mine->id);
});

it('ignores a stale workspace id that no longer exists', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->for($user)->create();

    runMiddleware($user->fresh(), ['current_workspace_id' => 999999]);

    expect(app(CurrentWorkspace::class)->id())->toBe($ws->id);
});

it('falls back to the only membership when nothing is selected', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->for($user)->create();

    runMiddleware($user->fresh());

    expect(app(CurrentWorkspace::class)->id())->toBe($ws->id);
});

it('leaves the context empty for a user with no workspaces', function () {
    runMiddleware(User::factory()->create());

    expect(app(CurrentWorkspace::class)->has())->toBeFalse();
});

// A deleted workspace must not become the ambient context.
it('skips a soft deleted workspace', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->for($user)->create();
    $user->update(['current_workspace_id' => $ws->id]);
    $ws->delete();

    runMiddleware($user->fresh());

    expect(app(CurrentWorkspace::class)->has())->toBeFalse();
});

// Regression: the middleware runs on every web request, including the admin
// console. Resolving the DEFAULT guard there returns an AdminUser, which has no
// workspaces() relation - it fatalled on every admin page.
it('ignores an admin session and never treats staff as a workspace member', function () {
    $admin = App\Models\AdminUser::factory()->create();
    $customer = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($customer)->create();
    $customer->update(['current_workspace_id' => $workspace->id]);

    $this->withoutVite()->actingAs($admin, 'admin');

    $this->get('/admin')->assertOk();

    expect(app(CurrentWorkspace::class)->has())->toBeFalse();
});
