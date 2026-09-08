import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

/**
 * Reached only when a password has been accepted and no session exists yet.
 * There is no account identifier on this page on purpose - the pending login
 * lives in the session, so this URL tells a stranger nothing.
 */
export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/auth/two-factor-challenge', { onFinish: () => reset('code') });
    };

    return (
        <AuthLayout
            title="Two-step verification"
            description={
                useRecovery
                    ? 'Enter one of your recovery codes'
                    : 'Enter the code from your authenticator app'
            }
        >
            <Head title="Two-step verification" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="code">
                        {useRecovery ? 'Recovery code' : 'Authentication code'}
                    </Label>
                    <Input
                        id="code"
                        name="code"
                        required
                        autoFocus
                        // A recovery code is a word pair, so the numeric
                        // keypad and the 6-digit hints only apply to TOTP.
                        inputMode={useRecovery ? 'text' : 'numeric'}
                        autoComplete={
                            useRecovery ? 'one-time-code' : 'one-time-code'
                        }
                        maxLength={useRecovery ? 32 : 6}
                        placeholder={
                            useRecovery ? 'xxxxxxxxxx-xxxxxxxxxx' : '000000'
                        }
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        disabled={processing}
                    />
                    <InputError message={errors.code} />
                </div>

                <Button type="submit" disabled={processing}>
                    {processing && (
                        <LoaderCircle className="mr-2 size-4 animate-spin" />
                    )}
                    Continue
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setUseRecovery(!useRecovery);
                        reset('code');
                    }}
                >
                    {useRecovery
                        ? 'Use an authenticator code instead'
                        : 'Lost your device? Use a recovery code'}
                </Button>

                {/* Abandoning clears the pending note rather than leaving it
                    armed on a shared machine. */}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => router.delete('/auth/two-factor-challenge')}
                >
                    Cancel and sign in again
                </Button>
            </form>
        </AuthLayout>
    );
}
