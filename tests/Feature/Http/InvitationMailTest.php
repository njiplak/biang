<?php

use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Notifications\WorkspaceInvitationNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    // Inviting is a write, and there is no free tier: a workspace nobody is
    // paying for is read-only, so these tests have to buy a plan first.
    subscribeWorkspace($this->workspace);
});

// Until now an invitation was created, a seat was reserved, and the link went
// nowhere - the flow dead-ended.
it('emails the invitation link', function () {
    Notification::fake();

    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'new@example.com',
            'role' => 'member',
        ])->assertRedirect();

    Notification::assertSentOnDemand(
        WorkspaceInvitationNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com'
    );
});

it('emails a fresh link when resent', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);

    Notification::fake();

    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.resend', $invitation))
        ->assertRedirect();

    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
});

it('sends nothing when the seat limit blocks the invite', function () {
    Notification::fake();

    // two seats on the plan they pay for; the owner already holds one
    capSeats($this->workspace, 2);

    $this->actingAs($this->owner)->post(route('workspace.invitation.store', $this->workspace), [
        'email' => 'a@example.com', 'role' => 'member',
    ]);
    $this->actingAs($this->owner)->post(route('workspace.invitation.store', $this->workspace), [
        'email' => 'b@example.com', 'role' => 'member',
    ])->assertSessionHasErrors('errors');

    Notification::assertSentOnDemandTimes(WorkspaceInvitationNotification::class, 1);
});

// The email links to a GET. It must be reachable before signing in, or an
// invitee without an account hits a login wall with no context.
it('shows the invitation to a signed out visitor', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'new@example.com', WorkspaceRole::Admin, $this->owner);

    $this->get(route('invitation.show', $invitation->plainToken))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitation/show')
            ->where('workspace', 'Acme Inc')
            ->where('role', 'admin')
            ->where('authenticated', false));
});

it('tells a visitor when the invitation is no longer valid', function () {
    $this->get(route('invitation.show', 'not-a-real-token'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitation/show')->where('valid', false));
});

it('never leaks the workspace name for a bad token', function () {
    $this->get(route('invitation.show', 'not-a-real-token'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace', null));
});
