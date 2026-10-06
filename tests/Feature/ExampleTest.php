<?php

use App\Models\User;

// `/` has no page of its own: the marketing site is a separate project.
test('sends a guest to the login page when no marketing site is set', function () {
    config(['app.marketing_url' => null]);

    $this->get(route('home'))->assertRedirect(route('login'));
});

test('sends a guest to the marketing site when one is set', function () {
    config(['app.marketing_url' => 'https://example.test']);

    $this->get(route('home'))->assertRedirect('https://example.test');
});

test('sends a signed-in customer to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
