import { Head, router, useForm } from '@inertiajs/react';
import { Archive, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type {
    CatalogAddon,
    CatalogFeature,
    CatalogOverview,
    CatalogPlan,
    CatalogPrice,
} from '@/types/catalog';
import { AddonDialog } from './addon-dialog';
import { PlanDialog } from './plan-dialog';
import { PriceDialog } from './price-dialog';

function money(price: CatalogPrice) {
    return `${price.currency} ${(price.amount_minor / 100).toFixed(2)} / ${price.interval}`;
}

/**
 * Section 10: "Change what we sell ... without an engineer. Retire a plan
 * without breaking the customers already on it."
 */
export default function CatalogIndex({
    plans,
    addons,
    features,
}: CatalogOverview) {
    return (
        <div className="flex flex-col gap-6">
            <Head title="Plans and add-ons" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col">
                    <h1 className="text-xl font-semibold">Plans and add-ons</h1>
                    <p className="text-sm text-muted-foreground">
                        Prices are never edited in place. Changing one archives
                        the old row and writes a new one, so nobody is repriced.
                    </p>
                </div>
                <PlanDialog />
            </div>

            <div className="flex flex-col gap-4">
                {plans.map((plan) => (
                    <PlanCard
                        key={plan.id}
                        plan={plan}
                        features={features}
                        addons={addons}
                    />
                ))}
            </div>

            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Add-ons</h2>
                <AddonDialog features={features} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {addons.map((addon) => (
                    <AddonCard
                        key={addon.id}
                        addon={addon}
                        features={features}
                    />
                ))}
                {addons.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing sold as an add-on yet.
                    </p>
                )}
            </div>
        </div>
    );
}

