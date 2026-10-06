import { Link, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import type { SharedData } from '@/types';

/**
 * Section 5: when the workspace hits a limit, say so plainly and point at the
 * way out. Billing roles get the link; anyone else is told who can act, since
 * /billing would refuse them.
 */
export default function UpgradePrompt({ children }: { children: React.ReactNode }) {
    const current = usePage<SharedData>().props.tenancy?.current ?? null;

    return (
        <div className="flex items-start gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
            <Sparkles className="mt-0.5 size-4 shrink-0" />
            <span>
                {children}{' '}
                {current?.can_manage_billing ? (
                    <Link href="/billing" className="underline underline-offset-4">
                        See plans
                    </Link>
                ) : (
                    <>
                        Ask{' '}
                        {current?.owner_name
                            ? `${current.owner_name} (the workspace owner)`
                            : 'a workspace owner'}{' '}
                        to upgrade.
                    </>
                )}
            </span>
        </div>
    );
}
