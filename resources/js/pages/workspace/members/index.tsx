import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { Base } from '@/types/base';

type MemberRow = {
    id: number;
    role: string;
    joined_at: string | null;
    user: { id: number; name: string; email: string } | null;
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
    invitations: Invitation[];
    // limit is null when the plan grants unlimited seats
    seats: { used: number; limit: number | null };
    // section 7's priced offer; null on the free tier, where the answer is an upgrade
    seat_offer: SeatOffer | null;
    // inviting is gated on a verified address (section 5)
    must_verify_email: boolean;
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

const ROLES = ['owner', 'admin', 'billing_manager', 'member', 'viewer'];
const helper = createColumnHelper<MemberRow>();

export default function MembersIndex({
    workspace,
    invitations,
    seats,
    seat_offer,
    must_verify_email,
}: Props) {
    const form = useForm({ email: '', role: 'member' });
    // Domain failures (SeatLimitReached) arrive under a generic `errors` key
    // from the exception handler, outside this form's own typed error keys.
    const pageErrors = usePage().props.errors as
        | Record<string, string>
        | undefined;

    // Bumping this tells NextTable to refetch after a mutation.
    const [refresh, setRefresh] = useState(0);
    const reload = () => setRefresh((n) => n + 1);

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
            cell: (ctx) => (
                <select
                    defaultValue={ctx.getValue()}
                    onChange={(e) =>
                        router.put(
                            `/members/${ctx.row.original.id}`,
                            { role: e.target.value },
                            { preserveScroll: true, onSuccess: reload },
                        )
                    }
                    className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    {ROLES.map((role) => (
                        <option key={role} value={role}>
                            {role}
                        </option>
                    ))}
                </select>
            ),
        }),
        helper.display({
            id: 'actions',
            header: '',
            enableHiding: false,
            cell: (ctx) => (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(`/members/${ctx.row.original.id}`, {
                            preserveScroll: true,
                            onSuccess: reload,
                        })
                    }
                >
                    Remove
                </Button>
            ),
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

    const atLimit = seats.limit !== null && seats.used >= seats.limit;

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
                        <form
                            onSubmit={invite}
                            className="flex items-start gap-2"
                        >
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                placeholder="colleague@example.com"
                                className="h-8 w-56"
                                disabled={must_verify_email}
                            />
                            <select
                                value={form.data.role}
                                onChange={(e) =>
                                    form.setData('role', e.target.value)
                                }
                                className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                            >
                                {ROLES.filter((role) => role !== 'owner').map(
                                    (role) => (
                                        <option key={role} value={role}>
                                            {role}
                                        </option>
                                    ),
                                )}
                            </select>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing || must_verify_email}
                            >
                                Invite
                            </Button>
                        </form>
                    }
                />

                {/* Section 5: an unproved address cannot send mail in the
                    customer's name. Said here rather than only enforced, so a
                    disabled form is an explanation instead of a dead end. */}
                {must_verify_email && (
                    <p className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
                        Confirm your email address before inviting anyone.{' '}
                        <Link
                            href="/verify-email"
                            className="underline underline-offset-4"
                        >
                            Resend the link
                        </Link>
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
                            disabled={
                                !form.data.email ||
                                form.processing ||
                                must_verify_email
                            }
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
                                    {invitation.email} · {invitation.role}
                                </span>
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
                                            router.delete(
                                                `/invitations/${invitation.id}`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: reload,
                                                },
                                            )
                                        }
                                    >
                                        Revoke
                                    </Button>
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
