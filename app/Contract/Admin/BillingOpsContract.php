<?php

namespace App\Contract\Admin;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Section 8's operational half: what the payment integration recorded when
 * something went wrong, and the one action that can be taken about it.
 */
interface BillingOpsContract
{
    public function overview(): array;

    /**
     * One of this screen's lists, paginated.
     *
     * Named rather than split into four methods because they are four views of
     * the same question - what the payment integration recorded when something
     * went wrong - and the screen shows them together. LISTS is the allow-list;
     * anything outside it is rejected at the controller.
     */
    public function paginate(string $list, ?string $search, int $perPage): LengthAwarePaginator;

    public function retry(WebhookEvent $event): void;
}
