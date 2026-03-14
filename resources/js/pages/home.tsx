import { Link } from '@inertiajs/react';
import { ArrowRight, Shield, BarChart3, Settings } from 'lucide-react';
import { login } from '@/actions/App/Http/Controllers/Auth/UserAuthController';
import { Button } from '@/components/ui/button';

export default function Home() {
    return (
        <div className="relative flex min-h-svh flex-col bg-background overflow-hidden">
                {/* Top accent line */}
                <div className="h-px w-full bg-border" />

                {/* Header */}
                <header className="relative z-10 flex items-center justify-between px-8 py-6 lg:px-16">
                    <div className="flex items-center gap-2.5">
                        <div className="flex size-8 items-center justify-center rounded-md bg-foreground">
                            <span className="text-sm font-bold tracking-tight text-background">K</span>
                        </div>
                        <span className="text-lg font-semibold tracking-tight text-foreground">Kawakib</span>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <Link href={login.url()}>Sign in</Link>
                    </Button>
                </header>

                {/* Main */}
                <main className="relative z-10 flex flex-1 flex-col items-center justify-center px-8 lg:px-16">
                    <div className="flex max-w-2xl flex-col items-center text-center">
                        <p className="mb-4 text-xs font-medium uppercase tracking-[0.2em] text-muted-foreground">
                            Management Console
                        </p>

                        <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
                            Kawakib
                        </h1>

                        <div className="mx-auto mt-4 h-px w-16 bg-foreground/20" />

                        <p className="mt-6 max-w-md text-base leading-relaxed text-muted-foreground">
                            Centralized operations platform. Manage resources, monitor performance, and configure your systems from a single console.
                        </p>

                        <Button asChild size="lg" className="mt-10 gap-2.5 px-8">
                            <Link href={login.url()}>
                                Access Console
                                <ArrowRight className="size-4" />
                            </Link>
                        </Button>
                    </div>

                    {/* Feature pills */}
                    <div className="mt-16 flex flex-wrap items-center justify-center gap-6 text-muted-foreground">
                        <div className="flex items-center gap-2 text-sm">
                            <Shield className="size-4" />
                            <span>Secure Access</span>
                        </div>
                        <div className="hidden h-3 w-px bg-border sm:block" />
                        <div className="flex items-center gap-2 text-sm">
                            <BarChart3 className="size-4" />
                            <span>Real-time Monitoring</span>
                        </div>
                        <div className="hidden h-3 w-px bg-border sm:block" />
                        <div className="flex items-center gap-2 text-sm">
                            <Settings className="size-4" />
                            <span>System Configuration</span>
                        </div>
                    </div>
                </main>

                {/* Footer */}
                <footer className="relative z-10 px-8 py-6 lg:px-16">
                    <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                            &copy; {new Date().getFullYear()} Kawakib. All rights reserved.
                        </p>
                    </div>
                </footer>

                {/* Bottom accent line */}
                <div className="h-px w-full bg-border" />
        </div>
    );
}
