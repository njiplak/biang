import AppLayout from '@/layouts/app-layout';

export default function Backoffice() {
    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">Backoffice</h1>
            <p className="text-muted-foreground">Welcome to the backoffice.</p>
        </div>
    );
}

Backoffice.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
