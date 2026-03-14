import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';

import IndexPage from '@/components/index-page';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import {
    create,
    destroy as destroyRoute,
    destroyBulk,
    fetch as fetchRoute,
    show,
} from '@/routes/backoffice/setting/setting';
import type { Setting } from '@/types/setting';

const helper = createColumnHelper<Setting>();

const columns: ColumnDef<Setting, any>[] = [
    helper.accessor('id', {
        id: 'id',
        header: 'ID',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('key', {
        id: 'key',
        header: 'Key',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('value', {
        id: 'value',
        header: 'Value',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    createDateColumn<Setting>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function SettingIndex() {
    return (
        <IndexPage<Setting>
            title="Setting Management"
            description="Manage your application settings"
            addLabel="Add Setting"
            columns={columns}
            routes={routes}
        />
    );
}

SettingIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
