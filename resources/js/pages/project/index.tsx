import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import UpgradePrompt from '@/components/upgrade-prompt';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEntitlement } from '@/hooks/use-entitlement';
import AppLayout from '@/layouts/app-layout';

type Project = {
    ulid: string;
    name: string;
    description: string | null;
    created_by: string | null;
    created_at: string;
};

type Props = {
    projects: Project[];
    // limit null means unlimited
    usage: { used: number; limit: number | null };
    // WorkspacePolicy::write - the person's role AND the workspace's state
    can_write: boolean;
};

/**
 * The example product feature. It shows the three things every real feature
 * needs: the write gate (can_write), the plan limit (useEntitlement plus the
 * server's own check), and a plain way out when the limit is reached.
 */
export default function ProjectIndex({ projects, usage, can_write }: Props) {
    const entitlement = useEntitlement('projects');
    const atLimit = !entitlement.allows(usage.used + 1);
    const form = useForm({ name: '', description: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/projects', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AppLayout>
            <Head title="Projects" />

            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Projects
                    </h1>
                    <span className="text-sm text-muted-foreground">
                        {usage.used} of {usage.limit ?? 'unlimited'} used
                    </span>
                </div>

                {/* Read-only and over-limit workspaces are already explained by
                    the banner above; this only covers the plan ceiling. */}
                {can_write && atLimit && (
                    <UpgradePrompt>
                        {entitlement.included
                            ? `This plan includes ${entitlement.limit} projects, and all of them are in use.`
                            : 'Projects are not included in this plan.'}
                    </UpgradePrompt>
                )}

                {can_write && !atLimit && (
                    <form
                        onSubmit={submit}
                        className="flex flex-col gap-3 rounded-md border border-border p-4"
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="name">New project</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                maxLength={120}
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="description">
                                Description{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                maxLength={2000}
                            />
                            <InputError message={form.errors.description} />
                        </div>
                        {/* LimitReached and other domain refusals arrive here. */}
                        <InputError
                            message={
                                (form.errors as Record<string, string>).errors
                            }
                        />
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Create project
                            </Button>
                        </div>
                    </form>
                )}

                {projects.length === 0 ? (
                    <p className="border-t border-border pt-4 text-sm text-muted-foreground">
                        No projects yet.
                    </p>
                ) : (
                    <ul className="flex flex-col">
                        {projects.map((project) => (
                            <li
                                key={project.ulid}
                                className="flex items-start justify-between gap-2 border-t border-border py-3 text-sm"
                            >
                                <span className="flex flex-col">
                                    <span className="font-medium">
                                        {project.name}
                                    </span>
                                    {project.description && (
                                        <span className="text-muted-foreground">
                                            {project.description}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {project.created_by
                                            ? `${project.created_by} · `
                                            : ''}
                                        {new Date(
                                            project.created_at,
                                        ).toLocaleDateString()}
                                    </span>
                                </span>
                                {can_write && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Delete ${project.name}`}
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    `Delete ${project.name}?`,
                                                )
                                            ) {
                                                router.delete(
                                                    `/projects/${project.ulid}`,
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
