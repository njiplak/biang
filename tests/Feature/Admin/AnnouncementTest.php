<?php

use App\Contract\Admin\AnnouncementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/* Section 10: "Talk to everyone. Announce maintenance or a new feature." */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->announcements = app(AnnouncementContract::class);

    $this->admin = AdminUser::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->announce = fn (array $attributes = []) => $this->announcements->create([
        'title' => 'Scheduled maintenance',
        'body' => 'We are down on Sunday.',
        'audience' => 'all',
        'severity' => 'info',
        'is_dismissible' => true,
        'created_by_admin_id' => $this->admin->id,
        ...$attributes,
    ]);
});

// ------------------------------------------------------------ what gets shown

it('shows nothing until it is published', function () {
    ($this->announce)();

    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();
});

it('shows a published announcement to everyone', function () {
    ($this->announce)(['published_at' => now()->subMinute()]);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toHaveCount(1);
});

it('hides one that has expired', function () {
    ($this->announce)([
        'published_at' => now()->subDays(2),
        'expires_at' => now()->subDay(),
    ]);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();
});

it('hides one scheduled for later', function () {
    ($this->announce)(['published_at' => now()->addDay()]);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();
});

// ---------------------------------------------------------------- targeting

it('targets a plan', function () {
    ($this->announce)([
        'published_at' => now()->subMinute(),
        'audience' => 'plan',
        'audience_filter' => ['plan_codes' => ['pro']],
    ]);

    // On the free tier, so not addressed.
    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();

    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
    app(SubscriptionContract::class)->grantPlan($this->workspace, $price, $this->admin, 'Deal');

    expect($this->announcements->forUser($this->owner, $this->workspace->fresh()))->toHaveCount(1);
});

// A workspace with no subscription resolves to the free plan, not to "no plan".
it('targets the free tier by its plan code', function () {
    ($this->announce)([
        'published_at' => now()->subMinute(),
        'audience' => 'plan',
        'audience_filter' => ['plan_codes' => ['free']],
    ]);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toHaveCount(1);
});

it('targets a workspace state', function () {
    ($this->announce)([
        'published_at' => now()->subMinute(),
        'audience' => 'state',
        'audience_filter' => ['states' => ['suspended']],
    ]);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();

    app(WorkspaceContract::class)->suspend($this->workspace, $this->admin, 'Abuse');

    expect($this->announcements->forUser($this->owner, $this->workspace->fresh()))->toHaveCount(1);
});

/*
 * Someone between workspaces should see the universal ones rather than a
 * targeted filter that silently matches nothing.
 */
it('shows only universal announcements when there is no workspace', function () {
    ($this->announce)(['published_at' => now()->subMinute(), 'title' => 'For everyone']);
    ($this->announce)([
        'published_at' => now()->subMinute(),
        'title' => 'For pro',
        'audience' => 'plan',
        'audience_filter' => ['plan_codes' => ['pro']],
    ]);

    $visible = $this->announcements->forUser($this->owner, null);

    expect($visible)->toHaveCount(1)
        ->and($visible[0]['title'])->toBe('For everyone');
});

// ---------------------------------------------------------------- dismissal

/*
 * Dismissal is per PERSON, not per workspace: the same human should not be told
 * about the same maintenance window once per workspace in their switcher.
 */
