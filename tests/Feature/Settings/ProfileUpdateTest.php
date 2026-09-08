<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();
    $original = $user->email;

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    // The name applies at once; the address waits to be confirmed, and the
    // account stays verified on the one it has already proved.
    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe($original);
    expect($user->pending_email)->toBe('test@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('the confirmation link applies the new address', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'moved@example.com',
    ]);

    $this->actingAs($user)
        ->get(URL::temporarySignedRoute('settings.email.confirm', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('moved@example.com'),
        ]))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('moved@example.com')
        ->and($user->pending_email)->toBeNull();
});

// A forwarded link must not move whoever happens to be signed in.
test('the confirmation link refuses a different signed-in account', function () {
    $owner = User::factory()->create();
    $bystander = User::factory()->create();

    $this->actingAs($owner)->patch(route('profile.update'), [
        'name' => $owner->name,
        'email' => 'moved@example.com',
    ]);

    $this->actingAs($bystander)
        ->get(URL::temporarySignedRoute('settings.email.confirm', now()->addHour(), [
            'id' => $owner->id,
            'hash' => sha1('moved@example.com'),
        ]))
        ->assertForbidden();

    expect($owner->refresh()->email)->not->toBe('moved@example.com');
});

test('an unsigned confirmation link is refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'moved@example.com',
    ]);

    $this->actingAs($user)
        ->get("/settings/email/confirm/{$user->id}/".sha1('moved@example.com'))
        ->assertForbidden();

    expect($user->refresh()->email)->not->toBe('moved@example.com');
});

test('a pending change can be cancelled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'moved@example.com',
    ]);

    $this->actingAs($user)
        ->delete(route('settings.email.cancel'))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->pending_email)->toBeNull();
});

// Two people cannot be told they may both have the same address.
test('an address already pending for someone else is rejected', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->patch(route('profile.update'), [
        'name' => $first->name,
        'email' => 'contested@example.com',
    ]);

    $this->actingAs($second)
        ->patch(route('profile.update'), [
            'name' => $second->name,
            'email' => 'contested@example.com',
        ])
        ->assertSessionHasErrors('email');

    expect($second->refresh()->pending_email)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
