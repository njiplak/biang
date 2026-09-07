<?php

namespace App\Contract\Admin;

use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Section 10: "Answer a support ticket in under a minute. Find any customer by
 * email or workspace name. See their plan, state, seat usage and payment
 * history without opening a second tool."
 *
 * Read model for the staff console. Everything here reads ACROSS tenants, so
 * every query on a workspace-scoped table lifts the tenancy scope explicitly -
 * staff may hold a customer session in the same browser (section 3 separates
 * the guards, it does not stop both existing), and an ambient workspace would
 * otherwise silently filter the console down to their own.
 */
interface CustomerContract
{
    /** @return LengthAwarePaginator<int, Workspace> */
    public function search(?string $term, int $perPage): LengthAwarePaginator;

    /** Everything the detail screen shows, and the options its actions offer. */
    public function overview(Workspace $workspace): array;

    /** One row of the directory listing. */
    public function summarise(Workspace $workspace): array;

    /**
     * One of the customer detail page's lists, paginated for its table.
     *
     * @throws \InvalidArgumentException when $list is not one of LISTS
     */
    public function paginateDetail(Workspace $workspace, string $list, ?string $search, int $perPage): LengthAwarePaginator;
}
