<?php

use App\Models\Page;
use App\Models\ProductEvent;
use App\Models\User;

/*
 * Which terms a customer agreed to is recorded on the account, not implied by
 * a sentence under the button - with a merchant of record, a dispute is argued
 * from evidence.
 */

beforeEach(function () {
    $this->withoutVite();

    $this->signUp = fn (array $extra = []) => $this->post(route('register.store'), array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $extra));
});

it('requires the tick once terms are published', function () {
    Page::factory()->published()->create(['slug' => Page::TERMS_SLUG]);

    ($this->signUp)()->assertSessionHasErrors('terms');

    expect(User::count())->toBe(0);
});

it('records which terms were accepted, and when', function () {
    Page::factory()->published()->create(['slug' => Page::TERMS_SLUG]);

    ($this->signUp)(['terms' => true])->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'test@example.com');

    expect($user->terms_accepted_at)->not->toBeNull()
        ->and($user->terms_version)->toStartWith(Page::TERMS_SLUG.'@');
});

// Nothing published yet: nothing to agree to, and no tick demanded.
it('does not ask before any terms are published', function () {
    ($this->signUp)()->assertSessionHasNoErrors();

    expect(User::firstWhere('email', 'test@example.com')->terms_accepted_at)->toBeNull();
});

// Section 15's funnel starts here.
it('records the signup in the funnel', function () {
    ($this->signUp)();

    expect(ProductEvent::where('name', 'signed_up')->count())->toBe(1);
});
