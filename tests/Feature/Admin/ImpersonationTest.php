<?php

use App\Contract\Admin\ImpersonationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\AlreadyImpersonating;
use App\Exceptions\Domain\CannotImpersonate;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Auth;

/*
 * Section 10: "Reproduce a complaint. Enter a customer's workspace as them,
 * with an obvious banner saying so and a permanent record that we did it."
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->impersonation = app(ImpersonationContract::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->support = AdminUser::factory()->create();
    $this->support->assignRole('support');
});

// ------------------------------------------------------------- the record

it('records who entered, as whom, and why', function () {
    $session = $this->impersonation->start(
        $this->support,
        $this->owner,
        $this->workspace,
        'Reproducing the export bug',
        'SUP-1234',
        '203.0.113.9',
        'Mozilla/5.0',
    );

    expect($session->admin_user_id)->toBe($this->support->id)
        ->and($session->user_id)->toBe($this->owner->id)
        ->and($session->workspace_id)->toBe($this->workspace->id)
        ->and($session->reason)->toBe('Reproducing the export bug')
        ->and($session->ticket_reference)->toBe('SUP-1234')
        ->and($session->ip_address)->toBe('203.0.113.9')
        ->and($session->isActive())->toBeTrue();
});

/*
 * The session row is our record; the audit log is the workspace's, so a
 * customer asking "who was in my account" is answerable from their own
 * timeline.
 */
it('writes the entry to the workspace audit trail', function () {
    $session = $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Support');

    $log = AuditLog::where('action', 'impersonation.started')->firstOrFail();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_type)->toBe(AdminUser::class)
        ->and($log->actor_id)->toBe($this->support->id)
        ->and($log->impersonation_session_id)->toBe($session->id);
});

it('closes the record on stop', function () {
    $session = $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Support');

    $this->impersonation->stop($session);

    expect($session->fresh()->ended_at)->not->toBeNull()
        ->and($session->fresh()->isActive())->toBeFalse()
        ->and(AuditLog::where('action', 'impersonation.ended')->count())->toBe(1);
});

// Two open sessions would make "which customer were we acting as" unanswerable.
it('refuses a second open session for the same staff member', function () {
    $this->impersonation->start($this->support, $this->owner, $this->workspace, 'First');

    expect(fn () => $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Second'))
        ->toThrow(AlreadyImpersonating::class);
});

it('lets the same staff member start again once the first has ended', function () {
    $first = $this->impersonation->start($this->support, $this->owner, $this->workspace, 'First');
    $this->impersonation->stop($first);

    $second = $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Second');

    expect($second->id)->not->toBe($first->id)
        ->and($second->isActive())->toBeTrue();
});

it('does not move the end time when stopped twice', function () {
    $session = $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Support');

    $ended = $this->impersonation->stop($session)->ended_at;
    $this->travel(5)->minutes();
    $again = $this->impersonation->stop($session->fresh())->ended_at;

    expect($again->toDateTimeString())->toBe($ended->toDateTimeString())
        ->and(AuditLog::where('action', 'impersonation.ended')->count())->toBe(1);
});

// ---------------------------------------------------------------- refusals

/*
 * Section 2: what someone may do is decided by the workspace they are looking
 * at. Entering as a person with no role there is a question with no answer.
 */
it('refuses to enter as someone who is not a member', function () {
    $stranger = User::factory()->create();

    expect(fn () => $this->impersonation->start($this->support, $stranger, $this->workspace, 'Why'))
        ->toThrow(CannotImpersonate::class);
});

it('refuses to enter a closed workspace', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);

    expect(fn () => $this->impersonation->start($this->support, $this->owner, $this->workspace->fresh(), 'Why'))
        ->toThrow(CannotImpersonate::class);
});

// ------------------------------------------------------------ the HTTP swap

it('signs the staff member in as the customer and keeps the admin guard', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Reproducing the export bug',
        ])
        ->assertRedirect(route('dashboard'));

    // Both at once: the admin guard is what authorises the way back out.
    expect(Auth::guard('web')->id())->toBe($this->owner->id)
        ->and(Auth::guard('admin')->id())->toBe($this->support->id);

    $this->assertNotNull(session(ImpersonationController::SESSION_KEY));
    // Landed in the workspace we asked for, not ResolveWorkspace's fallback.
    expect(session('current_workspace_id'))->toBe($this->workspace->id);
});

it('shows the banner on every customer page while impersonating', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Reproducing the export bug',
        ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('impersonation.user_email', $this->owner->email)
            ->where('impersonation.admin_name', $this->support->name)
            ->where('impersonation.reason', 'Reproducing the export bug'));
});

/*
 * The swap has to be complete, not cosmetic. Section 2 decides what someone may
 * do from the workspace they are looking at, so if the tenancy context were
 * still the staff member's, every policy on the page would be answering about
 * the wrong workspace while the banner claimed otherwise.
 */
it('puts the customer workspace in the tenancy context, not the staff member', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Support',
        ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', $this->owner->email)
            ->where('tenancy.current.name', 'Acme Inc')
            // The admin guard is still held, deliberately - it authorises the
            // way back out. The customer shell never renders it, but it being
            // present is what separates "staff viewing as them" from the
            // customer's own session.
            ->where('auth.admin.email', $this->support->email));
});

it('shares no impersonation context for an ordinary customer', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('impersonation', null));
});

it('signs back out of the customer account on stop', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Support',
        ]);

    $this->post(route('admin.impersonation.stop'))
        ->assertRedirect(route('admin.customer.show', $this->workspace));

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('admin')->id())->toBe($this->support->id)
        ->and(session(ImpersonationController::SESSION_KEY))->toBeNull()
        ->and(ImpersonationSession::whereNull('ended_at')->count())->toBe(0);
});

/*
 * A closed tab leaves the row open while the browser session key is gone. If
 * that could not be cleared, one abandoned tab would block every future
 * impersonation by that staff member for good.
 */
it('clears a stale open session from a fresh browser', function () {
    $this->impersonation->start($this->support, $this->owner, $this->workspace, 'Abandoned');

    // A new session: no impersonation key, and no customer signed in.
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.impersonation.stop'))
        ->assertRedirect(route('admin.customer.show', $this->workspace));

    expect(ImpersonationSession::whereNull('ended_at')->count())->toBe(0);
});

it('does nothing when there is no session to stop', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.impersonation.stop'))
        ->assertRedirect(route('admin.customer.index'));
});

it('requires a reason', function () {
    $this->actingAs($this->support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => '',
        ])
        ->assertSessionHasErrors('reason');

    expect(ImpersonationSession::count())->toBe(0)
        ->and(Auth::guard('web')->check())->toBeFalse();
});

// ------------------------------------------------------------------- RBAC

// Section 10 gives this to support. Sales closes deals; it has no business
// inside a customer's account.
it('refuses a staff member without the impersonate permission', function () {
    $sales = AdminUser::factory()->create();
    $sales->assignRole('sales');

    $this->actingAs($sales, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Curious',
        ])
        ->assertForbidden();

    expect(ImpersonationSession::count())->toBe(0);
});

it('refuses a customer trying to impersonate', function () {
    $this->actingAs($this->owner)
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Nice try',
        ])
        ->assertRedirect(route('admin.login'));

    expect(ImpersonationSession::count())->toBe(0);
});
