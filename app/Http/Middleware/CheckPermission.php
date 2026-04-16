<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasAnyPermission($permissions)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
