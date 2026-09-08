import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';

type Props = {
    mustVerifyEmail: boolean;
    pendingEmail: string | null;
    status?: string;
};

export default function Profile({
    mustVerifyEmail,
    pendingEmail,
    status,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const form = useForm({
        name: auth.user?.name ?? '',
        email: auth.user?.email ?? '',
    });
    const deleteForm = useForm({ password: '' });
    const [confirming, setConfirming] = useState(false);
    const pageErrors = usePage<SharedData>().props.errors as
        | Record<string, string>
        | undefined;

    return (
        <AppLayout>
            <Head title="Profile" />

            <div className="flex max-w-2xl flex-col gap-8">
                <div className="flex flex-wrap gap-4 text-sm">
                    <span className="font-medium">Profile</span>
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
                    <Link
                        href="/settings/sessions"
                        className="text-muted-foreground underline underline-offset-4"
                    >
                        Sessions
                    </Link>
                </div>

                <section className="flex flex-col gap-4">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Your profile
                    </h1>
                    {status && (
                        <p className="text-sm text-muted-foreground">
                            {status}
                        </p>
                    )}

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.patch('/settings/profile');
                        }}
                        className="flex flex-col gap-4"
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                className="max-w-sm"
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                className="max-w-sm"
                            />
                            <InputError message={form.errors.email} />
                            {/* The account keeps this address until the new one
                                is confirmed, so a typo here costs nothing. */}
                            <p className="text-xs text-muted-foreground">
                                We will email the new address to confirm it.
                                Until then your account keeps using this one.
                            </p>
                        </div>

                        {pendingEmail && (
                            <div className="flex flex-col gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                                <p>
                                    Waiting for confirmation at{' '}
                                    <span className="font-medium">
                                        {pendingEmail}
                                    </span>
                                    . Your account still uses{' '}
                                    <span className="font-medium">
                                        {auth.user?.email}
                                    </span>
                                    .
                                </p>
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                '/settings/email/pending',
                                            )
                                        }
                                    >
                                        Cancel the change
                                    </Button>
                                </div>
                            </div>
                        )}

                        {mustVerifyEmail && (
                            <p className="text-sm text-muted-foreground">
                                Your email is not confirmed.{' '}
                                <Link
                                    href="/verify-email"
                                    className="underline underline-offset-4"
                                >
                                    Resend the link
                                </Link>
                            </p>
                        )}

                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                </section>

                {/* The theme was already being applied on boot from a cookie
                    that nothing could set: initializeTheme() and the
                    HandleAppearance middleware were both wired up, and the one
                    control that changes the value was in a component no page
                    imported. This is that control. */}
                <section className="flex flex-col gap-3 border-t border-border pt-6">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Appearance
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Applies to this browser only.
                    </p>
                    <AppearanceToggleTab />
                </section>

                {/* Section 3: the sole owner of a workspace has to hand over
                    first - the server refuses and says so. */}
                <section className="flex flex-col gap-3 border-t border-destructive/30 pt-6">
                    <h2 className="text-sm font-medium text-destructive">
                        Delete your account
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        This cannot be undone. If you are the only owner of a
                        workspace, transfer ownership first.
                    </p>
                    {pageErrors?.errors && (
                        <p className="text-sm text-destructive">
                            {pageErrors.errors}
                        </p>
                    )}

                    {confirming ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                deleteForm.delete('/settings/profile');
                            }}
                            className="flex flex-col gap-2"
                        >
                            <Label htmlFor="delete_password">
                                Confirm your password
                            </Label>
                            <div className="flex items-start gap-2">
                                <div className="flex flex-col gap-1">
                                    <PasswordInput
                                        id="delete_password"
                                        value={deleteForm.data.password}
                                        onChange={(e) =>
                                            deleteForm.setData(
                                                'password',
                                                e.target.value,
                                            )
                                        }
                                        className="w-64"
                                    />
                                    <InputError
                                        message={deleteForm.errors.password}
                                    />
                                </div>
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={deleteForm.processing}
                                >
                                    Delete account
                                </Button>
                                <Button
                                    variant="ghost"
                                    type="button"
                                    onClick={() => setConfirming(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div>
                            <Button
                                variant="destructive"
                                onClick={() => setConfirming(true)}
                            >
                                Delete account
                            </Button>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
