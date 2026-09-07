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
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CatalogPlan } from '@/types/catalog';

/**
 * `code` is set once and never edited: it is how seeders, tests and any future
 * integration address a plan. The display name is free to change.
 */
export function PlanDialog({ plan }: { plan?: CatalogPlan }) {
    const [open, setOpen] = useState(false);
    const editing = plan !== undefined;

    const form = useForm({
        code: plan?.code ?? '',
        name: plan?.name ?? '',
        description: plan?.description ?? '',
        is_public: plan?.is_public ?? true,
        sort_order: plan?.sort_order ?? 0,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            ...createFormResponse(editing ? 'Plan updated.' : 'Plan created.'),
            onSuccess: () => {
                setOpen(false);
                if (!editing) form.reset();
            },
        };

        if (editing) {
            form.put(admin.catalog.plan.update(plan.id).url, options);
        } else {
            form.post(admin.catalog.plan.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant={editing ? 'outline' : 'default'} size={editing ? 'sm' : 'default'}>
                    {editing ? <Pencil className="size-4" /> : <Plus className="size-4" />}
                    {editing ? 'Edit' : 'New plan'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? `Edit ${plan.name}` : 'New plan'}
                        </DialogTitle>
                        <DialogDescription>
                            A new plan starts with no limits, which means it
                            allows nothing until you set them.
                        </DialogDescription>
                    </DialogHeader>

                    {!editing && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="plan-code">Code</Label>
                            <Input
                                id="plan-code"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="scale"
                            />
                            <p className="text-xs text-muted-foreground">
                                Lowercase, dashes. Permanent once created.
                            </p>
                            <InputError message={form.errors.code} />
                        </div>
                    )}

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="plan-name">Name</Label>
                        <Input
                            id="plan-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="plan-description">Description</Label>
                        <Textarea
                            id="plan-description"
                            value={form.data.description ?? ''}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="flex flex-wrap items-center gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_public}
                                onChange={(e) =>
                                    form.setData('is_public', e.target.checked)
                                }
                            />
                            Show on the pricing page
                        </label>
                        <div className="flex items-center gap-2">
                            <Label htmlFor="plan-sort">Order</Label>
                            <Input
                                id="plan-sort"
                                type="number"
                                min={0}
                                className="w-24"
                                value={form.data.sort_order}
                                onChange={(e) =>
                                    form.setData(
                                        'sort_order',
                                        Number(e.target.value),
                                    )
                                }
                            />
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
                            {editing ? 'Save' : 'Create plan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
