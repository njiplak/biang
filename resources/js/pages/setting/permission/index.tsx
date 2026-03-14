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
} from '@/routes/backoffice/setting/permission';
import type { Permission } from '@/types/permission';

const helper = createColumnHelper<Permission>();

const columns: ColumnDef<Permission, any>[] = [
    helper.accessor('id', {
        id: 'id',
        header: 'ID',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('name', {
        id: 'name',
        header: 'Name',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('guard_name', {
        id: 'guard_name',
        header: 'Guard',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    createDateColumn<Permission>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function PermissionIndex() {
    return (
        <IndexPage<Permission>
            title="Permission Management"
            description="Manage application permissions"
            addLabel="Add Permission"
            columns={columns}
            routes={routes}
        />
    );
}

PermissionIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
