import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/admin/page';
import type { Page } from '@/types/page';

type Props = {
    page?: Page;
};

export default function PageForm({ page }: Props) {
    const { data, setData, post, put, errors, processing } = useForm({
        slug: page?.slug ?? '',
        title: page?.title ?? '',
        body: page?.body ?? '',
        // The date itself is kept server-side: re-publishing an already
        // published page must not move the date it took effect.
        published:
            page?.published_at !== null && page?.published_at !== undefined,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (page) {
            put(update(page.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {page ? 'Edit page' : 'New page'}
            </h1>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Title</Label>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                    />
                    <InputError message={errors?.title} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Slug</Label>
                    <Input
                        value={data.slug}
                        onChange={(e) => setData('slug', e.target.value)}
                        placeholder="privacy-policy"
                    />
                    {/* The slug is the address, so changing it on a live page
                        breaks every link already pointing at it. */}
                    <p className="text-xs text-muted-foreground">
                        Lowercase letters, numbers and hyphens. This is the
                        address the page is looked up by, so avoid changing it
                        once anything links to it.
                    </p>
                    <InputError message={errors?.slug} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Body</Label>
                    <Textarea
                        rows={18}
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        className="font-mono text-sm"
                    />
                    {/* Said out loud because typing HTML here looks like it
                        works and then silently does not - Page::toHtml()
                        strips it, and that strip is what makes the public page
                        safe to render. */}
                    <p className="text-xs text-muted-foreground">
                        Markdown: <code># Heading</code>, <code>**bold**</code>,{' '}
                        <code>- list item</code>, <code>[link](https://…)</code>
                        . Raw HTML is removed.
                    </p>
                    <InputError message={errors?.body} />
                </div>

                <div className="flex items-start gap-2">
                    <Checkbox
                        id="published"
                        checked={data.published}
                        onCheckedChange={(checked) =>
                            setData('published', checked === true)
                        }
                    />
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="published">Published</Label>
                        <p className="text-xs text-muted-foreground">
                            Drafts stay invisible. A half-finished terms page is
                            worse than none, so publishing is deliberate.
                        </p>
                    </div>
                </div>
                <InputError message={errors?.published} />

                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.visit(index().url)}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && (
                            <LoaderCircle className="size-4 animate-spin" />
                        )}
                        Save
                    </Button>
                </div>
            </form>
        </div>
    );
}

PageForm.layout = (page: React.ReactNode) => <AdminLayout>{page}</AdminLayout>;