function PlanCard({
    plan,
    features,
    addons,
}: {
    plan: CatalogPlan;
    features: CatalogFeature[];
    addons: CatalogAddon[];
}) {
    const [editingLimits, setEditingLimits] = useState(false);

    return (
        <Card className={plan.is_archived ? 'opacity-70' : undefined}>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                <div>
                    <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                        {plan.name}
                        <span className="text-xs font-normal text-muted-foreground">
                            {plan.code}
                        </span>
                        {/* Not a product: where a workspace rests when no
                            subscription is live. Never sold, never public. */}
                        {plan.is_free && (
                            <Badge variant="secondary">Read-only floor</Badge>
                        )}
                        {plan.is_archived && (
                            <Badge variant="outline">Retired</Badge>
                        )}
                        {!plan.is_public && !plan.is_archived && (
                            <Badge variant="outline">Hidden</Badge>
                        )}
                    </CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {plan.live_subscriptions} live{' '}
                        {plan.live_subscriptions === 1
                            ? 'subscription'
                            : 'subscriptions'}
                        {plan.description ? ` · ${plan.description}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <PlanDialog plan={plan} />
                    <PriceDialog plan={plan} />
                    {plan.is_archived ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    admin.catalog.plan.restore(plan.id).url,
                                )
                            }
                        >
                            <RotateCcw className="size-4" />
                            Un-retire
                        </Button>
                    ) : (
                        !plan.is_free && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.delete(
                                        admin.catalog.plan.archive(plan.id).url,
                                    )
                                }
                            >
                                <Archive className="size-4" />
                                Retire
                            </Button>
                        )
                    )}
                </div>
            </CardHeader>

            <CardContent className="flex flex-col gap-4 text-sm">
                <div>
                    <div className="mb-1 flex items-center justify-between">
                        <p className="font-medium">Limits</p>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditingLimits((v) => !v)}
                        >
                            {editingLimits ? 'Cancel' : 'Edit limits'}
                        </Button>
                    </div>

                    {editingLimits ? (
                        <LimitsForm
                            plan={plan}
                            features={features}
                            onDone={() => setEditingLimits(false)}
                        />
                    ) : (
                        <ul className="flex flex-wrap gap-x-6 gap-y-1 text-muted-foreground">
                            {plan.features.length === 0 && (
                                <li>
                                    No limits set. This plan currently allows
                                    nothing.
                                </li>
                            )}
                            {plan.features.map((feature) => (
                                <li key={feature.id}>
                                    {feature.name}:{' '}
                                    <span className="font-medium text-foreground">
                                        {feature.value === null
                                            ? 'Unlimited'
                                            : feature.value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div>
                    <p className="mb-1 font-medium">Prices</p>
                    <ul className="flex flex-col gap-1">
                        {plan.prices.length === 0 && (
                            <li className="text-muted-foreground">
                                {plan.is_free
                                    ? 'The floor plan is never sold, so it has no price and never reaches the payment provider.'
                                    : 'No price yet — this plan cannot be sold.'}
                            </li>
                        )}
                        {plan.prices.map((price) => (
                            <li
                                key={price.id}
                                className="flex items-center justify-between gap-3"
                            >
                                <span
                                    className={
                                        price.is_archived
                                            ? 'text-muted-foreground line-through'
                                            : 'flex items-center gap-2'
                                    }
                                >
                                    {money(price)}
                                    {!price.is_archived && (
                                        <SellableBadge price={price} />
                                    )}
                                </span>
                                {!price.is_archived && (
                                    <span className="flex items-center gap-1">
                                        {!price.is_published && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        admin.catalog.plan.price.publish(
                                                            {
                                                                plan: plan.id,
                                                                price: price.id,
                                                            },
                                                        ).url,
                                                    )
                                                }
                                            >
                                                Publish
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    admin.catalog.plan.price.archive(
                                                        {
                                                            plan: plan.id,
                                                            price: price.id,
                                                        },
                                                    ).url,
                                                )
                                            }
                                        >
                                            Archive
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>

                <AddonPicker plan={plan} addons={addons} />
            </CardContent>
        </Card>
    );
}

/**
 * The limits editor. Saving rebuilds every workspace on this plan, because
 * section 7's hard block reads a materialised snapshot rather than the plan.
 */
function LimitsForm({
    plan,
    features,
    onDone,
}: {
    plan: CatalogPlan;
    features: CatalogFeature[];
    onDone: () => void;
}) {
    const form = useForm({
        limits: features.map((feature) => {
            const existing = plan.features.find((f) => f.id === feature.id);

            return {
                feature_id: feature.id,
                value: existing ? existing.value : 0,
                // Local only: a null value means unlimited on the wire.
                unlimited: existing ? existing.value === null : false,
                include: Boolean(existing),
            };
        }),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data: any) => ({
            limits: data.limits
                .filter((row: any) => row.include)
                .map((row: any) => ({
                    feature_id: row.feature_id,
                    value: row.unlimited ? null : Number(row.value),
                })),
        }));

        form.put(admin.catalog.plan.features(plan.id).url, {
            ...createFormResponse('Limits updated and customers rebuilt.'),
            onSuccess: onDone,
        });
    };

    const setRow = (index: number, patch: Record<string, unknown>) =>
        form.setData(
            'limits',
            form.data.limits.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ) as never,
        );

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <p className="text-xs text-muted-foreground">
                Saving re-resolves every workspace on this plan straight away.
            </p>
            {form.data.limits.map((row, index) => {
                const feature = features[index];

                return (
                    <div
                        key={feature.id}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <label className="flex w-48 items-center gap-2">
                            <input
                                type="checkbox"
                                checked={row.include}
                                onChange={(e) =>
                                    setRow(index, { include: e.target.checked })
                                }
                            />
                            <span>{feature.name}</span>
                        </label>
                        <Input
                            type="number"
                            min={0}
                            className="w-32"
                            disabled={!row.include || row.unlimited}
                            value={row.unlimited ? '' : (row.value ?? 0)}
                            onChange={(e) =>
                                setRow(index, { value: Number(e.target.value) })
                            }
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                disabled={!row.include}
                                checked={row.unlimited}
                                onChange={(e) =>
                                    setRow(index, {
                                        unlimited: e.target.checked,
                                    })
                                }
                            />
                            Unlimited
                        </label>
                    </div>
                );
            })}
            <InputError message={form.errors.limits} />
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Save limits
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function AddonPicker({
    plan,
    addons,
}: {
    plan: CatalogPlan;
    addons: CatalogAddon[];
}) {
    const live = addons.filter((addon) => !addon.is_archived);

    if (live.length === 0) return null;

    const toggle = (id: number) => {
        const next = plan.addon_ids.includes(id)
            ? plan.addon_ids.filter((existing) => existing !== id)
            : [...plan.addon_ids, id];

        router.put(
            admin.catalog.plan.addons(plan.id).url,
            { addon_ids: next },
            { preserveScroll: true },
        );
    };

    return (
        <div>
            <p className="mb-1 font-medium">Add-ons offered on this plan</p>
            <div className="flex flex-wrap gap-3">
                {live.map((addon) => (
                    <label
                        key={addon.id}
                        className="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            checked={plan.addon_ids.includes(addon.id)}
                            onChange={() => toggle(addon.id)}
                        />
                        {addon.name}
                    </label>
                ))}
            </div>
        </div>
    );
}

function AddonCard({
    addon,
    features,
}: {
    addon: CatalogAddon;
    features: CatalogFeature[];
}) {
    return (
        <Card className={addon.is_archived ? 'opacity-70' : undefined}>
            <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                <div>
                    <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                        {addon.name}
                        <Badge variant="secondary">{addon.kind}</Badge>
                        {addon.is_archived && (
                            <Badge variant="outline">Retired</Badge>
                        )}
                    </CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {addon.feature_key
                            ? `Grants ${addon.grant_per_unit ?? 1} × ${addon.feature_key} per unit`
                            : 'Grants no feature'}
                    </p>
                </div>
                <div className="flex gap-2">
                    <AddonDialog addon={addon} features={features} />
                    {!addon.is_archived && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.delete(
                                    admin.catalog.addon.archive(addon.id).url,
                                )
                            }
                        >
                            <Archive className="size-4" />
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent className="text-sm">
                <ul className="flex flex-col gap-1">
                    {addon.prices.length === 0 && (
                        <li className="text-muted-foreground">No price yet.</li>
                    )}
                    {addon.prices.map((price) => (
                        <li
                            key={price.id}
                            className={
                                price.is_archived
                                    ? 'text-muted-foreground line-through'
                                    : 'flex items-center justify-between gap-3'
                            }
                        >
                            <span className="flex items-center gap-2">
                                {money(price)}
                                {!price.is_archived && (
                                    <SellableBadge price={price} />
                                )}
                            </span>
                            {!price.is_archived && !price.is_published && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            admin.catalog.addon.price.publish({
                                                addon: addon.id,
                                                price: price.id,
                                            }).url,
                                        )
                                    }
                                >
                                    Publish
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
                <PriceDialog addon={addon} />
            </CardContent>
        </Card>
    );
}

/**
 * Section 10: a price only sells once Dodo has a product for it. Saved and
 * on-sale are different states, and staff have to be able to see which one a
 * price is in - otherwise the first anyone hears about it is a customer whose
 * checkout refused.
 */
function SellableBadge({ price }: { price: CatalogPrice }) {
    if (price.is_published) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className="border-amber-500/40 text-amber-700 dark:text-amber-400"
        >
            Not on sale
        </Badge>
    );
}

CatalogIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
