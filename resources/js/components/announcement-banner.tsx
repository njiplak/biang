import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Info, Megaphone, X } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

const TONE = {
    info: 'border-blue-300 bg-blue-50 text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100',
    warning:
        'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100',
    critical:
        'border-red-300 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950 dark:text-red-100',
} as const;

const ICON = {
    info: Info,
    warning: AlertTriangle,
    critical: Megaphone,
} as const;

/**
 * Section 10: "Announce maintenance or a new feature to all customers."
 *
 * A non-dismissible announcement has no close button on purpose - that is what
 * makes it usable for "we are down right now".
 */
export default function AnnouncementBanner() {
    const { announcements } = usePage<SharedData>().props;

    if (!announcements?.length) return null;

    return (
        <div className="flex flex-col gap-2">
            {announcements.map((announcement) => {
                const Icon = ICON[announcement.severity] ?? Info;

                return (
                    <div
                        key={announcement.id}
                        className={cn(
                            'flex items-start gap-2 rounded-md border p-3 text-sm',
                            TONE[announcement.severity] ?? TONE.info,
                        )}
                    >
                        <Icon className="mt-0.5 size-4 shrink-0" />
                        <div className="flex-1">
                            <p className="font-medium">{announcement.title}</p>
                            <p className="whitespace-pre-line">
                                {announcement.body}
                            </p>
                        </div>
                        {announcement.is_dismissible && (
                            <button
                                type="button"
                                aria-label="Dismiss"
                                className="shrink-0 rounded p-1 hover:bg-black/5 dark:hover:bg-white/10"
                                onClick={() =>
                                    router.post(
                                        `/announcements/${announcement.id}/dismiss`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <X className="size-4" />
                            </button>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
