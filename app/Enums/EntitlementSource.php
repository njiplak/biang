<?php

namespace App\Enums;

enum EntitlementSource: string
{
    case Plan = 'plan';
    case Addon = 'addon';

    /** Section 10: staff override a limit for one specific customer. */
    case Override = 'override';
}
