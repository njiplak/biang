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

export type SharedData = {
    name: string;
    auth: Auth;
    // null for guests and everywhere in the admin console. Named `tenancy`
    // because pages pass their own `workspace` and `workspaces` props.
    tenancy: WorkspaceContext | null;
    sidebarOpen: boolean;
    [key: string]: unknown;
};
