import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';

import IndexPage from '@/components/index-page';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import { createDateColumn } from '@/lib/column-helpers';
import {
    create,
    destroy as destroyRoute,
    destroyBulk,
    fetch as fetchRoute,
    show,
} from '@/routes/admin/page';
import type { Page } from '@/types/page';

const helper = createColumnHelper<Page>();

/**
 * Three states, not two. A page with a future `published_at` is neither a
 * draft nor live, and showing it as either would misrepresent legal copy that
 * is deliberately queued to take effect on a date.
 */
function statusOf(publishedAt: string | null) {
    if (publishedAt === null) {
        return { label: 'Draft', variant: 'secondary' as const };
    }

    return new Date(publishedAt) > new Date()
        ? { label: 'Scheduled', variant: 'outline' as const }
        : { label: 'Published', variant: 'default' as const };
}

const columns: ColumnDef<Page, any>[] = [
    helper.accessor('title', {
        id: 'title',
        header: 'Title',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('slug', {
        id: 'slug',
        header: 'Slug',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <code className="text-xs text-muted-foreground">
                /{ctx.getValue()}
            </code>
        ),
    }),
    helper.accessor('published_at', {
        id: 'published_at',
        header: 'Status',
        enableColumnFilter: false,
        cell: (ctx) => {
            const status = statusOf(ctx.getValue());

            return <Badge variant={status.variant}>{status.label}</Badge>;
        },
    }),
    createDateColumn<Page>('created_at'),
];

const routes = {
    fetch: fetchRoute,
    destroy: destroyRoute,
    destroyBulk,
    show,
    create,
};

export default function PageIndex() {
    return (
        <IndexPage<Page>
            title="Pages"
            description="Content addressed by slug — terms, privacy, and anything else that changes without a deploy"
            addLabel="Add page"
            columns={columns}
            routes={routes}
        />
    );
}

PageIndex.layout = (page: React.ReactNode) => <AdminLayout>{page}</AdminLayout>;
