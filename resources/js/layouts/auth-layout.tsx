import { Toaster } from '@/components/ui/sonner';

export default function AuthLayout({
    children,
}: {
    children: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <div className="grid grid-cols-12 gap-4">
            <div className="col-span-6 flex h-screen flex-col items-center justify-center bg-neutral-900 text-white">
                <div className="max-w-md text-center">
                    <h1 className="text-3xl font-bold tracking-tight">Durham React</h1>
                    <p className="mt-2 text-lg text-neutral-400">Culture & Creator Engine</p>
                    <blockquote className="mt-8 text-sm leading-relaxed text-neutral-300">
                        "Stay on top of South African social culture. Monitor trends, generate AI-powered ideas, and detect organic brand mentions — all in one place."
                    </blockquote>
                </div>
            </div>
            <div className="col-span-6 h-screen p-4">
                <Toaster position="bottom-right" richColors />
                {children}
            </div>
        </div>
    );
}