it('stays dismissed across every workspace that person is in', function () {
    $announcement = ($this->announce)(['published_at' => now()->subMinute()]);
    $second = app(WorkspaceContract::class)->create($this->owner, 'Second Co');

    $this->announcements->dismiss($announcement, $this->owner);

    expect($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty()
        ->and($this->announcements->forUser($this->owner, $second))->toBeEmpty();
});

it('still shows it to a different person', function () {
    $announcement = ($this->announce)(['published_at' => now()->subMinute()]);
    $other = User::factory()->create();

    $this->announcements->dismiss($announcement, $this->owner);

    expect($this->announcements->forUser($other, null))->toHaveCount(1);
});

// What makes it usable for "we are down right now".
it('refuses to dismiss one marked as not dismissible', function () {
    $announcement = ($this->announce)([
        'published_at' => now()->subMinute(),
        'is_dismissible' => false,
    ]);

    $this->announcements->dismiss($announcement, $this->owner);

    expect(AnnouncementDismissal::count())->toBe(0)
        ->and($this->announcements->forUser($this->owner, $this->workspace))->toHaveCount(1);
});

it('does not record a second dismissal', function () {
    $announcement = ($this->announce)(['published_at' => now()->subMinute()]);

    $this->announcements->dismiss($announcement, $this->owner);
    $this->announcements->dismiss($announcement, $this->owner);

    expect(AnnouncementDismissal::count())->toBe(1);
});

// Republishing must not resurrect it for everyone who already dismissed it.
it('does not move the publish time when published twice', function () {
    $announcement = ($this->announce)(['published_at' => now()->subDays(3)]);
    $original = $announcement->published_at;

    $this->announcements->publish($announcement->fresh());

    expect($announcement->fresh()->published_at->toDateTimeString())
        ->toBe($original->toDateTimeString());
});

// ------------------------------------------------------------------- the HTTP

it('reaches customers as a shared prop on every page', function () {
    ($this->announce)(['published_at' => now()->subMinute()]);

    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('announcements', 1)
            ->where('announcements.0.title', 'Scheduled maintenance'));
});

it('lets a customer dismiss one from the banner', function () {
    $announcement = ($this->announce)(['published_at' => now()->subMinute()]);

    $this->actingAs($this->owner)
        ->post(route('announcement.dismiss', $announcement))
        ->assertRedirect();

    expect(AnnouncementDismissal::where('user_id', $this->owner->id)->count())->toBe(1);
});

/*
 * Dismissing something they were never shown would let a customer probe for
 * announcements we have not sent them.
 */
it('refuses to dismiss an unpublished announcement', function () {
    $announcement = ($this->announce)();

    $this->actingAs($this->owner)
        ->post(route('announcement.dismiss', $announcement))
        ->assertNotFound();

    expect(AnnouncementDismissal::count())->toBe(0);
});

it('creates a draft that nobody can see yet', function () {
    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.announcement.store'), [
            'title' => 'New export tool',
            'body' => 'It is out.',
            'audience' => 'all',
            'severity' => 'info',
            'is_dismissible' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $announcement = Announcement::firstOrFail();

    expect($announcement->published_at)->toBeNull()
        ->and($announcement->created_by_admin_id)->toBe($this->admin->id)
        ->and($this->announcements->forUser($this->owner, $this->workspace))->toBeEmpty();
});

it('publishes and unpublishes', function () {
    $announcement = ($this->announce)();

    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.announcement.publish', $announcement))
        ->assertRedirect();
    expect($announcement->fresh()->isLive())->toBeTrue();

    $this->actingAs($this->admin, 'admin')
        ->delete(route('admin.announcement.unpublish', $announcement))
        ->assertRedirect();
    expect($announcement->fresh()->isLive())->toBeFalse();
});

it('folds plan targeting into the stored filter', function () {
    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.announcement.store'), [
            'title' => 'Pro only',
            'body' => 'Something for Pro.',
            'audience' => 'plan',
            'plan_codes' => ['pro'],
            'severity' => 'info',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Announcement::firstOrFail()->audience_filter)->toBe(['plan_codes' => ['pro']]);
});

it('refuses a plan code that does not exist', function () {
    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.announcement.store'), [
            'title' => 'Nope',
            'body' => 'Nope.',
            'audience' => 'plan',
            'plan_codes' => ['does-not-exist'],
            'severity' => 'info',
        ])
        ->assertSessionHasErrors('plan_codes.0');
});

it('shows the console screen to staff who may announce', function () {
    ($this->announce)();

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.announcement.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/announcement/index')
            ->has('announcements', 1)
            ->where('announcements.0.is_live', false));
});

// Section 10 keeps announce.manage separate from the customer-facing jobs.
it('refuses staff without the announcement permission', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.announcement.index'))
        ->assertForbidden();
});

it('keeps customers out of the console screen', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.announcement.index'))
        ->assertRedirect(route('admin.login'));
});

it('shares nothing for a guest', function () {
    ($this->announce)(['published_at' => now()->subMinute()]);

    app(CurrentWorkspace::class)->forget();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('announcements', 0));
});
