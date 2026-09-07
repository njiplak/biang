<?php

namespace App\Service\Admin;

use App\Contract\Admin\RevenueContract;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

class RevenueService implements RevenueContract
{
    /** Section 15 measures churn monthly. */
    private const CHURN_WINDOW_DAYS = 30;

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
     * Converted is derivable exactly rather than estimated: convertTrial leaves
     * `trial_ends_at` set and moves the status to active, while a comped grant
     * never sets `trial_ends_at` at all. So a row with both is a trial that
     * paid, and nothing else produces that shape.
     */
    private function trials(): array
    {
        $running = Subscription::withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Trialing)
            ->count();

        $converted = Subscription::withoutWorkspaceScope()
            ->whereNotNull('trial_ends_at')
            ->where('status', SubscriptionStatus::Active)
            ->count();

        // Section 12: one trial per person, ever - so the number of people who
        // have spent theirs IS the number of trials ever started.
        $started = User::query()->whereNotNull('trial_consumed_at')->count();

        return [
            'running' => $running,
            'started_total' => $started,
            'converted_total' => $converted,
            // Null rather than zero when nobody has tried yet: "0% conversion"
            // and "no data" are very different things to read on a dashboard.
            'conversion_rate' => $started === 0
                ? null
                : round($converted / $started * 100, 1),
        ];
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
