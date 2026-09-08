/** Mirrors App\Service\Admin\CatalogService. */

export type CatalogPrice = {
    id: number;
    interval: 'month' | 'year';
    currency: string;
    amount_minor: number;
    is_archived: boolean;
    // false until the price exists as a product at the payment provider, which
    // is what makes it buyable at all - a saved price is not an on-sale price
    is_published: boolean;
};

export type CatalogPlanFeature = {
    id: number;
    key: string;
    name: string;
    /** Null is unlimited. */
    value: number | null;
};

export type CatalogPlan = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_public: boolean;
    is_free: boolean;
    sort_order: number;
    is_archived: boolean;
    /** Why a plan is retired rather than deleted. */
    live_subscriptions: number;
    features: CatalogPlanFeature[];
    prices: CatalogPrice[];
    addon_ids: number[];
};

export type CatalogAddon = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    kind: 'quantity' | 'unlock' | 'metered';
    feature_id: number | null;
    feature_key: string | null;
    grant_per_unit: number | null;
    max_quantity: number | null;
    is_archived: boolean;
    prices: CatalogPrice[];
};

export type CatalogFeature = {
    id: number;
    key: string;
    name: string;
    type: string;
    unit: string | null;
};

export type CatalogOverview = {
    plans: CatalogPlan[];
    addons: CatalogAddon[];
    features: CatalogFeature[];
};

export type AdminAnnouncement = {
    id: number;
    ulid: string;
    title: string;
    body: string;
    audience: 'all' | 'plan' | 'state';
    audience_filter: { plan_codes?: string[]; states?: string[] } | null;
    severity: 'info' | 'warning' | 'critical';
    is_dismissible: boolean;
    published_at: string | null;
    expires_at: string | null;
    is_live: boolean;
    created_by: string | null;
    dismissals_count: number;
};

/** Mirrors App\Service\Admin\StaffService. */
export type StaffRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    is_offboarded: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    created_at: string;
};

/** Mirrors App\Service\Admin\BillingOpsService. */
export type FailedWebhook = {
    id: number;
    event_id: string;
    event_type: string;
    error: string | null;
    attempts: number;
    occurred_at: string | null;
    failed_at: string | null;
    /** False when intake never verified it - a retry must not apply it. */
    can_retry: boolean;
};

export type DunningRow = {
    id: number;
    workspace_ulid: string | null;
    workspace_name: string | null;
    plan: string | null;
    started_at: string | null;
    grace_ends_at: string | null;
    grace_expired: boolean;
    attempt_count: number;
    last_failure_message: string | null;
};

export type IntegrityAlarm = {
    id: number;
    workspace_ulid: string | null;
    workspace_name: string | null;
    plan: string | null;
    status: string;
    created_at: string | null;
};

export type RecentWebhook = {
    id: number;
    event_type: string;
    received_at: string | null;
    processed_at: string | null;
    failed_at: string | null;
};

/**
 * Metered usage we recorded and never billed for. Filtered to workspaces that
 * actually have a payment account - free and comped ones never reach Dodo, and
 * their records stay unreported forever by design.
 */
export type UnreportedUsage = {
    id: number;
    workspace_ulid: string | null;
    workspace_name: string | null;
    feature: string;
    quantity: number;
    occurred_at: string | null;
    idempotency_key: string;
};

export type BillingOpsOverview = {
    failed_webhooks: FailedWebhook[];
    dunning: DunningRow[];
    integrity: IntegrityAlarm[];
    recent_webhooks: RecentWebhook[];
    unreported_usage: UnreportedUsage[];
};

/** Mirrors App\Service\Admin\AuditViewService. */
export type AuditRow = {
    id: number;
    action: string;
    actor: string | null;
    /** Staff and customers both write here; which side acted comes first. */
    actor_kind: 'staff' | 'customer' | 'system';
    workspace_name: string | null;
    workspace_ulid: string | null;
    subject: string | null;
    changes: Record<string, unknown> | null;
    via_impersonation: boolean;
    ip_address: string | null;
    created_at: string;
};

export type ImpersonationRow = {
    id: number;
    admin: string | null;
    user: string | null;
    user_email: string | null;
    workspace_name: string | null;
    workspace_ulid: string | null;
    reason: string;
    ticket_reference: string | null;
    started_at: string;
    ended_at: string | null;
    /** Somebody inside a customer account right now. */
    is_active: boolean;
    ip_address: string | null;
};

/** Mirrors App\Service\Admin\SchedulerHealthService. */
export type ScheduledTaskRow = {
    id: number;
    name: string;
    cron_expression: string;
    grace_time_in_minutes: number;
    last_started_at: string | null;
    last_finished_at: string | null;
    last_failed_at: string | null;
    last_skipped_at: string | null;
    expected_by: string | null;
    is_overdue: boolean;
    has_failed: boolean;
    is_healthy: boolean;
};

export type SchedulerOverview = {
    tasks: ScheduledTaskRow[];
    /** False means nobody ever synced the scheduler — not "all green". */
    is_registered: boolean;
    unhealthy_count: number;
};
