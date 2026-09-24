<?php

namespace App\Service\Admin;

use App\Contract\Admin\CustomerContract;
use App\Contract\Billing\UsageContract;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Feature;
use App\Models\InvoiceSummary;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementOverride;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class CustomerService implements CustomerContract
{
    /** Section 10: "See their plan, state, seat usage and payment history." */
    private const RECENT_INVOICES = 10;

    public function __construct(private readonly UsageContract $usage) {}

    /**
     * Section 10: "Find any customer by email or workspace name."
     *
     * `any` includes closed ones - a support ticket about a workspace that
     * vanished is exactly when staff need to find it - so this reads through
     * the soft delete. displayState() renders those as Deleted.
     */
    public function search(?string $term, int $perPage): LengthAwarePaginator
    {
        return Workspace::withTrashed()
            ->when(filled($term), fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->whereLike('name', "%{$term}%")
                    ->orWhereLike('slug', "%{$term}%")
                    ->orWhereHas('users', fn (Builder $user) => $user->whereLike('email', "%{$term}%"))
            ))
            // Subscriptions are tenant-scoped. Eager loading them through the
            // default scope would return nothing whenever the signed-in staff
            // member also holds a customer session for a different workspace.
            ->with([
                'subscription' => fn ($query) => $query->withoutWorkspaceScope()->with('plan'),
            ])
            ->withCount('members')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function summarise(Workspace $workspace): array
    {
        $state = $workspace->displayState();
        $subscription = $workspace->relationLoaded('subscription')
            ? $workspace->getRelation('subscription')
            : $this->liveSubscription($workspace);

        return [
            'ulid' => $workspace->ulid,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'state' => $state->value,
            'state_label' => $state->label(),
            'plan' => $subscription?->plan?->name,
            'billing_source' => $subscription?->billing_source->value,
            'members_count' => $workspace->members_count ?? $workspace->members()->count(),
            'created_at' => $workspace->created_at,
        ];
    }

    public function overview(Workspace $workspace): array
    {
        $subscription = $this->liveSubscription($workspace, ['plan', 'planPrice', 'grantedByAdmin', 'scheduledPlanPrice.plan']);
        $entitlements = $this->entitlements($workspace);

        return [
            'workspace' => $this->workspacePayload($workspace),
            'subscription' => $this->subscriptionPayload($subscription),
            'seats' => [
                'used' => $workspace->seatsUsed(),
                // Null is unlimited. Read off the snapshot rather than the plan,
                // so an add-on or a staff override is reflected here too.
                'limit' => $entitlements->firstWhere('feature_key', 'seats')?->value,
            ],
            /*
             * Members only. Limits, overrides and invoices are tables now and
             * load themselves through paginateDetail(), so sending them here as
             * well would compute every one of them twice on every page load -
             * and the entitlement list costs a usage lookup per feature.
             *
             * This one stays because the impersonation dialog needs the people
             * to choose between before any table has loaded.
             */
            'members' => $this->membersPayload($workspace),
            /*
             * notification_logs records the milestone, not the recipient -
             * that uniqueness is what makes a duplicate charge warning
             * impossible - so the email history can say WHAT went out and
             * when, but never to whom. This is the honest other half: who a
             * billing email would reach if we sent one now.
             */
            'billing_recipients' => $this->billingRecipients($workspace),
            // What the actions on this page can offer.
            'plans' => $this->planOptions(),
            'features' => $this->featureOptions(),
        ];
    }

    private function workspacePayload(Workspace $workspace): array
    {
        $state = $workspace->displayState();

        return [
            'ulid' => $workspace->ulid,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'state' => $state->value,
            'state_label' => $state->label(),
            'can_write' => $workspace->canWrite(),
            'over_limit_features' => $workspace->over_limit_features,
            'suspended_at' => $workspace->suspended_at,
            'suspension_reason' => $workspace->suspension_reason,
            'suspended_by' => $workspace->suspendedByAdmin?->name,
            'grace_ends_at' => $workspace->grace_ends_at,
            'purge_after' => $workspace->purge_after,
            'created_at' => $workspace->created_at,
        ];
    }

    private function subscriptionPayload(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'plan' => $subscription->plan->name,
            'plan_code' => $subscription->plan->code,
            'status' => $subscription->status->value,
            // Section 8: `manual` is a comp with no payment behind it, which is
            // the first thing support needs to know about a paying-looking row.
            'billing_source' => $subscription->billing_source->value,
            'is_missing_provider_record' => $subscription->isMissingProviderRecord(),
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end,
            'amount_minor' => $subscription->planPrice->amount_minor,
            'currency' => $subscription->planPrice->currency,
            'interval' => $subscription->planPrice->billing_interval->value,
            'granted_by' => $subscription->grantedByAdmin?->name,
            'grant_reason' => $subscription->grant_reason,
            // Support is asked "why did my plan stop?" and "why am I still on
            // Pro?" - both are answered by what is scheduled, not what is live.
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'ends_at' => $subscription->cancel_at_period_end
                ? ($subscription->status === SubscriptionStatus::Trialing
                    ? $subscription->trial_ends_at
                    : $subscription->current_period_end)
                : null,
            'cancellation_feedback' => $subscription->cancellation_feedback?->value,
            'cancellation_comment' => $subscription->cancellation_comment,
            'scheduled_plan' => $subscription->scheduledPlanPrice?->plan?->name,
            'scheduled_change_at' => $subscription->scheduled_change_at,
        ];
    }

    /** The detail page's lists, and the only values paginateDetail() accepts. */
    public const LISTS = ['members', 'entitlements', 'overrides', 'invoices', 'notifications', 'usage'];

    /**
     * One of the detail page's lists, paginated for its table.
     *
     * Each reuses the SAME presenter the page payload uses, so a column cannot
     * mean one thing on first render and another after paging.
     */
    public function paginateDetail(Workspace $workspace, string $list, ?string $search, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);

        return match ($list) {
            'members' => $workspace->members()
                ->with('user')
                ->when($search, fn (Builder $query, string $term) => $query
                    ->whereHas('user', fn (Builder $q) => $q
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")))
                ->paginate($perPage)
                ->through(fn (WorkspaceMember $member) => $this->presentMember($member)),

            'entitlements' => WorkspaceEntitlement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->when($search, fn (Builder $query, string $term) => $query
                    ->where('feature_key', 'like', "%{$term}%"))
                ->orderBy('feature_key')
                ->paginate($perPage)
                ->through(fn (WorkspaceEntitlement $entitlement) => $this->presentEntitlement($workspace, $entitlement)),

            'overrides' => WorkspaceEntitlementOverride::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->live()
                ->with(['feature', 'grantedByAdmin'])
                ->when($search, fn (Builder $query, string $term) => $query
                    ->whereHas('feature', fn (Builder $q) => $q->where('key', 'like', "%{$term}%")))
                ->paginate($perPage)
                ->through(fn (WorkspaceEntitlementOverride $override) => $this->presentOverride($override)),

            'invoices' => InvoiceSummary::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->when($search, fn (Builder $query, string $term) => $query
                    ->where('number', 'like', "%{$term}%")
                    ->orWhere('status', 'like', "%{$term}%"))
                ->orderByDesc('issued_at')
                ->paginate($perPage)
                ->through(fn (InvoiceSummary $invoice) => $this->presentInvoice($invoice)),

            /*
             * Section 16: the trial auto-charges, so "you charged me with no
             * warning" is a chargeback waiting to happen. Every billing command
             * writes here through BillingNotifier, and nothing read it until
             * now - which made that dispute unanswerable from the console.
             *
             * Not workspace-scoped as a model, so this filters by hand.
             */
            'notifications' => NotificationLog::query()
                ->where('workspace_id', $workspace->id)
                ->when($search, fn (Builder $query, string $term) => $query
                    ->where('type', 'like', "%{$term}%"))
                ->orderByDesc('sent_at')
                ->paginate($perPage)
                ->through(fn (NotificationLog $log) => $this->presentNotification($log)),

            /*
             * The metered ledger, not the counters: `entitlements` above
             * already shows the current level of every feature, and what is
             * missing is the events behind a metered bill - including whether
             * each one actually reached the provider.
             */
            'usage' => UsageRecord::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->when($search, fn (Builder $query, string $term) => $query
                    ->where('feature_key', 'like', "%{$term}%"))
                ->orderByDesc('occurred_at')
                ->paginate($perPage)
                ->through(fn (UsageRecord $record) => $this->presentUsageRecord($record)),

            default => throw new InvalidArgumentException("Unknown customer list [{$list}]."),
        };
    }

    /** @return array<string, mixed> */
    private function presentMember(WorkspaceMember $member): array
    {
        return [
            'id' => $member->id,
            // Impersonation targets the PERSON, not the membership row.
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role->value,
            'role_label' => $member->role->label(),
            'is_owner' => $member->role === WorkspaceRole::Owner,
            'joined_at' => $member->joined_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentEntitlement(Workspace $workspace, WorkspaceEntitlement $entitlement): array
    {
        return [
            // Doubles as the table's row id: one entitlement per feature key.
            'feature' => $entitlement->feature_key,
            'used' => $this->usage->current($workspace, $entitlement->feature_key),
            'limit' => $entitlement->value,
            'source' => $entitlement->source->value,
        ];
    }

    /** @return array<string, mixed> */
    private function presentOverride(WorkspaceEntitlementOverride $override): array
    {
        return [
            'id' => $override->id,
            'feature' => $override->feature->key,
            'feature_name' => $override->feature->name,
            'value' => $override->value,
            'reason' => $override->reason,
            'granted_by' => $override->grantedByAdmin?->name,
            'expires_at' => $override->expires_at,
        ];
    }

    /** Section 8: we keep a summary; the document itself stays with the provider. */
    private function presentInvoice(InvoiceSummary $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'total_minor' => $invoice->total_minor,
            'issued_at' => $invoice->issued_at,
            'paid_at' => $invoice->paid_at,
            'hosted_url' => $invoice->hosted_url,
        ];
    }

    /**
     * The milestones BillingNotifier sends, in the words a staff member would
     * use to a customer.
     *
     * The dunning reminders build their type from a day count at send time, so
     * no map can be complete - an unknown key renders as itself rather than as
     * an empty column, and the raw `type` ships alongside the label either way.
     */
    private const NOTIFICATION_LABELS = [
        'trial_ending_3d' => 'Trial ending in 3 days',
        'trial_ending_1d' => 'Trial ending tomorrow',
        'trial_converted' => 'Trial converted',
        'trial_ended_unpaid' => 'Trial ended unpaid',
        'payment_failed' => 'Payment failed',
        'grace_ended' => 'Grace period ended',
    ];

    /** @return array<string, mixed> */
    private function presentNotification(NotificationLog $log): array
    {
        return [
            'id' => $log->id,
            'type' => $log->type,
            'label' => self::NOTIFICATION_LABELS[$log->type]
                ?? ucfirst(str_replace('_', ' ', $log->type)),
            'channel' => $log->channel,
            'sent_at' => $log->sent_at,
        ];
    }

    /**
     * Section 9's rule, read off the enum rather than restated: the owner and
     * any billing manager, never regular members. A second copy of that list
     * here would drift from the one BillingNotifier actually sends to.
     *
     * @return string[]
     */
    private function billingRecipients(Workspace $workspace): array
    {
        return $workspace->members()->with('user')->get()
            ->filter(fn (WorkspaceMember $member) => $member->role->receivesBillingNotifications())
            ->map(fn (WorkspaceMember $member) => $member->user?->email)
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentUsageRecord(UsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'feature' => $record->feature_key,
            'quantity' => $record->quantity,
            'occurred_at' => $record->occurred_at,
            'is_reported' => $record->reported_at !== null,
            'reported_at' => $record->reported_at,
            // Section 8: the id to quote back when a charge is disputed.
            'dodo_event_id' => $record->dodo_event_id,
        ];
    }

    private function membersPayload(Workspace $workspace): array
    {
        return $workspace->members()->with('user')->get()
            ->map(fn (WorkspaceMember $member) => $this->presentMember($member))
            ->values()
            ->all();
    }

    /**
     * Every sellable price, including plans hidden from the public pricing
     * page: granting one by hand is exactly when staff need those.
     */
    private function planOptions(): array
    {
        return Plan::query()->active()->with(['prices' => fn ($query) => $query->active()])
            ->orderBy('sort_order')
            ->get()
            ->flatMap(fn (Plan $plan) => $plan->prices->map(fn (PlanPrice $price) => [
                'price_id' => $price->id,
                'label' => sprintf(
                    '%s - %s %s / %s',
                    $plan->name,
                    $price->currency,
                    number_format($price->amount_minor / 100, 2),
                    $price->billing_interval->value,
                ),
                'plan_code' => $plan->code,
                'is_free' => $plan->is_free,
            ]))
            ->values()
            ->all();
    }

    private function featureOptions(): array
    {
        return Feature::query()->orderBy('sort_order')->get()
            ->map(fn (Feature $feature) => [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, WorkspaceEntitlement> */
    private function entitlements(Workspace $workspace): Collection
    {
        return WorkspaceEntitlement::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('feature_key')
            ->get();
    }

    private function liveSubscription(Workspace $workspace, array $with = []): ?Subscription
    {
        return Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->with($with)
            ->first();
    }
}
