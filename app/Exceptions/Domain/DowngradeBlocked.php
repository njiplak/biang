<?php

namespace App\Exceptions\Domain;

/**
 * Section 7: "Workspace has 8 members and wants to move to a 5-seat plan. The
 * downgrade is blocked until they remove three people. We tell them exactly how
 * many and let them do it in one place."
 *
 * Thrown for a first PURCHASE as much as for a switch. A workspace that has
 * been read-only with 25 people can pick a 5-seat plan just as easily as one
 * downgrading into it, and finding out after the card is charged is far worse -
 * Dodo is merchant of record, so that is a refund request, not a rollback.
 *
 * The excess is carried so the UI can say "remove 3 people", not "too many
 * members". We do not delete anyone's data to make it fit.
 */
class DowngradeBlocked extends DomainException
{
    public readonly int $excess;

    public function __construct(
        public readonly string $featureKey,
        public readonly int $used,
        public readonly int $limit,
    ) {
        $this->excess = $used - $limit;

        parent::__construct("Downgrade blocked: {$featureKey} usage {$used} exceeds new limit {$limit}.");
    }

    public function userMessage(): string
    {
        return "This plan allows {$this->limit} and this workspace is using {$this->used}. Remove {$this->excess} before choosing it - nothing is deleted by doing so.";
    }
}
