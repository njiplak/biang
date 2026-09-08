import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import type { Base } from '@/types/base';

type MemberRow = {
    id: number;
    role: string;
    joined_at: string | null;
    user: { id: number; name: string; email: string } | null;
    // Answered per row by WorkspaceMemberPolicy, because the rules are not
    // uniform: the last owner may be neither demoted nor removed, and anyone
    // may remove themselves whatever their role.
    can_change_role: boolean;
    can_assign_owner: boolean;
    can_remove: boolean;
};

type Invitation = {
    id: string;
    email: string;
    role: string;
    expires_at: string | null;
};

type SeatOffer = {
    name: string;
    amount_minor: number;
    currency: string;
    interval: string;
};

type Props = {
    workspace: { ulid: string; name: string; slug: string };
    // invite: role permits it AND the workspace is in a state that can write
    // invite_blocked_by: which of those two said no, null while it is allowed
    // export: section 6 keeps this on even while suspended or over limit
    can: {
        invite: boolean;
        invite_blocked_by: 'role' | 'state' | null;
        export: boolean;
    };
    invitations: Invitation[];
    // limit is null when the plan grants unlimited seats
    seats: { used: number; limit: number | null };
    // section 7's priced offer; null without a subscription, where the answer is a plan
    seat_offer: SeatOffer | null;
    // inviting is gated on a verified address (section 5)
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

const ROLES = ['owner', 'admin', 'billing_manager', 'member', 'viewer'];

// Matches WorkspaceRole::label(). Only billing_manager actually needs it, but
// mapping the lot keeps the table and the picker reading the same way.
const ROLE_LABELS: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    billing_manager: 'Billing manager',
    member: 'Member',
    viewer: 'Viewer',
};

const roleLabel = (role: string) => ROLE_LABELS[role] ?? role;

const helper = createColumnHelper<MemberRow>();

