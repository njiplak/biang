<?php

namespace App\Console\Commands;

use App\Contract\Billing\CatalogPublisherContract;
use App\Exceptions\Domain\ProductPublishFailed;
use App\Models\AddonPrice;
use App\Models\PlanPrice;
use Illuminate\Console\Command;

/**
 * Publishes every live price that Dodo does not know about yet.
 *
 * Two jobs. It backfills a catalogue that predates publishing - which is every
 * price created before this existed, none of which can be bought. And it is the
 * bulk retry for prices whose publish failed while the provider was unreachable.
 *
 * Safe to run repeatedly: publishing is idempotent on the stored product id, so
 * a price that is already on sale is skipped rather than duplicated.
 */
class PublishCatalog extends Command
{
    protected $signature = 'billing:publish-catalog {--dry-run : List what would be published without calling the provider}';

    protected $description = 'Publish every live plan and add-on price to the payment provider';

    public function handle(CatalogPublisherContract $publisher): int
    {
        $pending = $publisher->unpublished();
        $prices = [...$pending['plan'], ...$pending['addon']];

        if ($prices === []) {
            $this->info('Every live price is already published.');

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        foreach ($prices as $price) {
            $label = $this->label($price);

            if ($this->option('dry-run')) {
                $this->line("would publish: {$label}");

                continue;
            }

            try {
                $publisher->publish($price);
                $published++;
                $this->info("published: {$label}");
            } catch (ProductPublishFailed $e) {
                // Carry on. One rejected price must not leave the rest of the
                // catalogue unsellable, and the exit code still reports it.
                $failed++;
                $this->error("failed: {$label} - {$e->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.count($prices).' price(s) would be published.');

            return self::SUCCESS;
        }

        $this->info("Published: {$published}. Failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function label(PlanPrice|AddonPrice $price): string
    {
        $parent = $price instanceof PlanPrice ? $price->plan : $price->addon;

        return sprintf(
            '%s %s/%s (%s %d)',
            $parent?->name ?? 'unknown',
            $price->currency,
            $price->billing_interval->value,
            $price instanceof PlanPrice ? 'plan price' : 'addon price',
            $price->id,
        );
    }
}
