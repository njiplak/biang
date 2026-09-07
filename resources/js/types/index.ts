export type * from './auth';
export type * from './navigation';
export type * from './ui';

import type { Auth } from './auth';

export type WorkspaceSummary = {
    ulid: string;
    name: string;
    role: string;
    role_label: string;
    state: string;
    state_label: string;
};

export type CurrentWorkspace = {
    ulid: string;
    name: string;
    slug: string;
    state: string;
    state_label: string;
    can_write: boolean;
    can_export: boolean;
    // null unless the workspace is over a limit; names the specific features
    over_limit_features: string[] | null;
    grace_ends_at: string | null;
    trial_ends_at: string | null;
};

export type WorkspaceContext = {
    current: CurrentWorkspace | null;
    available: WorkspaceSummary[];
};

/** Section 10: set on every page while staff are inside a customer account. */
export type ImpersonationContext = {
    admin_name: string | null;
    user_name: string | null;
    user_email: string | null;
    reason: string;
    started_at: string;
};

/** Section 10: what this person should be told right now, in this workspace. */
export type AnnouncementNotice = {
    id: number;
    title: string;
    body: string;
    severity: 'info' | 'warning' | 'critical';
    is_dismissible: boolean;
};

export type SharedData = {
    name: string;
    auth: Auth;
    // null for guests and everywhere in the admin console. Named `tenancy`
    // because pages pass their own `workspace` and `workspaces` props.
    tenancy: WorkspaceContext | null;
    // Null whenever nobody is impersonating, which is almost always.
    impersonation: ImpersonationContext | null;
    // Empty for guests and in the admin console; staff get their own screen.
    announcements: AnnouncementNotice[];
    sidebarOpen: boolean;
    [key: string]: unknown;
};
