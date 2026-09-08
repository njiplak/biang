<?php

namespace App\Service\Admin;

use App\Contract\Admin\RevenueContract;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class RevenueService implements RevenueContract
{
    /** Section 15 measures churn monthly. */
    private const CHURN_WINDOW_DAYS = 30;

    /** A year of monthly buckets: long enough to see a direction, short enough to read. */
    private const TREND_MONTHS = 12;

    public function summary(): array
    {
        $paying = $this->subscriptions(SubscriptionStatus::Active);
        $atRisk = $this->subscriptions(SubscriptionStatus::PastDue);

        $mrr = $this->monthlyRecurring($paying);

        return [
            'mrr_minor' => $mrr,
            // ARR is MRR annualised, not the sum of annual contracts. Mixing
            // the two double-counts every yearly plan.
            'arr_minor' => $mrr * 12,
            'currency' => $this->currency($paying),

            // Section 9 keeps full access while past due, so these are still
            // customers - but counting them as revenue would overstate it
            // until the payment actually recovers.
            'at_risk_minor' => $this->monthlyRecurring($atRisk),
            'at_risk_count' => $atRisk->count(),

            // Section 8: a comp has no payment behind it. It is a real customer
            // and real usage, and zero revenue.
            'comped_count' => $paying->where('billing_source', BillingSource::Manual)->count(),

            'signups' => $this->signups(),
            'trials' => $this->trials(),
            'churn' => $this->churn(),
            'plans' => $this->planMix($paying),

            /*
             * Section 15 asks for movement, not a snapshot: every figure above
             * is a single number over a fixed window, which can say where we
             * are and never which way we are going.
             */
            'trends' => $this->trends(),
            'recovery' => $this->recovery(),
        ];
    }

    /**
     * Section 15: "Signups. How many complete a workspace."
     *
     * Two different numbers on purpose - a person who signs up and never names
     * a workspace has not become a customer, and the gap between these is the
     * one worth watching.
     */
    private function signups(): array
    {
        $since = now()->subWeek();

        return [
            'users_this_week' => User::query()->where('created_at', '>=', $since)->count(),
            'workspaces_this_week' => Workspace::query()->where('created_at', '>=', $since)->count(),
            'users_total' => User::query()->count(),
            'workspaces_total' => Workspace::query()->count(),
        ];
    }

    /**
     * Section 15: "Trial to paid conversion - the number this whole build
     * exists to move."
     *
     * Measured over the trials that have ENDED, not over everyone who ever
     * started one: a trial still running has not had its chance yet, and
     * counting it as a miss understates the rate every single month.
     */
    private function trials(): array
    {
        $running = Subscription::withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Trialing)
            ->count();

        // Two counts rather than a fetch: this one is unbounded - every trial
        // that has ever ended - and only the totals are ever used.
        $ended = $this->endedTrials()->count();
        $converted = $this->endedTrials()->where($this->converted(...))->count();

        // Section 12: one trial per person, ever - so the number of people who
        // have spent theirs IS the number of trials ever started.
        $started = User::query()->whereNotNull('trial_consumed_at')->count();

        return [
            'running' => $running,
            'started_total' => $started,
            'ended_total' => $ended,
            'converted_total' => $converted,
            // Null rather than zero when nothing has ended yet: "0% conversion"
            // and "no data" are very different things to read on a dashboard.
            'conversion_rate' => $ended === 0 ? null : round($converted / $ended * 100, 1),
        ];
    }

    /**
     * Trials that have had their chance: the 14 days ran out, so each one
     * either converted or did not.
     *
     * @return Builder<Subscription>
     */
    private function endedTrials(?CarbonImmutable $from = null): Builder
    {
        return Subscription::withoutWorkspaceScope()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->when($from, fn (Builder $query) => $query->where('trial_ends_at', '>=', $from));
    }

    /**
     * Did this trial reach a paid state?
     *
     * Deliberately NOT "is active today". That was the old rule, and it made a
     * conversion stop counting the moment the customer later cancelled - so
     * last year's rate fell every time somebody churned, which is churn being
     * counted twice under a second name.
     *
     * `dodo_subscription_id` is what survives: ConvertEndedTrials cancels
     * exactly the trials without one, and Dodo converts exactly the ones with
     * one. A first charge that then fails still counts as converted - the
     * customer bought, and what happened next is the dunning figures' job.
     *
     * The status arm covers a conversion recorded directly through
     * convertTrial(), which is the manual path and never holds a provider id.
     *
     * Written as a condition rather than a predicate on a loaded row so that
     * the tile, the monthly buckets and the churn exclusion all ask the same
     * question of the database, and none of them can drift from the others.
     */
    private function converted(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->whereNotNull('dodo_subscription_id')
            ->orWhereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ]));
    }

    private function churn(): array
    {
        $since = now()->subDays(self::CHURN_WINDOW_DAYS);

        $canceled = Subscription::withoutWorkspaceScope()
            ->whereNotNull('canceled_at')
            ->where('canceled_at', '>=', $since)
            ->count();

        $live = Subscription::withoutWorkspaceScope()->live()->count();

        // Denominator is what we started the window with: those still live plus
        // those that left. Dividing by today's live count alone overstates it.
        $base = $live + $canceled;

        return [
            'canceled_30d' => $canceled,
            'live' => $live,
            'rate' => $base === 0 ? null : round($canceled / $base * 100, 1),
        ];
    }

    /**
     * Section 15's numbers, one bucket per month, oldest first.
     *
     * Bucketed in PHP rather than grouped in SQL: a YEAR-MONTH grouping
     * expression is spelled differently on sqlite and postgres, and this file
     * already loads and groups its subscriptions the same way.
     *
     * Every month in the window is present even when nothing happened in it -
     * a chart that silently drops empty months draws a flat line through a
     * gap and calls it continuity.
     */
    private function trends(): array
    {
        $start = $this->trendStart();

        $users = $this->bucket(User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->map(fn (User $user) => $user->created_at));

        $workspaces = $this->bucket(Workspace::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->map(fn (Workspace $workspace) => $workspace->created_at));

        $endedByMonth = $this->bucket($this->trialEnds($this->endedTrials($start)));
        $convertedByMonth = $this->bucket($this->trialEnds(
            $this->endedTrials($start)->where($this->converted(...))
        ));

        $voluntary = $this->bucket($this->voluntaryChurn($start)
            ->map(fn (Subscription $subscription) => $subscription->canceled_at));

        $involuntary = $this->bucket(DunningState::withoutWorkspaceScope()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $start)
            // Section 9: recovered is the opposite of churn, and `canceled`
            // and `suspended` are the two ways an episode ends in a loss.
            ->whereIn('resolution', [DunningResolution::Canceled, DunningResolution::Suspended])
            ->get(['resolved_at'])
            ->map(fn (DunningState $state) => $state->resolved_at));

        return collect(range(self::TREND_MONTHS - 1, 0))
            ->map(function (int $back) use ($users, $workspaces, $endedByMonth, $convertedByMonth, $voluntary, $involuntary) {
                $month = now()->startOfMonth()->subMonths($back);
                $key = $month->format('Y-m');

                $trialsEnded = $endedByMonth[$key] ?? 0;
                $trialsConverted = $convertedByMonth[$key] ?? 0;

                return [
                    'month' => $key,
                    'label' => $month->format('M y'),
                    'signups_users' => $users[$key] ?? 0,
                    'signups_workspaces' => $workspaces[$key] ?? 0,
                    'trials_ended' => $trialsEnded,
                    'trials_converted' => $trialsConverted,
                    // Null, not zero: a month with no ended trials has no rate.
                    'conversion_rate' => $trialsEnded === 0
                        ? null
                        : round($trialsConverted / $trialsEnded * 100, 1),
                    'churn_voluntary' => $voluntary[$key] ?? 0,
                    'churn_involuntary' => $involuntary[$key] ?? 0,
                ];
            })
            ->all();
    }

    /**
     * Customers who chose to leave.
     *
     * A trial that ends without a card is cancelled by ConvertEndedTrials,
     * which sets `canceled_at` exactly like a customer leaving does - so the
     * raw cancellation count reports every failed conversion as a lost
     * customer, on top of already counting it as a failed conversion.
     *
     * Excluded by id from the cohort itself rather than by comparing
     * timestamps, so there is one definition of "never converted" and no
     * window to tune.
     *
     * @return Collection<int, Subscription>
     */
    private function voluntaryChurn(CarbonImmutable $start): Collection
    {
        $lapsed = $this->endedTrials($start)
            ->whereNot($this->converted(...))
            ->pluck('id')
            ->all();

        return Subscription::withoutWorkspaceScope()
            ->whereNotNull('canceled_at')
            ->where('canceled_at', '>=', $start)
            ->whereNotIn('id', $lapsed)
            ->get(['id', 'canceled_at']);
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return SupportCollection<int, \DateTimeInterface|null>
     */
    private function trialEnds(Builder $query): SupportCollection
    {
        return $query->get(['trial_ends_at'])
            ->map(fn (Subscription $subscription) => $subscription->trial_ends_at);
    }

    /**
     * Section 15: "Failed-payment recovery rate."
     *
     * Open episodes are left out on purpose - they have not been won or lost
     * yet, and counting them as losses would report a recovery rate that only
     * ever climbs as they close.
     */
    private function recovery(): array
    {
        $resolved = DunningState::withoutWorkspaceScope()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $this->trendStart())
            ->get(['resolution']);

        $recovered = $resolved
            ->filter(fn (DunningState $state) => $state->resolution === DunningResolution::Recovered)
            ->count();

        return [
            'months' => self::TREND_MONTHS,
            'resolved' => $resolved->count(),
            'recovered' => $recovered,
            'rate' => $resolved->isEmpty()
                ? null
                : round($recovered / $resolved->count() * 100, 1),
        ];
    }

    private function trendStart(): CarbonImmutable
    {
        return now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
    }

    /**
     * @param  iterable<\DateTimeInterface|null>  $timestamps
     * @return array<string, int>
     */
    private function bucket(iterable $timestamps): array
    {
        $counts = [];

        foreach ($timestamps as $timestamp) {
            if ($timestamp === null) {
                continue;
            }

            $key = $timestamp->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** Section 10: "Which plans are actually selling." */
    private function planMix(Collection $paying): array
    {
        return $paying
            ->groupBy(fn (Subscription $subscription) => $subscription->plan->name)
            ->map(fn (Collection $group, string $name) => [
                'plan' => $name,
                'customers' => $group->count(),
                'mrr_minor' => $this->monthlyRecurring($group),
            ])
            ->sortByDesc('mrr_minor')
            ->values()
            ->all();
    }

    /**
     * Normalised to a month so a yearly plan and a monthly one are comparable.
     * Comped subscriptions are excluded: there is no money behind them, and
     * counting them would inflate the one number nobody should be able to
     * flatter by hand.
     */
    private function monthlyRecurring(Collection $subscriptions): int
    {
        return (int) $subscriptions
            ->reject(fn (Subscription $subscription) => $subscription->billing_source === BillingSource::Manual)
            ->sum(function (Subscription $subscription) {
                $price = $subscription->planPrice;

                if ($price === null) {
                    return 0;
                }

                return match ($price->billing_interval) {
                    BillingInterval::Month => $price->amount_minor,
                    BillingInterval::Year => (int) round($price->amount_minor / 12),
                };
            });
    }

    /**
     * Section 8 leaves us multi-currency in principle. Until a rate source
     * exists, mixing currencies in one total would be a lie, so the dashboard
     * reports the one every price is actually in and says so when that breaks.
     */
    private function currency(Collection $paying): string
    {
        $currencies = $paying
            ->map(fn (Subscription $subscription) => $subscription->planPrice?->currency)
            ->filter()
            ->unique();

        return $currencies->count() === 1 ? $currencies->first() : 'MIXED';
    }

    /** @return Collection<int, Subscription> */
    private function subscriptions(SubscriptionStatus $status): Collection
    {
        return Subscription::withoutWorkspaceScope()
            ->where('status', $status)
            ->with(['plan', 'planPrice'])
            ->get();
    }
}
