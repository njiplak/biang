import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

type Props = {
    page: {
        title: string;
        // Already HTML, and already sanitised - see Page::toHtml(), which
        // strips raw HTML and unsafe link schemes out of the markdown.
        html: string;
        published_at: string | null;
    };
};

export default function PageShow({ page }: Props) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title={page.title} />

            <header className="flex items-center justify-between border-b border-border px-6 py-4 lg:px-10">
                <Link
                    href="/"
                    className="flex items-center gap-2.5 text-foreground"
                >
                    <span className="flex size-8 items-center justify-center rounded-md bg-foreground">
                        <span className="text-sm font-bold tracking-tight text-background">
                            K
                        </span>
                    </span>
                    <span className="text-lg font-semibold tracking-tight">
                        Kawakib
                    </span>
                </Link>
                <Link
                    href="/"
                    className="flex items-center gap-1.5 text-sm text-muted-foreground underline underline-offset-4"
                >
                    <ArrowLeft className="size-4" />
                    Home
                </Link>
            </header>

            <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-10 lg:px-0">
                <h1 className="text-3xl font-semibold tracking-tight">
                    {page.title}
                </h1>

                {page.published_at && (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Last updated{' '}
                        {new Date(page.published_at).toLocaleDateString()}
                    </p>
                )}

                {/*
                 * Safe because the server never lets raw HTML through: the body
                 * is markdown, converted with html_input=strip and
                 * allow_unsafe_links=false in Page::toHtml(). If that ever
                 * changes this line has to change with it.
                 */}
                <article
                    className="mt-8 flex flex-col gap-4 text-sm leading-relaxed [&_a]:underline [&_a]:underline-offset-4 [&_h1]:mt-8 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_ol_li]:list-decimal [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3"
                    dangerouslySetInnerHTML={{ __html: page.html }}
                />
            </main>
        </div>
    );
}
