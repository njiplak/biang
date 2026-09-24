import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    CreditCard,
    FolderKanban,
    Plus,
    RotateCcw,
    Sparkles,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import CreateWorkspaceDialog from '@/components/create-workspace-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';

type Props = {
    // The plan carried through signup, still waiting because the workspace
    // could not be created on the way in. Null once spent, or if none chosen.
    pending_plan: { name: string; trial_days: number | null } | null;
    // Section 6: closed workspaces this person owns that can still be restored.
    closed_workspaces: {
        ulid: string;
        name: string;
        restorable_until: string;
    }[];
    // The current workspace at a glance; null when they are not in one.
    home: { plan: string | null; members: number; projects: number } | null;
};

const date = (value: string) => new Date(value).toLocaleDateString();

/**
 * The customer's home: their workspace, its plan, and the next things worth
 * doing. One customer has one workspace for now; other people's workspaces
 * they were invited to are listed underneath.
 */
export default function Dashboard({
    pending_plan,
    closed_workspaces,
    home,
}: Props) {
    const { tenancy, auth } = usePage<SharedData>().props;
    const current = tenancy?.current ?? null;
    const workspaces = tenancy?.available ?? [];
    const canCreate = tenancy?.can_create_workspace ?? false;
    const role = workspaces.find((w) => w.ulid === current?.ulid)?.role;
    const canManageMembers = role === 'owner' || role === 'admin';
    const [creating, setCreating] = useState(false);
    const firstName = auth.user?.name?.split(' ')[0] ?? '';

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-xl font-semibold tracking-tight">
                    Welcome{firstName ? `, ${firstName}` : ''}
                </h1>

                {current && home && (
                    <section className="flex flex-col gap-4 rounded-md border border-border p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <span className="flex flex-col">
                                <span className="font-medium">
                                    {current.name}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {home.plan ?? 'No plan'} ·{' '}
                                    {current.state_label}
                                    <KeyDate workspace={current} />
                                </span>
                            </span>
                        </div>

                        {/* The next things worth doing, ticked off as they
                            happen. Each is a link, never a wall. */}
                        <ul className="flex flex-col gap-2 text-sm">
                            {current.state === 'expired' &&
                                current.can_manage_billing && (
                                    <NextStep
                                        done={false}
                                        icon={CreditCard}
                                        href="/billing"
                                        label="Choose a plan to start making changes"
                                    />
                                )}
                            <NextStep
                                done={home.projects > 0}
                                icon={FolderKanban}
                                href="/projects"
                                label="Create your first project"
                            />
                            {canManageMembers && (
                                <NextStep
                                    done={home.members > 1}
                                    icon={Users}
                                    href={`/workspaces/${current.ulid}/members`}
                                    label="Invite your team"
                                />
                            )}
                        </ul>
                    </section>
                )}

                {/* Onboarding could not create their workspace (see
                    OnboardingController) - offer it by hand, with the plan
                    they picked still named. */}
                {!current && canCreate && (
                    <section className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                        <Sparkles className="size-4 shrink-0" />
                        <span>
                            {pending_plan ? (
                                <>
                                    You chose the{' '}
                                    <strong>{pending_plan.name}</strong> plan.
                                    {pending_plan.trial_days === null
                                        ? ' Create your workspace to continue to checkout.'
                                        : ` Create your workspace to start your ${pending_plan.trial_days}-day trial.`}
                                </>
                            ) : (
                                'Create your workspace to get started.'
                            )}
                        </span>
                        <Button size="sm" onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            Create workspace
                        </Button>
                    </section>
                )}

                {/* Workspaces other people invited them to. */}
                {workspaces.length > 1 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Your workspaces
                        </h2>
                        <ul className="flex flex-col">
                            {workspaces.map((workspace) => (
                                <li
                                    key={workspace.ulid}
                                    className="flex items-center justify-between border-t border-border py-3 text-sm"
                                >
                                    <span className="flex flex-col">
                                        <span className="font-medium">
                                            {workspace.name}
                                            {workspace.ulid === current?.ulid &&
                                                ' · current'}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {workspace.role_label} ·{' '}
                                            {workspace.state_label}
                                        </span>
                                    </span>
                                    {workspace.ulid !== current?.ulid && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/workspaces/${workspace.ulid}/switch`,
                                                )
                                            }
                                        >
                                            Switch
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {closed_workspaces.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Recently closed
                        </h2>
                        <ul className="flex flex-col">
                            {closed_workspaces.map((workspace) => (
                                <li
                                    key={workspace.ulid}
                                    className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm"
                                >
                                    <span className="flex flex-col">
                                        <span className="font-medium">
                                            {workspace.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Can be restored until{' '}
                                            {date(workspace.restorable_until)}.
                                            After that it is anonymised.
                                        </span>
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/workspaces/${workspace.ulid}/restore`,
                                            )
                                        }
                                    >
                                        <RotateCcw className="size-4" />
                                        Restore
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-muted-foreground">
                            A restored workspace comes back read-only, with all
                            its data. Choose a plan to start making changes
                            again.
                        </p>
                    </section>
                )}
            </div>

            <CreateWorkspaceDialog open={creating} onOpenChange={setCreating} />
        </AppLayout>
    );
}

/** The one date that matters right now: when the trial or the subscription ends. */
function KeyDate({
    workspace,
}: {
    workspace: NonNullable<SharedData['tenancy']>['current'];
}) {
    if (!workspace) return null;

    const end = workspace.current_period_end ?? workspace.trial_ends_at;

    if (workspace.cancel_at_period_end && end) {
        return <> · ends {date(end)}</>;
    }

    if (workspace.state === 'trialing' && workspace.trial_ends_at) {
        return <> · trial ends {date(workspace.trial_ends_at)}</>;
    }

    return null;
}

function NextStep({
    done,
    icon: Icon,
    href,
    label,
}: {
    done: boolean;
    icon: React.ComponentType<{ className?: string }>;
    href: string;
    label: string;
}) {
    return (
        <li>
            <Link
                href={href}
                className="flex items-center gap-2 hover:underline"
            >
                {done ? (
                    <Check className="size-4 text-muted-foreground" />
                ) : (
                    <Icon className="size-4" />
                )}
                <span className={done ? 'text-muted-foreground line-through' : ''}>
                    {label}
                </span>
            </Link>
        </li>
    );
}
