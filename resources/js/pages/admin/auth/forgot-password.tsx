import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Staff recovery. Deliberately its own screen rather than the customer one:
 * the token is minted by a different broker, and the two must never meet.
 */
export default function AdminForgotPassword({ status }: { status?: string }) {
    const form = useForm({ email: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/admin/forgot-password');
    };

    return (
        <div className="flex min-h-svh items-center justify-center bg-background p-6">
            <Head title="Reset staff password" />

            <form
                onSubmit={submit}
                className="flex w-full max-w-sm flex-col gap-5"
            >
                <div className="flex flex-col gap-1">
                    <h1 className="text-lg font-semibold tracking-tight">
                        Reset your password
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        We will email a link to your staff address.
                    </p>
                </div>

                {status && (
                    <p className="text-sm text-muted-foreground">{status}</p>
                )}

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        required
                        autoFocus
                    />
                    {form.errors.email && (
                        <p className="text-xs text-destructive">
                            {form.errors.email}
                        </p>
                    )}
                </div>

                <Button type="submit" disabled={form.processing}>
                    Email a reset link
                </Button>

                <Link
                    href="/admin/login"
                    className="text-center text-sm underline underline-offset-4"
                >
                    Back to sign in
                </Link>
            </form>
        </div>
    );
}
