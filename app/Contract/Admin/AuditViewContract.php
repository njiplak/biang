<?php

namespace App\Contract\Admin;

use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reading the trail. Section 10's "a permanent record that we did it" is only
 * worth having if somebody can actually read it - and a customer asking "who
 * was in my account on the 3rd" has to be answerable from their own timeline,
 * not ours.
 */
interface AuditViewContract
{
    /** @return LengthAwarePaginator<int, \App\Models\AuditLog> */
    public function search(?string $term, ?string $action, int $perPage): LengthAwarePaginator;

    public function present(\App\Models\AuditLog $log): array;

    /** Impersonation sessions, optionally narrowed to one customer. */
    public function impersonations(?Workspace $workspace, int $limit = 50): array;

    /** Distinct actions actually recorded, for the filter. */
    public function actions(): array;
}
