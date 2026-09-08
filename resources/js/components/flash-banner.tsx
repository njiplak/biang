import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type { SharedData } from '@/types';

/**
 * One-off notices that outlive the redirect that raised them.
 *
 * These are the messages that explain why something the customer expected did
 * NOT happen: a trial refused because they have already had one, a card form
 * that could not be opened, a payment we could not match to this workspace.
 * Every one of them is raised on a request that then redirects, so the page
 * that finally renders is not the page that knows about it.
 *
 * Shared rather than per-page for the same reason the impersonation banner is:
 * a message the customer never sees is the same as no message at all, and the
 * page they land on is not always the one that flashed it.
 */
export default function FlashBanner() {
    const warning = usePage<SharedData>().props.flash?.warning;

    if (!warning) return null;

    return (
        <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
            <AlertTriangle className="size-4 shrink-0" />
            <span>{warning}</span>
        </div>
    );
}
