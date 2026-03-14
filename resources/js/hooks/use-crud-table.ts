import { useForm } from '@inertiajs/react';
import { useCallback, useState } from 'react';

import { FormResponse } from '@/lib/constant';
import type { Base } from '@/types/base';
import type { RouteQueryOptions, RouteDefinition } from '@/wayfinder';

export type CrudRoutes<T> = {
    fetch: (options?: RouteQueryOptions) => RouteDefinition<'get'>;
    destroy: (
        id: { id: string | number } | [string | number] | string | number,
        options?: RouteQueryOptions,
    ) => RouteDefinition<'delete'>;
    destroyBulk: (options?: RouteQueryOptions) => RouteDefinition<'post'>;
    show: (
        id: { id: string | number } | [string | number] | string | number,
        options?: RouteQueryOptions,
    ) => RouteDefinition<'get'>;
    create: (options?: RouteQueryOptions) => RouteDefinition<'get'>;
};

export function useCrudTable<T>(routes: CrudRoutes<T>) {
    const [deleteId, setDeleteId] = useState<any>();
    const [selected, setSelected] = useState<any[]>([]);
    const { delete: deleteForm, setData, post } = useForm<any>({ ids: [] });

    const onDelete = (e: { preventDefault: () => void }) => {
        e.preventDefault();
        deleteForm(routes.destroy(deleteId).url, FormResponse);
    };

    const onBulkDelete = (e: { preventDefault: () => void }) => {
        e.preventDefault();
        setData('ids', selected);
        post(routes.destroyBulk().url, FormResponse);
    };

    const load = useCallback(
        async (params: Record<string, any>) => {
            const response = await window.fetch(
                routes.fetch({ query: params }).url,
            );
            return response.json() as Promise<Base<T[]>>;
        },
        [routes],
    );

    return {
        deleteId,
        setDeleteId,
        selected,
        setSelected,
        onDelete,
        onBulkDelete,
        load,
    };
}
