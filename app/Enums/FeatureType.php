<?php

namespace App\Enums;

enum FeatureType: string
{
    /** A numeric ceiling: seats, projects, storage. */
    case Limit = 'limit';

    /** On or off: a capability the plan either includes or does not. */
    case Boolean = 'boolean';

    /** Billed on actual consumption past what the plan includes (section 4). */
    case Metered = 'metered';
}
