<?php

namespace App\Service\Billing;

use App\Contract\Billing\CatalogPublisherContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Exceptions\Domain\ProductPublishFailed;
use App\Models\AddonPrice;
use App\Models\PlanPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The seam between our catalogue and Dodo's product list (section 10).
 *
 * Everything here is written around one fact: publishing is a network call to
 * somebody else's system, and it can fail while our own write succeeded. So a
 * price is created locally first and published second, never in one
 * transaction - a rollback cannot un-create a product at their end, and a
 * provider outage must not stop staff editing the catalogue at all.
 *
 * The visible consequence is that "saved" and "on sale" are two different
 * states, which is exactly what the admin catalogue screen now shows.
 */
class CatalogPublisher implements CatalogPublisherContract
{
    public function __construct(private readonly PaymentGatewayContract $gateway) {}

    public function publish(PlanPrice|AddonPrice $price): PlanPrice|AddonPrice
    {
        // Already published. Doing it again would mint a second one at their
        // end and leave the first collecting subscriptions we no longer point
        // at - an invisible split of one plan's revenue across two products.
        if (filled($this->providerId($price))) {
            return $price;
        }

        /*
         * A plan price becomes a PRODUCT; an add-on price becomes an ADD-ON.
         * They are different resources at Dodo with different endpoints and
         * different id spaces, and a subscription attaches them by different
         * fields - so this is not a naming difference we could paper over.
         */
        if ($price instanceof AddonPrice) {
            $price->update([
                'dodo_addon_id' => $this->gateway->publishAddon(
                    $this->name($price),
                    $price->currency,
                    $price->amount_minor,
                    $this->description($price),
                ),
            ]);

            return $price->refresh();
        }

        $price->update([
            'dodo_product_id' => $this->gateway->publishProduct(
                $this->name($price),
                $price->currency,
                $price->amount_minor,
                $price->billing_interval,
                $this->description($price),
            ),
        ]);

        return $price->refresh();
    }

    /** Whichever provider id this kind of price is published under. */
    private function providerId(PlanPrice|AddonPrice $price): ?string
    {
        return $price instanceof AddonPrice
            ? $price->dodo_addon_id
            : $price->dodo_product_id;
    }

    public function retire(PlanPrice|AddonPrice $price): void
    {
        /*
         * Only products are archived. Dodo's add-on API has no archive, and an
         * add-on cannot be bought on its own anyway - it is only ever attached
         * to a subscription through a plan we control, so retiring the plan is
         * what actually takes it off sale.
         */
        if ($price instanceof AddonPrice || blank($price->dodo_product_id)) {
            return;
        }

        /*
         * Swallowed deliberately, and the only place in this class that does.
         * Retiring a plan is OUR decision (section 10) and it has already
         * happened in our records; a provider we cannot reach must not be able
         * to veto it. The product stays live at their end, which is harmless -
         * our own routes refuse an archived price - and re-archiving is safe.
         */
        try {
            $this->gateway->archiveProduct($price->dodo_product_id);
        } catch (ProductPublishFailed $e) {
            Log::warning('Could not archive the provider product for a retired price.', [
                'price' => $price::class.':'.$price->id,
                'dodo_product_id' => $price->dodo_product_id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{plan: Collection<int, PlanPrice>, addon: Collection<int, AddonPrice>} */
    public function unpublished(): array
    {
        return [
            'plan' => PlanPrice::query()
                ->whereNull('archived_at')
                ->whereNull('dodo_product_id')
                ->with('plan')
                ->get(),
            'addon' => AddonPrice::query()
                ->whereNull('archived_at')
                ->whereNull('dodo_addon_id')
                ->with('addon')
                ->get(),
        ];
    }

    /**
     * What the customer sees on Dodo's checkout and on the invoice they keep.
     * The interval is part of the name because a plan has one product per
     * interval, and "Pro" twice on a receipt tells nobody which they bought.
     */
    private function name(PlanPrice|AddonPrice $price): string
    {
        // An add-on takes the interval of the subscription it hangs off, so
        // naming one "(Monthly)" would be a promise we do not control.
        if ($price instanceof AddonPrice) {
            return $price->addon->name;
        }

        $interval = ucfirst($price->billing_interval->value).'ly';

        return "{$price->plan->name} ({$interval})";
    }

    private function description(PlanPrice|AddonPrice $price): ?string
    {
        $parent = $price instanceof PlanPrice ? $price->plan : $price->addon;

        return $parent->description;
    }
}
