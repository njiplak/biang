<?php

namespace App\Enums;

/** How a failed-payment episode ended. Section 15 measures recovery rate. */
enum DunningResolution: string
{
    case Recovered = 'recovered';
    case Canceled = 'canceled';
    case Suspended = 'suspended';
}
