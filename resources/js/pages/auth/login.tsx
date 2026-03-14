import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { FormResponse } from '@/lib/constant';
import { attempt } from '@/routes';

type FormData = {
    email: string;
    password: string;
    remember: boolean;
};

export default function Login() {
    const { data, setData, post, processing, errors } = useForm<FormData>({
        email: '',
        password: '',
        remember: false,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post(attempt().url, FormResponse);
    };

    return (
        <AuthLayout
            title="Log in to Kawakib"
            description="Enter your credentials to access the platform"
        >
            <Head title="Log in" />

            <div className="mx-auto flex h-full max-w-sm flex-col items-center justify-center gap-1">
                <h1 className="mt-1 text-xl font-bold">Kawakib</h1>
                <p className="text-center text-sm text-muted-foreground">
                    Enter your email and password below <br />
                    to access the platform
                </p>
                <form
                    className="mt-2 flex w-full flex-col gap-4"
                    onSubmit={onSubmit}
                >
                    <div className="flex flex-col">
                        <Label htmlFor="email" className="mb-1.5">
                            Email address
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder="email@example.com"
                            className="w-full"
                        />
                        <InputError message={errors.email} />
                    </div>
                    <div className="flex flex-col">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            required
                            tabIndex={3}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            disabled={processing}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        tabIndex={5}
                        disabled={processing}
                    >
                        {processing && (
                            <LoaderCircle className="size-4 animate-spin" />
                        )}
                        Login
                    </Button>
                    <Label
                        htmlFor="remember"
                        className="flex items-center gap-2"
                    >
                        <Checkbox
                            id="remember"
                            checked={data.remember}
                            onCheckedChange={(checked) =>
                                setData('remember', checked === true)
                            }
                            disabled={processing}
                        />
                        <span>Remember me</span>
                    </Label>
                </form>
            </div>
        </AuthLayout>
    );
}
