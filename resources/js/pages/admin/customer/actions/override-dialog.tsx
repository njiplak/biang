import { useForm } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CustomerWorkspace, FeatureOption } from '@/types/customer';

/**
 * Section 10: "Override a limit for one specific customer."
 *
 * The override REPLACES whatever the plan and add-ons resolved to - it is not
 * an adjustment on top - and it replaces any override already live on the same
 * feature.
 */
export function OverrideDialog({
    workspace,
    features,
}: {
    workspace: CustomerWorkspace;
    features: FeatureOption[];
}) {
    const [open, setOpen] = useState(false);
    const [unlimited, setUnlimited] = useState(false);
    const form = useForm<{
        feature_id: string;
        value: number | null;
        reason: string;
        expires_at: string | null;
    }>({ feature_id: '', value: 0, reason: '', expires_at: null });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // Null is unlimited in the entitlement layer, so the checkbox has to
        // send an explicit null rather than omit the field.
        form.transform((data) => ({
            ...data,
            value: unlimited ? null : data.value,
            expires_at: data.expires_at || null,
        }));

        form.post(admin.customer.override.store(workspace.ulid).url, {
            ...createFormResponse('Limit overridden.'),
            onSuccess: () => {
                setOpen(false);
                setUnlimited(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <SlidersHorizontal className="size-4" />
                    Override a limit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Override a limit</DialogTitle>
                        <DialogDescription>
                            Applies to this customer only, and takes effect
                            straight away - including lifting a block they are
                            currently under.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="feature">Feature</Label>
                        <Select
                            value={form.data.feature_id}
                            onValueChange={(value) =>
                                form.setData('feature_id', value)
                            }
                        >
                            <SelectTrigger id="feature">
                                <SelectValue placeholder="Pick a feature" />
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

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="value">New limit</Label>
                        <Input
                            id="value"
                            type="number"
                            min={0}
                            disabled={unlimited}
                            value={unlimited ? '' : (form.data.value ?? 0)}
                            onChange={(e) =>
                                form.setData('value', Number(e.target.value))
                            }
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={unlimited}
                                onCheckedChange={(checked) =>
                                    setUnlimited(checked === true)
                                }
                            />
                            Unlimited
                        </label>
                        <InputError message={form.errors.value} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="expires">Expires (optional)</Label>
                        <Input
                            id="expires"
                            type="date"
                            value={form.data.expires_at ?? ''}
                            onChange={(e) =>
                                form.setData('expires_at', e.target.value)
                            }
                        />
                        <InputError message={form.errors.expires_at} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="override-reason">Reason</Label>
                        <Textarea
                            id="override-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="What did we agree, and with whom?"
                        />
                        <InputError message={form.errors.reason} />
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
                            Apply override
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
