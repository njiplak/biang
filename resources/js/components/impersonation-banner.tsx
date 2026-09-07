import { router, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';
import type { SharedData } from '@/types';

/**
 * Section 10: "with an obvious banner saying so".
 *
 * Deliberately loud and deliberately fixed to the top of the viewport. A staff
 * member who forgets they are inside a customer account will take an action
 * believing it is their own, and the customer will see it as theirs.
 */
export default function ImpersonationBanner() {
    const { impersonation } = usePage<SharedData>().props;

    if (!impersonation) return null;

    return (
        <div className="sticky top-0 z-50 -mx-3 -mt-3 mb-1 flex flex-wrap items-center justify-between gap-2 border-b-2 border-amber-500 bg-amber-100 px-4 py-2 text-sm text-amber-950 sm:-mx-4 sm:-mt-4 md:-mx-6 md:-mt-6 dark:bg-amber-900 dark:text-amber-50">
            <div className="flex items-center gap-2">
                <Eye className="size-4 shrink-0" />
                <span>
                    You are viewing this as{' '}
                    <strong>{impersonation.user_name}</strong> (
                    {impersonation.user_email}). Everything you do here is
                    recorded against{' '}
                    <strong>{impersonation.admin_name ?? 'your account'}</strong>
                    .
                </span>
            </div>
            <Button
                size="sm"
                variant="outline"
                className="border-amber-700 bg-amber-50 text-amber-950 hover:bg-amber-200 dark:bg-amber-950 dark:text-amber-50"
                onClick={() => router.post(admin.impersonation.stop().url)}
            >
                Stop impersonating
            </Button>
        </div>
    );
}
