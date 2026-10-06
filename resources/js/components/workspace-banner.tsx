import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Lock } from 'lucide-react';
import type { CurrentWorkspace, SharedData } from '@/types';

/**
 * Spec sections 6 and 9 promise the customer is told plainly what is happening
 * and what removes it. Each case names the specific problem - a generic
 * "something is wrong" is what turns a card problem into a cancellation.
 */
export default function WorkspaceBanner({
    workspace,
}: {
    workspace: CurrentWorkspace | null;
}) {
    // Set by staff in the console. Null until someone fills it in, which is
    // why every use below has a plain-text fallback.
    const supportUrl = usePage<SharedData>().props.support?.url ?? null;

    if (!workspace) return null;

    /*
     * No free tier. A workspace nobody is paying for keeps everything it has,
     * keeps it readable, and cannot be changed - so the banner has to say all
     * three things: what stopped, what is kept, and the one way back.
     *
     * Above over-limit deliberately, matching Workspace::displayState(): naming
     * a seat limit here would point at a problem this customer cannot fix and
     * hide the one they can.
     */
    if (workspace.state === 'expired') {
        return (
            <Banner tone="warn" icon={<Lock className="size-4 shrink-0" />}>
                This workspace is read-only because there is no active
                subscription. Everything here is kept and stays readable.{' '}
                <BillingAction
                    workspace={workspace}
                    label="Choose a plan"
                    ask="choose a plan"
                />
            </Banner>
        );
    }

    // Section 7: over limit blocks writing and must name the limits.
    if (workspace.state === 'over_limit') {
        const features = workspace.over_limit_features?.join(', ') ?? 'a limit';

        return (
            <Banner tone="warn" icon={<Lock className="size-4 shrink-0" />}>
                This workspace is over its limit on <strong>{features}</strong>.
                Reading and exporting still work; writing resumes as soon as the
                plan is upgraded or space is freed up.{' '}
                <BillingAction
                    workspace={workspace}
                    label="See plans"
                    ask="upgrade the plan"
                />
            </Banner>
        );
    }

    // Section 9: past due deliberately keeps full access, and only the people
    // who handle billing hear about the company's card problems.
    if (workspace.state === 'past_due' && workspace.can_manage_billing) {
        return (
            <Banner
                tone="warn"
                icon={<AlertTriangle className="size-4 shrink-0" />}
            >
                A payment did not go through. Everything keeps working for now —
                please update your card to avoid interruption.{' '}
                <Link href="/billing" className="underline underline-offset-4">
                    Go to billing
                </Link>
            </Banner>
        );
    }

    if (workspace.state === 'suspended') {
        return (
            <Banner tone="danger" icon={<Lock className="size-4 shrink-0" />}>
                This workspace is suspended. You can still read and export your
                data.{' '}
                {supportUrl ? (
                    <>
                        <a
                            href={supportUrl}
                            className="underline underline-offset-4"
                        >
                            Contact support
                        </a>{' '}
                        to resolve it.
                    </>
                ) : (
                    'Contact support to resolve it.'
                )}
            </Banner>
        );
    }

    /*
     * A cancellation scheduled for the period end. Everyone sees it, because
     * everyone loses the ability to make changes on that date; only billing
     * roles are offered the way to undo it.
     */
    const endsAt = workspace.current_period_end ?? workspace.trial_ends_at;

    if (
        workspace.cancel_at_period_end &&
        endsAt &&
        (workspace.state === 'active' || workspace.state === 'trialing')
    ) {
        return (
            <Banner tone="info" icon={<Clock className="size-4 shrink-0" />}>
                The subscription for this workspace ends on{' '}
                {new Date(endsAt).toLocaleDateString()}. After that it becomes
                read-only — nothing is deleted.{' '}
                {workspace.can_manage_billing && (
                    <Link
                        href="/billing"
                        className="underline underline-offset-4"
                    >
                        Resume subscription
                    </Link>
                )}
            </Banner>
        );
    }

    // Section 4: the trial auto-charges, so the date is never a surprise.
    if (
        workspace.state === 'trialing' &&
        workspace.trial_days_left !== null &&
        workspace.can_manage_billing
    ) {
        const days = workspace.trial_days_left;

        return (
            <Banner tone="info" icon={<Clock className="size-4 shrink-0" />}>
                Your trial ends in {days} {days === 1 ? 'day' : 'days'} and your
                card will be charged automatically.{' '}
                <Link href="/billing" className="underline underline-offset-4">
                    Review your plan
                </Link>
            </Banner>
        );
    }

    return null;
}

/**
 * The way forward from a billing problem. Billing roles get the link; anyone
 * else would get a 403 from it, so they are told who can fix it instead.
 */
function BillingAction({
    workspace,
    label,
    ask,
}: {
    workspace: CurrentWorkspace;
    label: string;
    ask: string;
}) {
    if (workspace.can_manage_billing) {
        return (
            <Link href="/billing" className="underline underline-offset-4">
                {label}
            </Link>
        );
    }

    const who = workspace.owner_name
        ? `${workspace.owner_name} (the workspace owner)`
        : 'a workspace owner';

    return (
        <>
            Ask {who} to {ask}.
        </>
    );
}

function Banner({
    tone,
    icon,
    children,
}: {
    tone: 'info' | 'warn' | 'danger';
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    const toneClass = {
        info: 'border-border bg-muted/50',
        warn: 'border-amber-500/40 bg-amber-500/10',
        danger: 'border-destructive/40 bg-destructive/10',
    }[tone];

    return (
        <div
            className={`flex items-start gap-2 rounded-md border px-3 py-2 text-sm ${toneClass}`}
        >
            {icon}
            <span>{children}</span>
        </div>
    );
}
