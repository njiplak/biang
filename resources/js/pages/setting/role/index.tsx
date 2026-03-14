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
} from '@/routes/backoffice/setting/role';
import type { Role } from '@/types/role';

const helper = createColumnHelper<Role>();

const columns: ColumnDef<Role, any>[] = [
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
    helper.display({
        id: 'permissions_count',
        header: 'Permissions',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => {
            const count = ctx.row.original.permissions?.length ?? 0;
            return (
                <span className="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">
                    {count}
                </span>
            );
        },
    }),
    createDateColumn<Role>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function RoleIndex() {
    return (
        <IndexPage<Role>
            title="Role Management"
            description="Manage user roles and their permissions"
            addLabel="Add Role"
            columns={columns}
            routes={routes}
        />
    );
}

RoleIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
