import { Link } from '@inertiajs/react';
import { AlertTriangle, Clock, Lock } from 'lucide-react';
import type { CurrentWorkspace } from '@/types';

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
    if (!workspace) return null;

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
                data. Contact support to resolve it.
            </Banner>
        );
    }

    // Section 4: the trial auto-charges, so the date is never a surprise.
    if (workspace.state === 'trialing' && workspace.trial_ends_at) {
        const endsAt = new Date(workspace.trial_ends_at);
        const days = Math.max(
            0,
            Math.ceil((endsAt.getTime() - Date.now()) / 86_400_000),
        );

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
