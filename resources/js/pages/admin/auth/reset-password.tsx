import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * The console asks for two factors, so a new password lands back on the login
 * rather than signing anybody in - the second factor is still owed.
 */
export default function AdminResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/admin/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <div className="flex min-h-svh items-center justify-center bg-background p-6">
            <Head title="Choose a new password" />

            <form
                onSubmit={submit}
                className="flex w-full max-w-sm flex-col gap-5"
            >
                <div className="flex flex-col gap-1">
                    <h1 className="text-lg font-semibold tracking-tight">
                        Choose a new password
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        You will sign in again afterwards.
                    </p>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        required
                    />
                    {form.errors.email && (
                        <p className="text-xs text-destructive">
                            {form.errors.email}
                        </p>
                    )}
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password">New password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        required
                        autoFocus
                    />
                    {form.errors.password && (
                        <p className="text-xs text-destructive">
                            {form.errors.password}
                        </p>
                    )}
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password_confirmation">
                        Confirm password
                    </Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                        required
                    />
                </div>

                <Button type="submit" disabled={form.processing}>
                    Save and sign in
                </Button>
            </form>
        </div>
    );
}
