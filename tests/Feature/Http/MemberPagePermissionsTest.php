<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * The members screen used to render the invite form, a role picker on every row
 * and a Remove button to whoever opened it - Viewer included. Every one of those
 * was refused by WorkspacePolicy and WorkspaceMemberPolicy on submit, so the
 * page was offering actions it knew would fail.
 *
 * These tests pin the props that let it stop: the same policies, asked before
 * the render instead of after the click.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->ownerMembership = $this->workspace->owners()->first();
});

// ------------------------------------------------------------- page-level

it('lets an owner of a paid workspace invite and export', function () {
    subscribeWorkspace($this->workspace);

    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.invite', true)
            ->where('can.export', true));
});

/*
 * Section 4: a workspace nobody is paying for cannot be added to. The invite
 * form has to go with it - WorkspacePolicy::inviteMembers already refuses.
 */
it('offers no invite form on a read-only workspace', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.invite', false)
            // Section 6: read-only keeps export. Refusing it would be
            // indefensible - it is their own data.
            ->where('can.export', true));
});

it('offers a viewer no way to invite', function () {
    subscribeWorkspace($this->workspace);

    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();

    $this->actingAs($viewer)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.invite', false));
});

/*
 * `can.invite` collapses two independent refusals - the person's role, and
 * whether the workspace can be written to at all. The page has to name the
 * right one, so the server says which.
 */
it('blames the workspace state when an owner cannot invite', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.invite', false)
            ->where('can.invite_blocked_by', 'state'));
});

it('blames the role when the person could never invite anyway', function () {
    subscribeWorkspace($this->workspace);

    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();

    $this->actingAs($viewer)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.invite_blocked_by', 'role'));
});

/*
 * Both at once. Role wins: a viewer sent to choose a plan is being pointed at
 * a billing page their role cannot open either.
 */
it('blames the role first when both reasons apply', function () {
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();

    $this->actingAs($viewer)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.invite_blocked_by', 'role'));
});

it('names nothing while inviting is allowed', function () {
    subscribeWorkspace($this->workspace);

    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.invite_blocked_by', null));
});

// ------------------------------------------------------------- row-level

/*
 * The feed used to serialise the whole user row. A Viewer could read every
 * colleague's pending email address, trial history and whether they had a
 * second factor set up - none of which the table has ever displayed.
 */
it('sends only the columns the table shows', function () {
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();

    $user = $this->actingAs($viewer)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->json('items.0.user');

    expect(array_keys($user))->toEqualCanonicalizing(['id', 'name', 'email'])
        ->and($user['email'])->toBe($this->owner->email);
});

it('marks an ordinary member as changeable and removable by the owner', function () {
    subscribeWorkspace($this->workspace);
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $member->id,
            'can_change_role' => true,
            'can_assign_owner' => true,
            'can_remove' => true,
        ]);
});

/*
 * Section 3: "a workspace must always have at least one owner." Both policies
 * refuse the last owner, so neither control is offered on that row.
 */
it('marks the last owner as neither changeable nor removable', function () {
    $this->actingAs($this->owner)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->ownerMembership->id,
            'can_change_role' => false,
            'can_assign_owner' => false,
            'can_remove' => false,
        ]);
});

/*
 * Section 3: only an owner may create another owner, or an admin - who cannot
 * see billing - could mint one who can and escalate sideways into it.
 */
it('hides the owner role from an admin', function () {
    subscribeWorkspace($this->workspace);
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($admin)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $member->id,
            'can_change_role' => true,
            'can_assign_owner' => false,
            'can_remove' => true,
        ]);
});

it('gives a viewer no row controls at all', function () {
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($viewer)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $member->id,
            'can_change_role' => false,
            'can_assign_owner' => false,
            'can_remove' => false,
        ]);
});

/*
 * "Anyone may leave of their own accord" - WorkspaceMemberPolicy::delete. The
 * row control has to agree, or a member who wants out is told to ask an admin.
 */
it('lets a member remove their own row but not anyone else\'s', function () {
    $member = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($this->workspace)->for($member)->create();
    $other = WorkspaceMember::factory()->for($this->workspace)->create();

    $response = $this->actingAs($member)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk();

    $response->assertJsonFragment(['id' => $membership->id, 'can_remove' => true]);
    $response->assertJsonFragment(['id' => $other->id, 'can_remove' => false]);
});

/*
 * The row controls follow the policies exactly, so read-only splits them: the
 * role picker goes, the Remove button stays. Removing is how a workspace gets
 * back under its seat limit (section 7), so blocking it would trap the
 * customer behind the very limit they are trying to clear.
 */
it('drops the role picker but keeps Remove on a read-only workspace', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    expect($this->workspace->canWrite())->toBeFalse();

    $this->actingAs($this->owner)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $member->id,
            'can_change_role' => false,
            'can_assign_owner' => false,
            'can_remove' => true,
        ]);
});
