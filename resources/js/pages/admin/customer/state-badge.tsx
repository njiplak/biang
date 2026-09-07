import { cn } from '@/lib/utils';
import type { WorkspaceState } from '@/types/customer';

/**
 * Spec section 6's single state. The colour carries the same precedence the
 * enum does - suspended and deleted read as stops, over limit and past due as
 * warnings - so a directory row says what is wrong without being read.
 */
const TONE: Record<WorkspaceState, string> = {
    free: 'bg-neutral-100 text-neutral-700 ring-neutral-600/20 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-400/20',
    trialing:
        'bg-blue-50 text-blue-700 ring-blue-700/20 dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-400/20',
    active: 'bg-green-50 text-green-700 ring-green-700/20 dark:bg-green-950 dark:text-green-300 dark:ring-green-400/20',
    past_due:
        'bg-amber-50 text-amber-800 ring-amber-700/20 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-400/20',
    over_limit:
        'bg-orange-50 text-orange-800 ring-orange-700/20 dark:bg-orange-950 dark:text-orange-300 dark:ring-orange-400/20',
    suspended:
        'bg-red-50 text-red-700 ring-red-700/20 dark:bg-red-950 dark:text-red-300 dark:ring-red-400/20',
    deleted:
        'bg-neutral-200 text-neutral-600 ring-neutral-600/20 dark:bg-neutral-900 dark:text-neutral-400 dark:ring-neutral-500/20',
};

export function StateBadge({
    state,
    label,
    className,
}: {
    state: WorkspaceState;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset',
                TONE[state],
                className,
            )}
        >
            {label}
        </span>
    );
}
