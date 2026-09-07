<?php

namespace App\Http\Controllers\PublicApi;

use App\Contract\Public\PricingContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Section 11. Public and unauthenticated on purpose - it publishes exactly what
 * the pricing page already shows the world, and nothing else.
 *
 * Only `public()` plans are included, so an internal or retired plan is never
 * exposed here even though staff can still put customers on it by hand.
 */
class PricingController extends Controller
{
    public function __construct(private readonly PricingContract $pricing) {}

    public function __invoke(): JsonResponse
    {
        return response()
            ->json($this->pricing->published())
            // The marketing site may render this on every page view. A short
            // shared cache keeps a price change visible in minutes without
            // putting their traffic on our database.
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }
}
