<?php

namespace App\Enums;

/**
 * Why a customer left, in their own words as far as a dropdown allows.
 *
 * Section 15 asks for "monthly churn, and how much of it is voluntary versus
 * failed payments" - and voluntary churn is only a useful number if you know
 * what it was FOR. Leaving over a missing feature and leaving over price want
 * opposite responses, and a single "cancelled" count cannot tell them apart.
 *
 * Mirrors the values Dodo accepts so the answer can be passed straight through
 * to them, but declared here rather than imported: this is what we validate and
 * store, and our own metrics should not break because a vendor renamed a case.
 *
 * Always optional. Section 8's exit is never blocked, and that includes not
 * making somebody answer a question to leave.
 */
enum CancellationFeedback: string
{
    case TooExpensive = 'too_expensive';
    case MissingFeatures = 'missing_features';
    case SwitchedService = 'switched_service';
    case Unused = 'unused';
    case CustomerService = 'customer_service';
    case LowQuality = 'low_quality';
    case TooComplex = 'too_complex';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TooExpensive => 'Too expensive',
            self::MissingFeatures => 'Missing features I need',
            self::SwitchedService => 'Switched to something else',
            self::Unused => 'We were not using it',
            self::CustomerService => 'Support was not good enough',
            self::LowQuality => 'It did not work well enough',
            self::TooComplex => 'Too complicated',
            self::Other => 'Something else',
        };
    }
}
