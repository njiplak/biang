import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';

export default function PasswordSettings({ status }: { status?: string }) {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <AppLayout>
            <Head title="Password" />

            <div className="flex max-w-2xl flex-col gap-8">
                <div className="flex flex-wrap gap-4 text-sm">
                    <Link
                        href="/settings/profile"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Profile
                    </Link>
                    <span className="font-medium">Password</span>
                    <Link
                        href="/settings/two-factor"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Two-factor
                    </Link>
                    <Link
                        href="/settings/sessions"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Sessions
                    </Link>
                </div>

                <section className="flex flex-col gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Change your password
                    </h1>
                    {status && (
                        <p className="text-sm text-muted-foreground">
                            {status}
                        </p>
                    )}

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/password', {
                                onFinish: () =>
                                    form.reset(
                                        'current_password',
                                        'password',
                                        'password_confirmation',
                                    ),
                            });
                        }}
                        className="flex max-w-sm flex-col gap-4"
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="current_password">
                                Current password
                            </Label>
                            <PasswordInput
                                id="current_password"
                                autoComplete="current-password"
                                value={form.data.current_password}
                                onChange={(e) =>
                                    form.setData(
                                        'current_password',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.current_password}
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password">New password</Label>
                            <PasswordInput
                                id="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) =>
                                    form.setData('password', e.target.value)
                                }
                            />
                            <InputError message={form.errors.password} />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(e) =>
                                    form.setData(
                                        'password_confirmation',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.password_confirmation}
                            />
                        </div>

                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Update password
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
