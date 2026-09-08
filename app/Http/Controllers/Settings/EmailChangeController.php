<?php

namespace App\Http\Controllers\Settings;

use App\Contract\Auth\AccountContract;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The second half of an email change. The first half only parks the address
 * (AccountService::updateProfile); nothing moves until a link sent to that
 * address is clicked.
 */
class EmailChangeController extends Controller
{
    public function __construct(private readonly AccountContract $service) {}

    /**
     * `signed` proves the link came from us and has not expired; the id check
     * below proves it is being used by the account it was issued for. Without
     * the second, a forwarded link would move whoever happened to be signed in.
     */
    public function confirm(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        if (! hash_equals((string) $user->getKey(), $id)) {
            throw new AuthorizationException;
        }

        $this->service->confirmEmailChange($user, $hash);

        return to_route('profile.edit')->with('status', 'email-changed');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->service->cancelEmailChange($request->user());

        return to_route('profile.edit')->with('status', 'email-change-cancelled');
    }
}
