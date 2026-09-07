<?php

use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\UsageReportFailed;
use App\Jobs\ReportUsageToProvider;
use App\Models\UsageRecord;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * Section 4's third add-on kind: metered usage, "billed on actual consumption".
 *
 * This is the one direction where WE hold the fact that becomes money -
 * consumption happens in our product and exists nowhere else - so an event that
 * never reaches Dodo is revenue nobody can find later.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->workspace->update(['dodo_customer_id' => 'cus_dodo_1']);

    $this->usage = app(UsageContract::class);
});

/*
 * Queued, not inline. A customer making the API call being metered must not
 * wait on Dodo, and must certainly not have their call fail because Dodo is
 * down - section 7's hard block runs off our own counter, which has already
 * moved by then.
 */
it('reports usage in the background rather than in the request', function () {
    Queue::fake();

    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');

    Queue::assertPushed(ReportUsageToProvider::class);
    expect($this->usage->current($this->workspace, 'api_calls'))->toBe(5);
});

it('carries the event to the provider', function () {
    $gateway = fakeGateway();

    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');

    expect($gateway->usage)->toHaveCount(1)
        ->and($gateway->usage[0]['customer_id'])->toBe('cus_dodo_1')
        ->and($gateway->usage[0]['event_name'])->toBe('api_calls')
        // Their event has no quantity of its own; meters aggregate over
        // metadata, and every value has to be a string.
        ->and($gateway->usage[0]['metadata']['quantity'])->toBe('5')
        // Our idempotency key IS the provider event id - that is what makes a
        // retried job bill once.
        ->and($gateway->usage[0]['event_id'])->toBe('call-1');

    $record = UsageRecord::withoutWorkspaceScope()->firstOrFail();
    expect($record->reported_at)->not->toBeNull()
        ->and($record->dodo_event_id)->toBe('call-1');
});

/*
 * Section 12: "Free tier in the payment provider? No - free never touches
 * them." A free workspace still meters usage for section 7's hard block, which
 * is our own accounting and none of Dodo's business.
 */
it('never reports usage for a workspace with no payment account', function () {
    $gateway = fakeGateway();
    $this->workspace->update(['dodo_customer_id' => null]);

    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');

    expect($gateway->usage)->toBeEmpty()
        // Still counted for us: the hard block does not depend on being billed.
        ->and($this->usage->current($this->workspace, 'api_calls'))->toBe(5);
});

// Metered usage is money, and reporting it twice bills twice.
it('reports the same consumption only once', function () {
    $gateway = fakeGateway();

    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');
    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');

    expect($gateway->usage)->toHaveCount(1)
        ->and($this->usage->current($this->workspace, 'api_calls'))->toBe(5);
});

// The job itself must be safe to run again - that is what allows retries at all.
it('does not re-report a record a previous run already carried', function () {
    $gateway = fakeGateway();

    $this->usage->record($this->workspace, 'api_calls', 5, 'call-1');
    $record = UsageRecord::withoutWorkspaceScope()->firstOrFail();

    (new ReportUsageToProvider($record->id))->handle($gateway);

    expect($gateway->usage)->toHaveCount(1);
});

/*
 * A failure has to be loud. Swallowing it would undercharge the customer and
 * leave nothing anywhere saying so; instead the job raises, retries, and the
 * row stays findable as unreported.
 */
it('leaves the record unreported when the provider refuses', function () {
    $gateway = fakeGateway()->broken();

    expect(fn () => $this->usage->record($this->workspace, 'api_calls', 5, 'call-1'))
        ->toThrow(UsageReportFailed::class);

    $record = UsageRecord::withoutWorkspaceScope()->firstOrFail();
    expect($record->reported_at)->toBeNull()
        ->and(UsageRecord::withoutWorkspaceScope()->unreported()->count())->toBe(1);
});
