import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    return (
        <AuthLayout
            title="Reset your password"
            description="We will email you a link"
        >
            <Head title="Forgot password" />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/forgot-password');
                }}
                className="flex flex-col gap-4"
            >
                {status && (
                    <p className="text-sm text-muted-foreground">{status}</p>
                )}

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoFocus
                    />
                    <InputError message={errors.email} />
                </div>

                <Button type="submit" disabled={processing}>
                    Email a reset link
                </Button>

                <Link
                    href="/auth/login"
                    className="text-center text-sm underline underline-offset-4"
                >
                    Back to sign in
                </Link>
            </form>
        </AuthLayout>
    );
}
