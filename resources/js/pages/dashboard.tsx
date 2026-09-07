import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    must_verify_email: boolean;
};

/**
 * Section 2: one person, many workspaces, a different job in each - so this is
 * the switcher, and it reads across every workspace they belong to.
 */
export default function Dashboard({ must_verify_email }: Props) {
    // Same list the switcher uses - one source, shared on every page.
    const { tenancy } = usePage<SharedData>().props;
    const workspaces = tenancy?.available ?? [];
    const currentUlid = tenancy?.current?.ulid ?? null;
    const form = useForm({ name: '' });

    const create = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/workspaces', { onSuccess: () => form.reset('name') });
    };

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                {must_verify_email && (
                    <p className="rounded-md border border-border p-3 text-sm">
                        Please confirm your email address.{' '}
                        <Link
                            href="/verify-email"
                            className="underline underline-offset-4"
                        >
                            Resend the link
                        </Link>
                    </p>
                )}

                <h1 className="text-xl font-semibold tracking-tight">
                    Your workspaces
                </h1>

                {workspaces.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        You are not in a workspace yet. Create one to get
                        started.
                    </p>
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

                <form
                    onSubmit={create}
                    className="flex flex-wrap items-start gap-2 border-t border-border pt-4"
                >
                    <div className="flex flex-col gap-1">
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="New workspace name"
                            className="w-72"
                        />
                        {form.errors.name && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Create workspace
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
