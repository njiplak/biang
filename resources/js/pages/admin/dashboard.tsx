import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    admin: { name: string; email: string };
};

/**
 * Shell for section 10's jobs: understand the business, answer a ticket in
 * under a minute, reproduce a complaint, close a deal, stop abuse, talk to
 * everyone. Each becomes a section here as it is built.
 */
export default function AdminDashboard({ admin }: Props) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title="Staff console" />

            <header className="flex items-center justify-between border-b border-border px-6 py-4">
                <div>
                    <h1 className="text-base font-semibold tracking-tight">
                        Staff console
                    </h1>
                    <p className="text-xs text-muted-foreground">
                        {admin.name} · {admin.email}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => router.post('/admin/logout')}
                >
                    Sign out
                </Button>
            </header>

            <main className="flex flex-1 flex-col gap-3 p-6">
                <p className="text-sm text-muted-foreground">
                    Customer directory, impersonation, grants and the revenue
                    dashboard land here.
                </p>
            </main>
        </div>
    );
}