export default function MembersIndex({
    workspace,
    can,
    invitations,
    seats,
    seat_offer,
}: Props) {
    const page = usePage<SharedData>();
    const form = useForm({ email: '', role: 'member' });
    // Domain failures (SeatLimitReached) arrive under a generic `errors` key
    // from the exception handler, outside this form's own typed error keys.
    const pageErrors = page.props.errors as Record<string, string> | undefined;

    // Bumping this tells NextTable to refetch after a mutation.
    const [refresh, setRefresh] = useState(0);
    const reload = () => setRefresh((n) => n + 1);

    // Confirmation targets. Removing someone and revoking an invitation both
    // used to happen on a single click, and neither is undoable from here.
    const [removing, setRemoving] = useState<MemberRow | null>(null);
    const [revoking, setRevoking] = useState<Invitation | null>(null);

    const load = useCallback(
        async (params: Record<string, unknown>): Promise<Base<MemberRow[]>> => {
            const query = new URLSearchParams(
                Object.entries(params)
                    .filter(
                        ([, value]) =>
                            value !== undefined &&
                            value !== null &&
                            value !== '',
                    )
                    .map(([key, value]) => [key, String(value)]),
            ).toString();

            const response = await window.fetch(
                `/workspaces/${workspace.ulid}/members/fetch?${query}`,
                { headers: { Accept: 'application/json' } },
            );

            return response.json();
        },
        [workspace.ulid],
    );

    const columns: ColumnDef<MemberRow, any>[] = [
        helper.accessor((row) => row.user?.name ?? '—', {
            id: 'name',
            header: 'Name',
            enableColumnFilter: false,
        }),
        helper.accessor((row) => row.user?.email ?? '—', {
            id: 'email',
            header: 'Email',
            enableColumnFilter: false,
        }),
        helper.accessor('role', {
            id: 'role',
            header: 'Role',
            enableColumnFilter: false,
            cell: (ctx) => {
                const row = ctx.row.original;

                // Section 3: the last owner cannot be demoted, and a viewer
                // manages nobody. Plain text rather than a picker that 403s.
                if (!row.can_change_role) {
                    return (
                        <span className="text-sm">
                            {roleLabel(ctx.getValue())}
                        </span>
                    );
                }

                // Only an owner may create another owner.
                const options = row.can_assign_owner
                    ? ROLES
                    : ROLES.filter((role) => role !== 'owner');

                return (
                    // Controlled on the server's answer, not on what was
                    // picked. Uncontrolled, a change the server refused stayed
                    // on screen as though it had been applied.
                    <select
                        value={ctx.getValue()}
                        onChange={(e) =>
                            router.put(
                                `/members/${row.id}`,
                                { role: e.target.value },
                                { preserveScroll: true, onSuccess: reload },
                            )
                        }
                        className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        {options.map((role) => (
                            <option key={role} value={role}>
                                {roleLabel(role)}
                            </option>
                        ))}
                    </select>
                );
            },
        }),
        helper.display({
            id: 'actions',
            header: '',
            enableHiding: false,
            cell: (ctx) =>
                ctx.row.original.can_remove ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setRemoving(ctx.row.original)}
                    >
                        Remove
                    </Button>
                ) : null,
        }),
    ];

    const invite = (event: React.FormEvent, addSeat = false) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, add_seat: addSeat }));
        form.post(`/workspaces/${workspace.ulid}/invitations`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('email');
                reload();
            },
        });
    };

    // Only worth saying to someone who could act on it. Pricing a seat for a
    // viewer, or for a read-only workspace, offers a fix they cannot apply.
    const atLimit =
        can.invite && seats.limit !== null && seats.used >= seats.limit;

    return (
        <AppLayout>
            <Head title={`${workspace.name} · Members`} />

            <div className="flex flex-col gap-6 p-6">
                {/* A pending invitation reserves a seat, so this count already
                    includes anyone who has not accepted yet. */}
                <NextTable<MemberRow>
                    title="Members"
                    description={`${seats.used} of ${seats.limit ?? 'unlimited'} seats used`}
                    id="id"
                    columns={columns}
                    load={load}
                    params={{ _refresh: refresh }}
                    searchPlaceholder="Search members..."
                    actionComponent={
                        <div className="flex items-start gap-2">
                            {can.invite && (
                                <form
                                    onSubmit={invite}
                                    className="flex items-start gap-2"
                                >
                                    <Input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="colleague@example.com"
                                        className="h-8 w-56"
                                    />
                                    <select
                                        value={form.data.role}
                                        onChange={(e) =>
                                            form.setData('role', e.target.value)
                                        }
                                        className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                                    >
                                        {ROLES.filter(
                                            (role) => role !== 'owner',
                                        ).map((role) => (
                                            <option key={role} value={role}>
                                                {roleLabel(role)}
                                            </option>
                                        ))}
                                    </select>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={form.processing}
                                    >
                                        Invite
                                    </Button>
                                </form>
                            )}

                            {/* Section 6: read-only, over limit and suspended
                                all keep export. It is their own data. */}
                            {can.export && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={`/workspaces/${workspace.ulid}/members/export`}
                                    >
                                        <Download className="size-4" />
                                        Export
                                    </a>
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Why the invite form is not there. Two different problems
                    with two different people to talk to, and the server says
                    which - see MemberController::inviteBlockedBy. */}
                {!can.invite && (
                    <p className="text-sm text-muted-foreground">
                        {can.invite_blocked_by === 'state' ? (
                            <>
                                Inviting is off while this workspace is
                                read-only.{' '}
                                <Link
                                    href="/billing"
                                    className="underline underline-offset-4"
                                >
                                    Choose a plan
                                </Link>{' '}
                                to add people again.
                            </>
                        ) : (
                            'Only owners and admins can invite people to this workspace.'
                        )}
                    </p>
                )}

                {/* Section 7: "A seat limit should be a sales moment, not a
                    wall." At the limit we price the fix rather than refusing. */}
                {atLimit && seat_offer && (
                    <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                        <span>
                            All {seats.limit} seats are taken. Add one for{' '}
                            <strong>
                                {money(
                                    seat_offer.amount_minor,
                                    seat_offer.currency,
                                )}
                                /{seat_offer.interval}
                            </strong>{' '}
                            and the invitation goes out straight away.
                        </span>
                        <Button
                            size="sm"
                            disabled={!form.data.email || form.processing}
                            onClick={(e) => invite(e, true)}
                        >
                            Add a seat and invite
                        </Button>
                    </div>
                )}

                {/* Section 12: the free tier has no payment account, so there is
                    no seat to sell - the offer is a plan. */}
                {atLimit && !seat_offer && (
                    <p className="text-sm text-muted-foreground">
                        All {seats.limit} seats are taken.{' '}
                        <Link
                            href="/billing"
                            className="underline underline-offset-4"
                        >
                            Choose a plan
                        </Link>{' '}
                        to add more.
                    </p>
                )}
                {form.errors.email && (
                    <p className="text-sm text-destructive">
                        {form.errors.email}
                    </p>
                )}
                {pageErrors?.errors && (
                    <p className="text-sm text-destructive">
                        {pageErrors.errors}
                    </p>
                )}

                {invitations.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Pending invitations
                        </h2>
                        {invitations.map((invitation) => (
                            <div
                                key={invitation.id}
                                className="flex items-center justify-between border-t border-border py-2 text-sm"
                            >
                                <span>
                                    {invitation.email} ·{' '}
                                    {roleLabel(invitation.role)}
                                </span>
                                {/* Both are gated on inviteMembers, the same
                                    policy that put the form above out of
                                    reach. */}
                                {can.invite && (
                                    <span className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/invitations/${invitation.id}/resend`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Resend
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setRevoking(invitation)
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Removing someone frees their seat and takes their access with
                it. Getting them back means a fresh invitation they have to
                accept, so this is not a one-click action. */}
            <ConfirmDialog
                open={removing !== null}
                onCancel={() => setRemoving(null)}
                title="Remove this person?"
                body={
                    <>
                        {removing?.user?.name ?? 'This person'} loses access to{' '}
                        {workspace.name} immediately and their seat is freed.
                        Bringing them back means inviting them again.
                    </>
                }
                confirmLabel="Remove"
                onConfirm={() => {
                    if (!removing) return;

                    router.delete(`/members/${removing.id}`, {
                        preserveScroll: true,
                        onSuccess: reload,
                        onFinish: () => setRemoving(null),
                    });
                }}
            />

            <ConfirmDialog
                open={revoking !== null}
                onCancel={() => setRevoking(null)}
                title="Revoke this invitation?"
                body={
                    <>
                        The link already emailed to {revoking?.email} stops
                        working. You can invite them again at any time.
                    </>
                }
                confirmLabel="Revoke"
                onConfirm={() => {
                    if (!revoking) return;

                    router.delete(`/invitations/${revoking.id}`, {
                        preserveScroll: true,
                        onSuccess: reload,
                        onFinish: () => setRevoking(null),
                    });
                }}
            />
        </AppLayout>
    );
}

/**
 * Named copy rather than the shared DeleteDialog, whose wording is fixed at
 * "you want to delete data, this action is irreversible" - true of neither of
 * these. A revoked invitation can be sent again, and a removed member can be
 * invited back; saying otherwise would be a scarier lie than no dialog at all.
 */
function ConfirmDialog({
    open,
    title,
    body,
    confirmLabel,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    title: string;
    body: React.ReactNode;
    confirmLabel: string;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onCancel();
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{body}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
