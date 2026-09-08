import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

type Props = { status?: string; email: string };

export default function VerifyEmail({ status, email }: Props) {
    const { post, processing } = useForm({});

    const resend = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <AuthLayout
            title="Check your email"
            description="Confirm your address to finish setting up your account"
        >
            <Head title="Verify email" />

            <div className="flex flex-col gap-4">
                <p className="text-sm text-muted-foreground">
                    We sent a link to{' '}
                    <span className="font-medium text-foreground">{email}</span>
                    . Open it to continue.
                </p>

                {/* Shown because this is where a typo surfaces - and the
                    profile form stays reachable so it is fixable without
                    abandoning the account. */}
                <p className="text-sm text-muted-foreground">
                    Wrong address?{' '}
                    <Link
                        href="/settings/profile"
                        className="underline underline-offset-4"
                    >
                        Change it
                    </Link>
                    .
                </p>
                {status === 'verification-link-sent' && (
                    <p className="text-sm text-muted-foreground">
                        A new link is on its way. Check your inbox.
                    </p>
                )}

                <form onSubmit={resend}>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full"
                    >
                        {processing && (
                            <LoaderCircle className="mr-2 size-4 animate-spin" />
                        )}
                        Resend the link
                    </Button>
                </form>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => router.post('/auth/logout')}
                >
                    Log out
                </Button>
            </div>
        </AuthLayout>
    );
}
