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
                subscription. Everything here is kept and stays readable —
                choose a plan to start making changes again.{' '}
                <Link href="/billing" className="underline underline-offset-4">
                    Choose a plan
                </Link>
            </Banner>
        );
    }

    // Section 7: over limit blocks writing and must name the limits.
    if (workspace.state === 'over_limit') {
        const features = workspace.over_limit_features?.join(', ') ?? 'a limit';

        return (
            <Banner tone="warn" icon={<Lock className="size-4 shrink-0" />}>
                This workspace is over its limit on <strong>{features}</strong>.
                Reading and exporting still work; writing resumes as soon as you
                upgrade or free up space.{' '}
                <Link href="/billing" className="underline underline-offset-4">
                    See plans
                </Link>
            </Banner>
        );
    }

    // Section 9: past due deliberately keeps full access.
    if (workspace.state === 'past_due') {
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

    // Section 4: the trial auto-charges, so the date is never a surprise.
    if (workspace.state === 'trialing' && workspace.trial_days_left !== null) {
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
