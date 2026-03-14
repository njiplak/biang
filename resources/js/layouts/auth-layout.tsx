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
                    <h1 className="text-3xl font-bold tracking-tight">Kawakib</h1>
                    <p className="mt-2 text-lg text-neutral-400">A Laravel starter kit for Kawakib MVP</p>
                    <blockquote className="mt-8 text-sm leading-relaxed text-neutral-300">
                        "Kawakib - Basis, a Laravel starter kit for Kawakib MVP."
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
