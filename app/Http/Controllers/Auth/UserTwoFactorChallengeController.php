<?php

namespace App\Http\Controllers\Auth;

use App\Contract\Auth\TwoFactorContract;
use App\Contract\Auth\UserAuthContract;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/** The customer half of the challenge. */
class UserTwoFactorChallengeController extends TwoFactorChallengeController
{
    public function __construct(
        TwoFactorContract $twoFactor,
        private readonly UserAuthContract $auth,
    ) {
        parent::__construct($twoFactor);
    }

    protected function guard(): string
    {
        return 'web';
    }

    protected function loginRoute(): string
    {
        return 'login';
    }

    protected function intendedRoute(): string
    {
        return 'dashboard';
    }

    protected function page(): string
    {
        return 'auth/two-factor-challenge';
    }

    protected function findUser(int|string $id): ?Authenticatable
    {
        return User::find($id);
    }

    protected function completeLogin(Authenticatable $user, bool $remember): void
    {
        $this->auth->completeLogin($user, $remember);
    }
}
