import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

type Props = { status?: string };

export default function VerifyEmail({ status }: Props) {
    const { post, processing } = useForm({});

    const resend = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <AuthLayout
            title="Confirm your email"
            description="We sent a link to the address you signed up with"
        >
            <Head title="Verify email" />

            <div className="flex flex-col gap-4">
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
