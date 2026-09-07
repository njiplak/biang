<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * Section 7: "A seat limit should be a sales moment, not a wall." The exception
 * carries the numbers so the caller can offer the specific, priced add-on that
 * removes the block rather than a bare refusal.
 */
class SeatLimitReached extends DomainException
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $used,
        public readonly int $limit,
    ) {
        parent::__construct("Workspace {$workspace->id} is at its seat limit ({$used}/{$limit}).");
    }

    public function userMessage(): string
    {
        return "This workspace is using all {$this->limit} of its seats. Add a seat to invite someone else.";
    }
}
