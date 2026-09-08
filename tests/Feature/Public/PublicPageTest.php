<?php

use App\Models\Page;
use Database\Seeders\PageSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Section 11's terms and privacy, served to anyone.
 *
 * Public with no auth of any kind: section 4 takes a card up front, so the
 * terms have to be readable by somebody who has not signed up yet.
 *
 * The body is markdown converted server-side, and the public page puts the
 * result through dangerouslySetInnerHTML - so the sanitising below is not a
 * nice-to-have, it is the reason that line is defensible.
 */

beforeEach(fn () => $this->withoutVite());

// ------------------------------------------------------------- serving

it('serves a published page to a complete stranger', function () {
    $page = Page::factory()->published()->create([
        'slug' => 'terms-of-service',
        'title' => 'Terms of Service',
        'body' => '# Heading

Some **bold** copy.',
    ]);

    $this->get(route('page.show', $page->slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('page/show')
            ->where('page.title', 'Terms of Service')
            ->where('page.html', fn (string $html) => str_contains($html, '<h1>Heading</h1>')
                && str_contains($html, '<strong>bold</strong>')));
});

/*
 * A draft is copy nobody has approved and a future date is copy deliberately
 * not in force yet. Serving either publishes a document by accident, which is
 * the one thing the publish flag exists to prevent.
 */
it('refuses to serve a draft', function () {
    $page = Page::factory()->create(['slug' => 'terms-of-service']);

    $this->get(route('page.show', $page->slug))->assertNotFound();
});

it('refuses to serve a page scheduled for later', function () {
    $page = Page::factory()->scheduled()->create(['slug' => 'terms-of-service']);

    $this->get(route('page.show', $page->slug))->assertNotFound();
});

it('404s on a slug that does not exist', function () {
    $this->get(route('page.show', 'no-such-page'))->assertNotFound();
});

// ------------------------------------------------------------- sanitising

/*
 * Staff are trusted with the business, not with a script tag on a page every
 * visitor loads. This is also the blast radius of a compromised staff account:
 * without the strip, editing a page would be stored XSS on a public URL.
 */
it('strips html written into the markdown', function () {
    $page = Page::factory()->published()->create([
        'body' => 'Hello <script>alert(1)</script> and <img src=x onerror=alert(1)> there.',
    ]);

    $html = $page->toHtml();

    expect($html)->not->toContain('<script')
        ->not->toContain('onerror')
        ->and($html)->toContain('Hello');
});

// Markdown link syntax would otherwise pass a javascript: href straight through.
it('drops unsafe link schemes', function (string $body) {
    expect(Page::factory()->published()->create(['body' => $body])->toHtml())
        ->not->toContain('javascript:');
})->with([
    '[click me](javascript:alert(1))',
    '[click me](JaVaScRiPt:alert(1))',
]);

it('keeps ordinary links working', function () {
    $html = Page::factory()->published()->create([
        'body' => '[our site](https://acme.test/legal)',
    ])->toHtml();

    expect($html)->toContain('href="https://acme.test/legal"');
});

it('copes with an empty body', function () {
    expect(Page::factory()->published()->create(['body' => ''])->toHtml())->toBe('');
});

// ------------------------------------------------------------- signup links

/*
 * Section 11: "Our app links to them from signup." Null while the page is a
 * draft, so the form shows no link rather than one that 404s - which is the
 * state the seeder deliberately leaves them in.
 */
it('offers signup no link while the pages are drafts', function () {
    $this->seed(PageSeeder::class);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('legal.terms', null)
            ->where('legal.privacy', null));
});

it('links signup to both once they are published', function () {
    $this->seed(PageSeeder::class);
    Page::query()->update(['published_at' => now()->subDay()]);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('legal.terms', route('page.show', Page::TERMS_SLUG))
            ->where('legal.privacy', route('page.show', Page::PRIVACY_SLUG)));
});

it('links only the one that is published', function () {
    $this->seed(PageSeeder::class);
    Page::where('slug', Page::TERMS_SLUG)->update(['published_at' => now()->subDay()]);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('legal.terms', route('page.show', Page::TERMS_SLUG))
            ->where('legal.privacy', null));
});
