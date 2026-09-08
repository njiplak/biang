import { useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CatalogAddon, CatalogFeature } from '@/types/catalog';

/**
 * Section 4's three kinds. An add-on grants a feature exactly as a plan does,
 * which is what keeps entitlement resolution on a single code path.
 */
export function AddonDialog({
    addon,
    features,
}: {
    addon?: CatalogAddon;
    features: CatalogFeature[];
}) {
    const [open, setOpen] = useState(false);
    const editing = addon !== undefined;

    const form = useForm({
        key: addon?.key ?? '',
        name: addon?.name ?? '',
        description: addon?.description ?? '',
        kind: addon?.kind ?? 'quantity',
        feature_id: addon?.feature_id ? String(addon.feature_id) : '',
        grant_per_unit: addon?.grant_per_unit ?? 1,
        max_quantity: addon?.max_quantity ?? null,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            ...createFormResponse(
                editing ? 'Add-on updated.' : 'Add-on created.',
            ),
            onSuccess: () => {
                setOpen(false);
                if (!editing) form.reset();
            },
        };

        form.transform((data: any) => ({
            ...data,
            feature_id: data.feature_id === '' ? null : Number(data.feature_id),
        }));

        if (editing) {
            form.put(admin.catalog.addon.update(addon.id).url, options);
        } else {
            form.post(admin.catalog.addon.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={editing ? 'ghost' : 'default'}
                    size={editing ? 'sm' : 'default'}
                >
                    {editing ? (
                        <Pencil className="size-4" />
                    ) : (
                        <Plus className="size-4" />
                    )}
                    {editing ? '' : 'New add-on'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? `Edit ${addon.name}` : 'New add-on'}
                        </DialogTitle>
                        <DialogDescription>
                            Changing what one unit grants re-resolves every
                            workspace that already owns this add-on.
                        </DialogDescription>
                    </DialogHeader>

                    {!editing && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="addon-key">Key</Label>
                            <Input
                                id="addon-key"
                                value={form.data.key}
                                onChange={(e) =>
                                    form.setData('key', e.target.value)
                                }
                                placeholder="extra_seats"
                            />
                            <InputError message={form.errors.key} />
                        </div>
                    )}

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="addon-name">Name</Label>
                        <Input
                            id="addon-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="addon-kind">Kind</Label>
                        <Select
                            value={form.data.kind}
                            onValueChange={(value) =>
                                form.setData('kind', value as never)
                            }
                        >
                            <SelectTrigger id="addon-kind">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="quantity">
                                    Quantity — more of a limit
                                </SelectItem>
                                <SelectItem value="unlock">
                                    Unlock — turns a feature on
                                </SelectItem>
                                <SelectItem value="metered">
                                    Metered — billed on usage
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.kind} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="addon-feature">Feature it grants</Label>
                        <Select
                            value={form.data.feature_id}
                            onValueChange={(value) =>
                                form.setData('feature_id', value)
                            }
                        >
                            <SelectTrigger id="addon-feature">
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                {features.map((feature) => (
                                    <SelectItem
                                        key={feature.id}
                                        value={String(feature.id)}
                                    >
                                        {feature.name} ({feature.key})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.feature_id} />
                    </div>

                    <div className="flex gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="grant">Grants per unit</Label>
                            <Input
                                id="grant"
                                type="number"
                                min={1}
                                className="w-32"
                                value={form.data.grant_per_unit ?? 1}
                                onChange={(e) =>
                                    form.setData(
                                        'grant_per_unit',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError message={form.errors.grant_per_unit} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="max">Max quantity</Label>
                            <Input
                                id="max"
                                type="number"
                                min={1}
                                className="w-32"
                                value={form.data.max_quantity ?? ''}
                                onChange={(e) =>
                                    form.setData(
                                        'max_quantity',
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                            />
                            <InputError message={form.errors.max_quantity} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Save' : 'Create add-on'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
