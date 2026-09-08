<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Auth\AdminAuthContract;
use App\Contract\Auth\TwoFactorContract;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\AdminUser;
use Illuminate\Contracts\Auth\Authenticatable;

/** The staff half of the challenge. Separate guard, separate table, section 3. */
class AdminTwoFactorChallengeController extends TwoFactorChallengeController
{
    public function __construct(
        TwoFactorContract $twoFactor,
        private readonly AdminAuthContract $auth,
    ) {
        parent::__construct($twoFactor);
    }

    protected function guard(): string
    {
        return 'admin';
    }

    protected function loginRoute(): string
    {
        return 'admin.login';
    }

    protected function intendedRoute(): string
    {
        return 'admin.dashboard';
    }

    protected function page(): string
    {
        return 'admin/auth/two-factor-challenge';
    }

    /**
     * is_active is rechecked here, not just at the password step: staff can be
     * deactivated between the two, and the second half must not let them in on
     * the strength of a check that has since stopped being true.
     */
    protected function findUser(int|string $id): ?Authenticatable
    {
        return AdminUser::where('is_active', true)->find($id);
    }

    protected function completeLogin(Authenticatable $user, bool $remember): void
    {
        $this->auth->completeLogin($user, $remember);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();
    }
}
