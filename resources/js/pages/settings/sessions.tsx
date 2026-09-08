import { Head, Link, router } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import AppLayout from '@/layouts/app-layout';

type BrowserSession = {
    ip_address: string | null;
    user_agent: string | null;
    // Unix seconds, straight off the sessions table.
    last_active_at: number;
    is_current: boolean;
};

type Props = {
    sessions: BrowserSession[];
    // False when the session driver keeps sessions somewhere unreadable.
    available: boolean;
    status?: string;
};

const formatWhen = (seconds: number) =>
    new Date(seconds * 1000).toLocaleString();

/**
 * Deliberately coarse. Parsing a user agent properly needs a library and a
 * constant stream of updates, and the question this screen answers is "do I
 * recognise this?" - for which the browser and platform are enough.
 */
function describe(agent: string | null) {
    if (!agent) return 'Unknown device';

    const browser = /Edg\//.test(agent)
        ? 'Edge'
        : /OPR\//.test(agent)
          ? 'Opera'
          : /Chrome\//.test(agent)
            ? 'Chrome'
            : /Safari\//.test(agent)
              ? 'Safari'
              : /Firefox\//.test(agent)
                ? 'Firefox'
                : 'Unknown browser';

    const platform = /iPhone|iPad/.test(agent)
        ? 'iOS'
        : /Android/.test(agent)
          ? 'Android'
          : /Mac OS X/.test(agent)
            ? 'macOS'
            : /Windows/.test(agent)
              ? 'Windows'
              : /Linux/.test(agent)
                ? 'Linux'
                : 'Unknown platform';

    return `${browser} on ${platform}`;
}

export default function Sessions({ sessions, available, status }: Props) {
    const [confirming, setConfirming] = useState(false);
    const others = sessions.filter((session) => !session.is_current).length;

    return (
        <AppLayout>
            <Head title="Sessions" />

            <div className="flex max-w-2xl flex-col gap-8">
                <div className="flex flex-wrap gap-4 text-sm">
                    <Link
                        href="/settings/profile"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Profile
                    </Link>
                    <Link
                        href="/settings/password"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Password
                    </Link>
                    <Link
                        href="/settings/two-factor"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Two-factor
                    </Link>
                    <span className="font-medium">Sessions</span>
                </div>

                <section className="flex flex-col gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Where you are signed in
                    </h1>
                    {status && (
                        <p className="text-sm text-muted-foreground">
                            {status}
                        </p>
                    )}

                    {!available ? (
                        <p className="text-sm text-muted-foreground">
                            This list is not available on this deployment&apos;s
                            session storage.
                        </p>
                    ) : (
                        <>
                            <ul className="flex flex-col">
                                {sessions.map((session, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center gap-3 border-t border-border py-3 text-sm"
                                    >
                                        <Monitor className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="flex flex-col">
                                            <span className="font-medium">
                                                {describe(session.user_agent)}
                                                {session.is_current &&
                                                    ' · this device'}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {session.ip_address ??
                                                    'Unknown IP'}{' '}
                                                · last active{' '}
                                                {formatWhen(
                                                    session.last_active_at,
                                                )}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            {others > 0 ? (
                                <div className="flex flex-col items-start gap-2 border-t border-border pt-4">
                                    <p className="text-sm text-muted-foreground">
                                        Signing out other sessions ends them
                                        straight away. You will stay signed in
                                        on this device.
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={() => setConfirming(true)}
                                    >
                                        Sign out other sessions
                                    </Button>
                                </div>
                            ) : (
                                <p className="border-t border-border pt-4 text-sm text-muted-foreground">
                                    This is the only device you are signed in
                                    on.
                                </p>
                            )}
                        </>
                    )}
                </section>
            </div>

            <AlertDialog
                open={confirming}
                onOpenChange={(next) => !next && setConfirming(false)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Sign out other sessions?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {others === 1
                                ? 'One other session'
                                : `${others} other sessions`}{' '}
                            will end immediately. We will ask for your password
                            first.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete('/settings/sessions', {
                                    preserveScroll: true,
                                    onFinish: () => setConfirming(false),
                                })
                            }
                        >
                            Sign them out
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
