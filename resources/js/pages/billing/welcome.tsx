import { Head, Link } from '@inertiajs/react';
import { FolderKanban, LayoutDashboard, PartyPopper, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

type Props = {
    workspace: { ulid: string; name: string };
    subscription: {
        plan: string;
        status: string;
        trial_ends_at: string | null;
        current_period_end: string | null;
        amount_minor: number;
        currency: string;
        interval: string;
    };
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

const date = (value: string) => new Date(value).toLocaleDateString();

/**
 * Where the customer lands once the card form has settled - the point they are
 * most motivated. It says what they have and when they will be charged, then
 * points at the first useful things to do.
 */
export default function BillingWelcome({ workspace, subscription }: Props) {
    const price = `${money(subscription.amount_minor, subscription.currency)}/${subscription.interval}`;
    const trialing =
        subscription.status === 'trialing' && subscription.trial_ends_at;

    return (
        <AppLayout>
            <Head title="Welcome" />

            <div className="flex max-w-xl flex-col gap-6 p-6">
                <div className="flex flex-col gap-2">
                    <PartyPopper className="size-6" />
                    <h1 className="text-xl font-semibold tracking-tight">
                        {trialing
                            ? `Your ${subscription.plan} trial has started`
                            : `${workspace.name} is on ${subscription.plan}`}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {trialing
                            ? `Everything in ${subscription.plan} is open to your team until ${date(subscription.trial_ends_at!)}. Your card is then charged ${price}, plus tax, unless you cancel before - we will email you 3 days and 1 day ahead.`
                            : subscription.current_period_end
                              ? `Your subscription renews on ${date(subscription.current_period_end)} at ${price}, plus tax.`
                              : `You are subscribed at ${price}, plus tax.`}
                    </p>
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Get started
                    </h2>
                    <Button asChild variant="outline" className="justify-start">
                        <Link href="/projects">
                            <FolderKanban className="size-4" />
                            Create your first project
                        </Link>
                    </Button>
                    <Button asChild variant="outline" className="justify-start">
                        <Link href={`/workspaces/${workspace.ulid}/members`}>
                            <Users className="size-4" />
                            Invite your team
                        </Link>
                    </Button>
                    <Button asChild className="justify-start">
                        <Link href="/dashboard">
                            <LayoutDashboard className="size-4" />
                            Go to your dashboard
                        </Link>
                    </Button>
                </section>
            </div>
        </AppLayout>
    );
}
