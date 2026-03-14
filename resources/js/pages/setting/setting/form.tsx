import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/setting/setting';
import type { Setting } from '@/types/setting';

type Props = {
    setting?: Setting;
};

export default function SettingForm({ setting }: Props) {
    const { data, setData, post, put, errors, processing } =
        useForm(setting ?? { key: '', value: '' });

    const onSubmit = (e: React.SubmitEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (setting) {
            put(update(setting.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {setting ? 'Edit Setting' : 'New Setting'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Key</Label>
                    <Input
                        value={data.key}
                        onChange={(e) => setData('key', e.target.value)}
                    />
                    <InputError message={errors?.key} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label>Value</Label>
                    <Textarea
                        rows={3}
                        value={data.value}
                        onChange={(e) => setData('value', e.target.value)}
                    />
                    <InputError message={errors?.value} />
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

SettingForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
