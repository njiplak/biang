import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type FormData = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    workspace_name: string;
    terms: boolean;
};

type ChosenPlan = {
    code: string;
    name: string;
    interval: string;
    currency: string;
    amount_minor: number;
    trial_days: number;
};

type Props = {
    invitedEmail?: string | null;
    invitedTo?: string | null;
    /*
     * Section 11: the app links to terms and privacy from signup. Either can
     * be null while the page is still a draft, and a null means no link at all
     * rather than one that 404s.
     */
    legal?: { terms: string | null; privacy: string | null };
    // Section 11: carried from the marketing site's "Start trial" button.
    plan?: ChosenPlan | null;
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

export default function Register({
    invitedEmail,
    invitedTo,
    plan,
    legal,
}: Props) {
    const { data, setData, post, processing, errors, reset } =
        useForm<FormData>({
            name: '',
            // Signing up with the address the invitation was sent to is what
            // proves it, so it is prefilled rather than left to be retyped.
            email: invitedEmail ?? '',
            password: '',
            password_confirmation: '',
            workspace_name: '',
            terms: false,
        });
    const hasLegal = Boolean(legal?.terms || legal?.privacy);

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post('/auth/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title={invitedTo ? `Join ${invitedTo}` : 'Create your account'}
            description={
                invitedTo
                    ? 'Create your account to accept the invitation'
                    : plan
                      ? `Start your ${plan.trial_days}-day ${plan.name} trial`
                      : 'Choose a plan once you are in'
            }
        >
            <Head title="Sign up" />

            {/* The choice has to survive to the card form, and the customer
                has to be able to see that it did. */}
            {plan && (
                <div className="flex flex-wrap items-baseline justify-between gap-2 rounded-md border border-border bg-muted/40 p-3 text-sm">
                    <span className="font-medium">{plan.name}</span>
                    <span className="text-muted-foreground">
                        {money(plan.amount_minor, plan.currency)}/
                        {plan.interval} after {plan.trial_days} days
                    </span>
                </div>
            )}

            {invitedEmail && (
                <p className="rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
                    Sign up with{' '}
                    <span className="font-medium">{invitedEmail}</span> and you
                    will not need to confirm your address - we already sent this
                    invitation there.
                </p>
            )}

            <form onSubmit={onSubmit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoFocus
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password">Password</Label>
                    <PasswordInput
                        id="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password_confirmation">
                        Confirm password
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                        required
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                {/* An invitee joins someone else's workspace and gets none of
                    their own, so there is nothing to name. */}
                {!invitedTo && (
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="workspace_name">
                            Company or workspace name{' '}
                            <span className="text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="workspace_name"
                            autoComplete="organization"
                            value={data.workspace_name}
                            onChange={(e) =>
                                setData('workspace_name', e.target.value)
                            }
                        />
                        <InputError message={errors.workspace_name} />
                    </div>
                )}

                {/* Section 11, and a recorded tick rather than a sentence under
                    the button: which wording they agreed to is kept on the
                    account. Shown only for pages that are actually published -
                    see Page::legalLinks(). */}
                {hasLegal && (
                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-start gap-2 text-sm">
                            <Checkbox
                                id="terms"
                                checked={data.terms}
                                onCheckedChange={(checked) =>
                                    setData('terms', checked === true)
                                }
                            />
                            <Label htmlFor="terms" className="font-normal">
                                <span>
                                    I agree to the{' '}
                                    {legal?.terms && (
                                        <a
                                            href={legal.terms}
                                            className="underline underline-offset-4"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Terms of Service
                                        </a>
                                    )}
                                    {legal?.terms && legal?.privacy && ' and '}
                                    {legal?.privacy && (
                                        <a
                                            href={legal.privacy}
                                            className="underline underline-offset-4"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Privacy Policy
                                        </a>
                                    )}
                                </span>
                            </Label>
                        </div>
                        <InputError message={errors.terms} />
                    </div>
                )}

                <Button type="submit" disabled={processing}>
                    {processing && (
                        <LoaderCircle className="mr-2 size-4 animate-spin" />
                    )}
                    Create account
                </Button>

                <p className="text-center text-sm text-muted-foreground">
                    Already have an account?{' '}
                    <Link
                        href="/auth/login"
                        className="underline underline-offset-4"
                    >
                        Log in
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
