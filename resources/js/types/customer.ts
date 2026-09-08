/** Shapes the staff console reads. Mirrors App\Service\Admin\CustomerService. */

export type WorkspaceState =
    // No free tier: nobody is paying, so everything is readable and nothing
    // is writable. Covers "never bought" and "subscription ended" alike.
    | 'expired'
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'suspended'
    | 'over_limit'
    | 'deleted';

export type CustomerRow = {
    // NextTable keys rows off this; it is what URLs use too.
    ulid: string;
    name: string;
    slug: string;
    state: WorkspaceState;
    state_label: string;
    plan: string | null;
    billing_source: 'dodo' | 'manual' | null;
    members_count: number;
    created_at: string;
};

export type CustomerWorkspace = {
    ulid: string;
    name: string;
    slug: string;
    state: WorkspaceState;
    state_label: string;
    can_write: boolean;
    over_limit_features: string[] | null;
    suspended_at: string | null;
    suspension_reason: string | null;
    suspended_by: string | null;
    grace_ends_at: string | null;
    purge_after: string | null;
    created_at: string;
};

export type CustomerSubscription = {
    plan: string;
    plan_code: string;
    status: string;
    billing_source: 'dodo' | 'manual';
    // `dodo` with no provider id is a broken sync, not a comp.
    is_missing_provider_record: boolean;
    trial_ends_at: string | null;
    current_period_end: string | null;
    amount_minor: number;
    currency: string;
    interval: string;
    granted_by: string | null;
    grant_reason: string | null;
};

export type CustomerMember = {
    id: number;
    user_id: number;
    name: string | null;
    email: string | null;
    role: string;
    role_label: string;
    is_owner: boolean;
    joined_at: string | null;
};

export type CustomerEntitlement = {
    feature: string;
    used: number;
    /** Null is unlimited. */
    limit: number | null;
    source: 'plan' | 'addon' | 'override';
};

export type CustomerOverride = {
    id: number;
    feature: string;
    feature_name: string;
    /** Null is unlimited. */
    value: number | null;
    reason: string;
    granted_by: string | null;
    expires_at: string | null;
};

export type CustomerInvoice = {
    id: number;
    number: string | null;
    status: string;
    currency: string;
    total_minor: number;
    issued_at: string | null;
    paid_at: string | null;
    hosted_url: string | null;
};

export type PlanOption = {
    price_id: number;
    label: string;
    plan_code: string;
    is_free: boolean;
};

export type FeatureOption = {
    id: number;
    key: string;
    name: string;
};

export type CustomerOverview = {
    workspace: CustomerWorkspace;
    subscription: CustomerSubscription | null;
    seats: { used: number; limit: number | null };
    // Only the people are sent with the page - the impersonation dialog needs
    // them up front. Limits, overrides and invoices are tables that load
    // themselves, so sending them here too would be doing the work twice.
    members: CustomerMember[];
    plans: PlanOption[];
    features: FeatureOption[];
};

/** Section 10 "Understand the business". Mirrors App\Service\Admin\RevenueService. */
export type RevenueSummary = {
    mrr_minor: number;
    arr_minor: number;
    /** 'MIXED' when prices span more than one currency and no total is honest. */
    currency: string;
    at_risk_minor: number;
    at_risk_count: number;
    comped_count: number;
    signups: {
        users_this_week: number;
        workspaces_this_week: number;
        users_total: number;
        workspaces_total: number;
    };
    trials: {
        running: number;
        started_total: number;
        converted_total: number;
        /** Null means nobody has started a trial yet, which is not 0%. */
        conversion_rate: number | null;
    };
    churn: {
        canceled_30d: number;
        live: number;
        rate: number | null;
    };
    plans: { plan: string; customers: number; mrr_minor: number }[];
};
