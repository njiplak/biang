import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

type Props = {
    token: string;
    valid: boolean;
    workspace: string | null;
    role: string | null;
    authenticated: boolean;
};

/**
 * Section 5 Path C. The invitee joins an EXISTING workspace - they do not
 * create one, do not start a trial, and do not enter a card.
 */
export default function InvitationShow({
    token,
    valid,
    workspace,
    role,
    authenticated,
}: Props) {
    if (!valid) {
        return (
            <AuthLayout
                title="Invitation not valid"
                description="This link cannot be used"
            >
                <Head title="Invitation" />
                <div className="mx-auto flex w-full max-w-sm flex-col gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        This invitation has expired, been revoked, or was
                        already used. Ask whoever invited you to send a new one.
                    </p>
                    <Button variant="outline" asChild>
                        <Link href="/auth/login">Go to sign in</Link>
                    </Button>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title={`Join ${workspace}`}
            description={`You have been invited as a ${role}`}
        >
            <Head title={`Join ${workspace}`} />

            <div className="mx-auto flex w-full max-w-sm flex-col gap-4">
                {authenticated ? (
                    <Button onClick={() => router.post(`/invite/${token}`)}>
                        Accept invitation
                    </Button>
                ) : (
                    <>
                        <p className="text-center text-sm text-muted-foreground">
                            Sign in or create an account to accept.
                        </p>
                        <Button asChild>
                            <Link href="/auth/login">Sign in</Link>
                        </Button>
                        {/* The token travels with them: signing up with the
                            address it was sent to is what proves that address,
                            and a bare /auth/register throws it away. */}
                        <Button variant="outline" asChild>
                            <Link href={`/auth/register?invitation=${token}`}>
                                Create an account
                            </Link>
                        </Button>
                    </>
                )}
            </div>
        </AuthLayout>
    );
}
