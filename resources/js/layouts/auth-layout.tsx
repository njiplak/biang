import { Toaster } from '@/components/ui/sonner';

/**
 * Shell for every signed-out screen.
 *
 * The brand panel is hidden below `lg` so a phone gets the form full-width
 * rather than squeezed into half the viewport, and the form column centres its
 * children so each page does not have to arrange that itself.
 */
export default function AuthLayout({
    children,
    title,
    description,
}: {
    children: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="hidden flex-col items-center justify-center bg-neutral-900 p-10 text-white lg:flex">
                <div className="max-w-md text-center">
                    <h1 className="text-3xl font-bold tracking-tight">
                        Kawakib
                    </h1>
                    <p className="mt-2 text-lg text-neutral-400">
                        A Laravel starter kit for Kawakib MVP
                    </p>
                    <blockquote className="mt-8 text-sm leading-relaxed text-neutral-300">
                        "Kawakib - Basis, a Laravel starter kit for Kawakib
                        MVP."
                    </blockquote>
                </div>
            </div>

            <div className="flex min-h-svh flex-col items-center justify-center p-6">
                <Toaster position="bottom-right" richColors />

                <div className="flex w-full max-w-sm flex-col gap-6">
                    <div className="flex flex-col gap-1 text-center">
                        <h2 className="text-xl font-semibold tracking-tight">
                            {title}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
