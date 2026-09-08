<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\Page;
use App\Models\User;
use App\Notifications\Billing\TrialEndingNotification;
use Database\Seeders\PageSeeder;
use Illuminate\Notifications\Messages\MailMessage;

/*
 * Spec section 11: the app links to terms and privacy "from signup and from
 * email footers". Signup is the register page; this is the other half.
 *
 * Done once in the published mail wrapper rather than in nine notification
 * classes, which is also why these tests render a real notification through
 * the real pipeline - the whole point is that no individual notification has
 * to remember.
 */

/** Render a notification's mail exactly as the mail channel would. */
function renderFooter(User $user, App\Models\Workspace $workspace): string
{
    $mail = (new TrialEndingNotification($workspace, 3))->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    return (string) app(Illuminate\Mail\Markdown::class)->render(
        $mail->markdown ?? 'notifications::email',
        $mail->data(),
    );
}

beforeEach(function () {
    $this->seed(Database\Seeders\FeatureSeeder::class);
    $this->seed(Database\Seeders\PlanSeeder::class);

    $this->user = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->user, 'Acme Inc');
});

/*
 * The override is only worth anything if it actually wins over the framework's
 * copy of the same view. Publishing one file out of a component set is exactly
 * the kind of thing that silently does nothing.
 */
it('uses our published wrapper rather than the framework default', function () {
    $this->seed(PageSeeder::class);
    Page::query()->update(['published_at' => now()->subDay()]);

    expect(renderFooter($this->user, $this->workspace))->toContain('Terms of Service');
});

it('links both documents once they are published', function () {
    $this->seed(PageSeeder::class);
    Page::query()->update(['published_at' => now()->subDay()]);

    $html = renderFooter($this->user, $this->workspace);

    expect($html)->toContain(route('page.show', Page::TERMS_SLUG))
        ->toContain(route('page.show', Page::PRIVACY_SLUG));
});

/*
 * The pages are seeded as drafts, so this is the state every deployment starts
 * in. A footer linking to a 404 on every email we send would be worse than no
 * link at all.
 */
it('links nothing while the pages are drafts', function () {
    $this->seed(PageSeeder::class);

    $html = renderFooter($this->user, $this->workspace);

    expect($html)->not->toContain('Terms of Service')
        ->not->toContain('Privacy Policy')
        // The rest of the footer still renders.
        ->and($html)->toContain('All rights reserved');
});

it('links only the one that is published', function () {
    $this->seed(PageSeeder::class);
    Page::where('slug', Page::PRIVACY_SLUG)->update(['published_at' => now()->subDay()]);

    $html = renderFooter($this->user, $this->workspace);

    expect($html)->toContain('Privacy Policy')
        ->not->toContain('Terms of Service');
});

it('still renders when no pages exist at all', function () {
    $html = renderFooter($this->user, $this->workspace);

    expect($html)->toContain('All rights reserved');
});
