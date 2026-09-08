import { Head, router, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';

type Props = {
    enabled: boolean;
    pending: boolean;
    qrSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[];
};

/**
 * Staff enrolment. Same three states as the customer screen, own guard.
 *
 * Not gated on any `permission:` - protecting your own account is not a
 * privileged action, and gating it would leave the staff without that
 * permission as the only ones unable to.
 */
export default function AdminTwoFactor({
    enabled,
    pending,
    qrSvg,
    setupKey,
    recoveryCodes,
}: Props) {
    const confirmForm = useForm({ code: '' });

    const confirm = (e: React.FormEvent) => {
        e.preventDefault();
        confirmForm.post('/admin/two-factor/confirm', {
            onSuccess: () => confirmForm.reset('code'),
        });
    };

    return (
        <AdminLayout>
            <Head title="Two-factor authentication" />

            <div className="flex max-w-2xl flex-col gap-6 p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Two-factor authentication
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Ask for a code from your phone as well as your password.
                        Staff accounts reach every customer's data, so this
                        matters more here.
                    </p>
                </div>

                {!enabled && !pending && (
                    <div className="flex flex-col items-start gap-3 rounded-md border border-border p-4">
                        <p className="text-sm text-muted-foreground">
                            Two-factor authentication is off. Turning it on adds
                            a second step to every sign-in on this account.
                        </p>
                        <Button
                            onClick={() => router.post('/admin/two-factor')}
                        >
                            Turn on
                        </Button>
                    </div>
                )}

                {pending && (
                    <div className="flex flex-col gap-4 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                        <p className="text-sm">
                            Scan this with your authenticator app, then enter
                            the code it shows. Two-factor is not on until you
                            do.
                        </p>

                        {qrSvg && (
                            <div
                                className="w-fit rounded-md bg-white p-3"
                                // Generated server-side by bacon/bacon-qr-code
                                // from our own secret - no user input reaches it.
                                dangerouslySetInnerHTML={{ __html: qrSvg }}
                            />
                        )}

                        {setupKey && (
                            <p className="text-sm">
                                Cannot scan? Enter this key by hand:{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                    {setupKey}
                                </code>
                            </p>
                        )}

                        <form
                            onSubmit={confirm}
                            className="flex flex-col gap-1.5"
                        >
                            <Label htmlFor="code">Code from your app</Label>
                            <div className="flex items-start gap-2">
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    maxLength={6}
                                    placeholder="000000"
                                    className="w-40"
                                    value={confirmForm.data.code}
                                    onChange={(e) =>
                                        confirmForm.setData(
                                            'code',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Button
                                    type="submit"
                                    disabled={confirmForm.processing}
                                >
                                    Confirm
                                </Button>
                            </div>
                            <InputError message={confirmForm.errors.code} />
                        </form>

                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.delete('/admin/two-factor')
                                }
                            >
                                Cancel setup
                            </Button>
                        </div>
                    </div>
                )}

                {enabled && (
                    <div className="flex flex-col gap-4 rounded-md border border-border p-4">
                        <p className="text-sm">
                            Two-factor authentication is{' '}
                            <span className="font-medium">on</span>.
                        </p>

                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium">
                                Recovery codes
                            </p>
                            {/* The only way back in if the phone is lost, and
                                each one works exactly once. */}
                            <p className="text-sm text-muted-foreground">
                                Store these somewhere safe. Each one can be used
                                once, in place of a code from your app.
                            </p>
                            <ul className="grid grid-cols-2 gap-1 rounded-md bg-muted p-3 font-mono text-xs">
                                {recoveryCodes.map((code) => (
                                    <li key={code}>{code}</li>
                                ))}
                            </ul>
                            {recoveryCodes.length <= 2 && (
                                <p className="text-sm text-amber-600 dark:text-amber-500">
                                    Only {recoveryCodes.length} left. Generate a
                                    new set before you run out.
                                </p>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        '/admin/two-factor/recovery-codes',
                                    )
                                }
                            >
                                Generate new recovery codes
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.delete('/admin/two-factor')
                                }
                            >
                                Turn off
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
