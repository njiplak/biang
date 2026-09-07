import { Head, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

/** Re-proving identity before something irreversible. */
export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    return (
        <AuthLayout
            title="Confirm your password"
            description="This is a secure area — please confirm it is you"
        >
            <Head title="Confirm password" />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/confirm-password', {
                        onFinish: () => reset('password'),
                    });
                }}
                className="flex flex-col gap-4"
            >
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password">Password</Label>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                        autoFocus
                    />
                    <InputError message={errors.password} />
                </div>

                <Button type="submit" disabled={processing}>
                    Confirm
                </Button>
            </form>
        </AuthLayout>
    );
}
