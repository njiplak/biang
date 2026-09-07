import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Staff login. Deliberately separate from the customer login: a customer
 * account can never authenticate here, and this form never links across to it.
 */
export default function AdminLogin() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/admin/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-svh items-center justify-center bg-background p-6">
            <Head title="Staff sign in" />

            <form
                onSubmit={submit}
                className="flex w-full max-w-sm flex-col gap-5"
            >
                <div className="flex flex-col gap-1">
                    <h1 className="text-lg font-semibold tracking-tight">
                        Staff console
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Internal access only.
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
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        required
                    />
                    {form.errors.password && (
                        <p className="text-xs text-destructive">
                            {form.errors.password}
                        </p>
                    )}
                </div>

                <Button type="submit" disabled={form.processing}>
                    Sign in
                </Button>
            </form>
        </div>
    );
}
