<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\CatalogContract;
use App\Contract\Billing\CatalogPublisherContract;
use App\Exceptions\Domain\ProductPublishFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddonPriceRequest;
use App\Http\Requests\Admin\AddonRequest;
use App\Http\Requests\Admin\PlanFeatureRequest;
use App\Http\Requests\Admin\PlanPriceRequest;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 10: "Change what we sell. Create and edit plans, prices, limits and
 * add-ons without an engineer. Retire a plan without breaking the customers
 * already on it."
 *
 * Section 13.1 still lists the value metric as open, which is exactly why this
 * screen exists in this shape: whatever that turns out to be, it becomes a row
 * in `features` and a number on a plan here, not a deploy.
 */
class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogContract $catalog,
        private readonly AuditContract $audit,
        private readonly CatalogPublisherContract $publisher,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/catalog/index', $this->catalog->overview());
    }

    public function storePlan(PlanRequest $request): RedirectResponse
    {
        $plan = $this->catalog->createPlan($request->validated());

        $this->audit->record('catalog.plan_created', null, $plan, ['code' => $plan->code]);

        return back();
    }

    public function updatePlan(PlanRequest $request, Plan $plan): RedirectResponse
    {
        $this->catalog->updatePlan($plan, $request->validated());

        $this->audit->record('catalog.plan_updated', null, $plan, ['code' => $plan->code]);

        return back();
    }

    /**
     * The limits. This is the one that moves customers: every workspace
     * resolving against this plan is rebuilt, because section 7's hard block
     * reads a snapshot rather than the plan itself.
     */
    public function syncFeatures(PlanFeatureRequest $request, Plan $plan): RedirectResponse
    {
        $before = $plan->features->mapWithKeys(fn ($f) => [$f->key => $f->pivot->value])->all();

        $this->catalog->syncFeatures($plan, $request->limits());

        // The one catalogue change that moves existing customers, so what the
        // limits were before is the part worth keeping.
        $this->audit->record('catalog.limits_changed', null, $plan, [
            'plan' => $plan->code,
            'from' => $before,
            'to' => $plan->fresh('features')->features->mapWithKeys(fn ($f) => [$f->key => $f->pivot->value])->all(),
        ]);

        return back();
    }

    public function storePlanPrice(PlanPriceRequest $request, Plan $plan): RedirectResponse
    {
        $price = $this->catalog->addPrice($plan, $request->validated());

        $this->audit->record('catalog.price_added', null, $price, [
            'plan' => $plan->code,
            'amount_minor' => $price->amount_minor,
            'currency' => $price->currency,
            'interval' => $price->billing_interval->value,
        ]);

        return $this->publish($price);
    }

    /**
     * Section 10: a price staff create has to reach Dodo, or nobody can buy it.
     *
     * Deliberately AFTER the price is saved and audited, and deliberately not
     * inside the same transaction: publishing is a call to somebody else's
     * system, and a rollback cannot un-create a product at their end. So a
     * failure leaves a saved-but-unpublished price, says so, and the catalogue
     * screen offers to try again.
     */
    private function publish(PlanPrice|AddonPrice $price): RedirectResponse
    {
        try {
            $this->publisher->publish($price);
        } catch (ProductPublishFailed $e) {
            return back()->withErrors(['errors' => $e->userMessage()]);
        }

        $this->audit->record('catalog.price_published', null, $price->refresh(), [
            'dodo_product_id' => $price->dodo_product_id,
        ]);

        return back();
    }

    /** Retrying a publish that failed, from the catalogue screen. */
    public function publishPlanPrice(Plan $plan, PlanPrice $price): RedirectResponse
    {
        abort_unless($price->plan_id === $plan->id, 404);

        return $this->publish($price);
    }

    public function publishAddonPrice(Addon $addon, AddonPrice $price): RedirectResponse
    {
        abort_unless($price->addon_id === $addon->id, 404);

        return $this->publish($price);
    }

    public function archivePlanPrice(Plan $plan, PlanPrice $price): RedirectResponse
    {
        abort_unless($price->plan_id === $plan->id, 404);

        $this->catalog->archivePrice($price);

        // Section 10: retire it at their end too, or it stays purchasable
        // through a checkout link somebody already has.
        $this->publisher->retire($price);

        $this->audit->record('catalog.price_archived', null, $price, ['plan' => $plan->code]);

        return back();
    }

    public function archivePlan(Plan $plan): RedirectResponse
    {
        // Read BEFORE archiving: archivePlan archives every live price with it.
        $live = $plan->prices()->whereNull('archived_at')->get();

        $this->catalog->archivePlan($plan);

        $live->each(fn (PlanPrice $price) => $this->publisher->retire($price));

        $this->audit->record('catalog.plan_retired', null, $plan, ['code' => $plan->code]);

        return back();
    }

    public function restorePlan(Plan $plan): RedirectResponse
    {
        $this->catalog->restorePlan($plan);

        $this->audit->record('catalog.plan_restored', null, $plan, ['code' => $plan->code]);

        return back();
    }

    public function syncPlanAddons(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'addon_ids' => ['present', 'array'],
            'addon_ids.*' => ['integer', 'exists:addons,id'],
        ]);

        $this->catalog->syncPlanAddons($plan, $validated['addon_ids']);

        $this->audit->record('catalog.plan_addons_changed', null, $plan, [
            'plan' => $plan->code,
            'addon_ids' => $validated['addon_ids'],
        ]);

        return back();
    }

    public function storeAddon(AddonRequest $request): RedirectResponse
    {
        $addon = $this->catalog->createAddon($request->validated());

        $this->audit->record('catalog.addon_created', null, $addon, ['key' => $addon->key]);

        return back();
    }

    public function updateAddon(AddonRequest $request, Addon $addon): RedirectResponse
    {
        $this->catalog->updateAddon($addon, $request->validated());

        $this->audit->record('catalog.addon_updated', null, $addon, ['key' => $addon->key]);

        return back();
    }

    public function storeAddonPrice(AddonPriceRequest $request, Addon $addon): RedirectResponse
    {
        $price = $this->catalog->addAddonPrice($addon, $request->validated());

        $this->audit->record('catalog.addon_price_added', null, $price, [
            'addon' => $addon->key,
            'amount_minor' => $price->amount_minor,
        ]);

        return $this->publish($price);
    }

    public function archiveAddon(Addon $addon): RedirectResponse
    {
        // Read BEFORE archiving: archiveAddon archives every live price with
        // it, so afterwards there is nothing left matching "still on sale".
        $live = $addon->prices()->whereNull('archived_at')->get();

        $this->catalog->archiveAddon($addon);

        $live->each(fn (AddonPrice $price) => $this->publisher->retire($price));

        $this->audit->record('catalog.addon_retired', null, $addon, ['key' => $addon->key]);

        return back();
    }
}
