<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * Section 7: "Over a limit? Hard block on writing", and "we tell them exactly
 * how many and let them do it in one place".
 *
 * The pre-flight refusal, as distinct from the workspace-level block. That one
 * is a state a workspace is already IN, computed by UsageService::evaluate()
 * and enforced by WorkspacePolicy. This one stops the write that would put them
 * there - which is the kinder half, because nothing has to be undone.
 */
class LimitReached extends DomainException
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $featureKey,
        public readonly ?int $limit,
    ) {
        parent::__construct("Workspace {$workspace->id} is at its {$featureKey} limit.");
    }

    public function userMessage(): string
    {
        // A missing entitlement is a denial, not a number - saying "your limit
        // is 0" would be a lie about a feature the plan simply does not carry.
        return $this->limit === null
            ? "This plan does not include {$this->featureKey}. Upgrade to add more."
            : "You have used all {$this->limit} of your {$this->featureKey}. Upgrade or free one up to add another.";
    }
}
