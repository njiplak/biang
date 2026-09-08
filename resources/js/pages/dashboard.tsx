import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Sparkles } from 'lucide-react';
import { useState } from 'react';
import CreateWorkspaceDialog from '@/components/create-workspace-dialog';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Button } from '@/components/ui/button';

type Props = {
    // The plan carried through signup from the marketing site, still waiting
    // to be acted on. Null once it has been spent, or if none was chosen.
    pending_plan: { name: string; trial_days: number | null } | null;
};

/**
 * Section 2: one person, many workspaces, a different job in each - so this is
 * the switcher, and it reads across every workspace they belong to.
 */
export default function Dashboard({ pending_plan }: Props) {
    // Same list the switcher uses - one source, shared on every page.
    const { tenancy } = usePage<SharedData>().props;
    const workspaces = tenancy?.available ?? [];
    const currentUlid = tenancy?.current?.ulid ?? null;
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                {/* Section 11 carries the chosen plan through signup, and
                    section 5 Path A puts "name your workspace" between that
                    choice and the card form. Without this the step is
                    invisible: the plan sits in the session and the page says
                    only that you have no workspace. */}
                {pending_plan && (
                    <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                        <Sparkles className="size-4 shrink-0" />
                        <span>
                            You chose the <strong>{pending_plan.name}</strong>{' '}
                            plan.
                            {pending_plan.trial_days === null
                                ? ' Create a workspace to continue to checkout.'
                                : ` Create a workspace to start your ${pending_plan.trial_days}-day trial.`}
                        </span>
                        <Button size="sm" onClick={() => setCreating(true)}>
                            Create workspace
                        </Button>
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Your workspaces
                    </h1>
                    {/* Only once there is a list to sit above. With nothing on
                        the page the empty state owns the call to action, and
                        two of the same button is worse than one. */}
                    {workspaces.length > 0 && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            New workspace
                        </Button>
                    )}
                </div>

                {workspaces.length === 0 ? (
                    <div className="flex flex-col items-start gap-3 border-t border-border pt-4">
                        <p className="text-sm text-muted-foreground">
                            You are not in a workspace yet. Create one to get
                            started.
                        </p>
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            Create workspace
                        </Button>
                    </div>
                ) : (
                    <ul className="flex flex-col">
                        {workspaces.map((workspace) => (
                            <li
                                key={workspace.ulid}
                                className="flex items-center justify-between border-t border-border py-3 text-sm"
                            >
                                <span className="flex flex-col">
                                    <span className="font-medium">
                                        {workspace.name}
                                        {workspace.ulid === currentUlid &&
                                            ' · current'}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {workspace.role_label} ·{' '}
                                        {workspace.state_label}
                                    </span>
                                </span>
                                <span className="flex gap-1">
                                    {workspace.ulid !== currentUlid && (
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
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/workspaces/${workspace.ulid}/members`}
                                        >
                                            Open
                                        </Link>
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <CreateWorkspaceDialog open={creating} onOpenChange={setCreating} />
        </AppLayout>
    );
}
