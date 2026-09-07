<?php

namespace App\Contract\Public;

/**
 * Section 11: the marketing site is a separate project, and this is the whole
 * of the seam it reads. A price change in the admin console has to reach it
 * without anyone retyping anything.
 */
interface PricingContract
{
    public function published(): array;
}
