import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { attempt, register } from '@/routes';
import password from '@/routes/password';

type FormData = {
    email: string;
    password: string;
    remember: boolean;
};

export default function Login() {
    const { data, setData, post, processing, errors, reset } =
        useForm<FormData>({
            email: '',
            password: '',
            remember: false,
        });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        // No toast here: a failed sign-in has to stay on screen next to the
        // field it belongs to, and the throttle message is reported on `email`.
        post(attempt().url, { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout
            title="Log in to Kawakib"
            description="Enter your credentials to access the platform"
        >
            <Head title="Log in" />

            <form className="flex w-full flex-col gap-4" onSubmit={onSubmit}>
                <div className="flex flex-col">
                    <Label htmlFor="email" className="mb-1.5">
                        Email address
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autoFocus
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
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">Password</Label>
                        <Link
                            href={password.request().url}
                            className="text-sm underline underline-offset-4"
                        >
                            Forgot password?
                        </Link>
                    </div>
                    <PasswordInput
                        id="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        disabled={processing}
                        placeholder="Password"
                    />
                    <InputError message={errors.password} />
                </div>
                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && (
                        <LoaderCircle className="size-4 animate-spin" />
                    )}
                    Login
                </Button>
                <Label htmlFor="remember" className="flex items-center gap-2">
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

                <p className="text-center text-sm text-muted-foreground">
                    Don't have an account?{' '}
                    <Link
                        href={register().url}
                        className="underline underline-offset-4"
                    >
                        Sign up
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
