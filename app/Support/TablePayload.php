<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The envelope every admin table reads (`Base<T[]>` on the front end).
 *
 * Written out by hand in seven controllers before this existed, and about to be
 * written out in nine more - at which point one of them quietly disagrees about
 * what `next_page` means at the last page and a table stops paginating for
 * reasons nobody can see from the screen.
 */
final class TablePayload
{
    /**
     * @param  Closure(mixed): array<string, mixed>  $present  how one row is shaped for the table
     * @return array<string, mixed>
     */
    public static function from(LengthAwarePaginator $paginator, Closure $present): array
    {
        return [
            'items' => collect($paginator->items())->map($present)->values()->all(),
            // null rather than 0 at the ends: the table treats a number as "a
            // page exists in that direction" and would offer a page 0.
            'prev_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'current_page' => $paginator->currentPage(),
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'total_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
