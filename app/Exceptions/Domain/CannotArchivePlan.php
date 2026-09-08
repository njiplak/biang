<?php

namespace App\Exceptions\Domain;

use App\Models\Plan;

/**
 * Cancelling drops a workspace onto the FLOOR plan - where it rests, readable
 * and read-only, while nobody is paying. Retiring that plan would leave every
 * future cancellation with nowhere to land, and NoFloorPlanConfigured would
 * start firing on paths that have nothing to do with the catalogue.
 */
class CannotArchivePlan extends DomainException
{
    public function __construct(
        public readonly Plan $plan,
        private readonly string $why,
    ) {
        parent::__construct("Plan {$plan->code} cannot be archived: {$why}.");
    }

    public function userMessage(): string
    {
        return "This plan cannot be retired because {$this->why}.";
    }
}
