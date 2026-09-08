import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';

type Member = {
    id: number;
    name: string | null;
    email: string | null;
    role: string;
    is_self: boolean;
};

type Props = {
    workspace: { ulid: string; name: string; slug: string };
    // rename_blocked_by names which half of the policy said no: the person's
    // role, or the workspace's state. Null while renaming is allowed.
    can: {
        rename: boolean;
        rename_blocked_by: 'role' | 'state' | null;
        transfer: boolean;
        close: boolean;
    };
    members: Member[];
};

export default function WorkspaceSettings({ workspace, can, members }: Props) {
    const page = usePage<SharedData>();
    const rename = useForm({ name: workspace.name });
    const [successor, setSuccessor] = useState<string>('');
    const [confirmName, setConfirmName] = useState('');
    const pageErrors = page.props.errors as Record<string, string> | undefined;

    const self = members.find((m) => m.is_self);
    const candidates = members.filter((m) => !m.is_self);

    return (
        <AppLayout>
            <Head title={`${workspace.name} · Settings`} />

            <div className="flex max-w-2xl flex-col gap-8">
                <h1 className="text-xl font-semibold tracking-tight">
                    Workspace settings
                </h1>

                {pageErrors?.errors && (
                    <p className="text-sm text-destructive">
                        {pageErrors.errors}
                    </p>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Name
                    </h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            rename.put(`/workspaces/${workspace.ulid}`, {
                                preserveScroll: true,
                            });
                        }}
                        className="flex items-start gap-2"
                    >
                        <div className="flex flex-col gap-1">
                            <Input
                                value={rename.data.name}
                                onChange={(e) =>
                                    rename.setData('name', e.target.value)
                                }
                                disabled={!can.rename}
                                className="w-80"
                            />
                            {rename.errors.name && (
                                <p className="text-xs text-destructive">
                                    {rename.errors.name}
                                </p>
                            )}
                        </div>
                        <Button
                            type="submit"
                            disabled={!can.rename || rename.processing}
                        >
                            Save
                        </Button>
                    </form>
                    {/* Two reasons reach the same disabled field and they need
                        different people: an admin, or a card. The server says
                        which, because WorkspacePolicy::update collapses both
                        into one boolean. */}
                    {!can.rename && (
                        <p className="text-xs text-muted-foreground">
                            {can.rename_blocked_by === 'state'
                                ? 'This workspace is read-only, so its name cannot be changed until there is an active plan.'
                                : 'Only owners and admins can rename this workspace.'}
                        </p>
                    )}
                </section>

                {/* Section 3: the last owner cannot leave until they hand over. */}
                {can.transfer && (
                    <section className="flex flex-col gap-3 border-t border-border pt-6">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Transfer ownership
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            The new owner takes over billing and closing. You
                            become an admin.
                        </p>
                        <div className="flex items-center gap-2">
                            <select
                                value={successor}
                                onChange={(e) => setSuccessor(e.target.value)}
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option value="">Choose a member…</option>
                                {candidates.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name} ({member.email})
                                    </option>
                                ))}
                            </select>
                            <Button
                                variant="outline"
                                disabled={!successor}
                                onClick={() =>
                                    router.post(
                                        `/workspaces/${workspace.ulid}/transfer`,
                                        {
                                            member_id: Number(successor),
                                        },
                                    )
                                }
                            >
                                Transfer
                            </Button>
                        </div>
                    </section>
                )}

                {self && (
                    <section className="flex flex-col gap-3 border-t border-border pt-6">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Leave
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            You will lose access to this workspace. If you are
                            the last owner, hand ownership over first.
                        </p>
                        <div>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.delete(`/members/${self.id}`)
                                }
                            >
                                Leave workspace
                            </Button>
                        </div>
                    </section>
                )}

                {/* Section 6: closing deletes nothing. It is recoverable, and
                    the data stays through the retention window. */}
                {can.close && (
                    <section className="flex flex-col gap-3 border-t border-destructive/30 pt-6">
                        <h2 className="text-sm font-medium text-destructive">
                            Close this workspace
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Billing stops and nobody can open it. Nothing is
                            deleted straight away — it stays recoverable for the
                            retention window, then is anonymised.
                        </p>
                        <div className="flex flex-col gap-2">
                            <Label
                                htmlFor="confirm"
                                className="text-xs text-muted-foreground"
                            >
                                Type <strong>{workspace.name}</strong> to
                                confirm
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="confirm"
                                    value={confirmName}
                                    onChange={(e) =>
                                        setConfirmName(e.target.value)
                                    }
                                    className="w-80"
                                />
                                <Button
                                    variant="destructive"
                                    disabled={confirmName !== workspace.name}
                                    onClick={() =>
                                        router.delete(
                                            `/workspaces/${workspace.ulid}`,
                                        )
                                    }
                                >
                                    Close workspace
                                </Button>
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
