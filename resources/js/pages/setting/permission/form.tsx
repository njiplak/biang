import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/setting/permission';
import type { Permission } from '@/types/permission';

type Props = {
    permission?: Permission;
};

export default function PermissionForm({ permission }: Props) {
    const { data, setData, post, put, errors, processing } =
        useForm(permission ?? { name: '' });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (permission) {
            put(update(permission.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {permission ? 'Edit Permission' : 'New Permission'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Name</Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. campaign.create"
                    />
                    <p className="text-xs text-muted-foreground">
                        Use module.action format (e.g., campaign.create, setting.view)
                    </p>
                    <InputError message={errors?.name} />
                </div>
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

PermissionForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
