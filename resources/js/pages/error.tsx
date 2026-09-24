import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';

type Props = { status: 403 | 404 | 500 | 503 };

const COPY: Record<Props['status'], { title: string; description: string }> = {
    403: {
        title: 'You do not have access to this page',
        // The usual cause is a role that cannot open it (billing, settings),
        // so name who can help rather than leaving them guessing.
        description:
            'Your role in this workspace does not include this page. If you need it, ask an owner of the workspace.',
    },
    404: {
        title: 'We could not find that page',
        description:
            'The link may be out of date, or the thing it pointed to has been moved or closed.',
    },
    500: {
        title: 'Something went wrong on our side',
        description:
            'Nothing you did caused this. Please try again in a moment.',
    },
    503: {
        title: 'We are down for maintenance',
        description: 'We will be back shortly. Please try again in a few minutes.',
    },
};

/**
 * Rendered for framework errors outside local and test runs (bootstrap/app.php).
 * Shared props can be missing here - a 404 for an unknown URL never runs the
 * web middleware - so every read of them is optional.
 */
export default function ErrorPage({ status }: Props) {
    const props = usePage<Partial<SharedData>>().props;
    const signedIn = Boolean(props.auth?.user);
    const supportUrl = props.support?.url ?? null;
    const copy = COPY[status] ?? COPY[500];

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 text-center">
            <Head title={copy.title} />

            <p className="text-sm font-medium text-muted-foreground">
                Error {status}
            </p>
            <h1 className="max-w-md text-2xl font-semibold tracking-tight">
                {copy.title}
            </h1>
            <p className="max-w-md text-sm text-muted-foreground">
                {copy.description}
            </p>

            <div className="flex flex-wrap items-center justify-center gap-2">
                <Button variant="outline" onClick={() => window.history.back()}>
                    Go back
                </Button>
                <Button asChild>
                    <Link href={signedIn ? '/dashboard' : '/'}>
                        {signedIn ? 'Go to dashboard' : 'Go to home page'}
                    </Link>
                </Button>
            </div>

            {supportUrl && (
                <p className="text-sm text-muted-foreground">
                    Still stuck?{' '}
                    <a href={supportUrl} className="underline underline-offset-4">
                        Contact support
                    </a>
                </p>
            )}
        </div>
    );
}
