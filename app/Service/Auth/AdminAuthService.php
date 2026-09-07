<?php

namespace App\Service\Auth;

use App\Contract\Auth\AdminAuthContract;
use App\Models\AdminUser;
use App\Service\AuthService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Section 3: platform staff, on their own guard and their own table.
 */
class AdminAuthService extends AuthService implements AdminAuthContract
{
    protected string $username = 'email';

    protected ?string $guard = 'admin';

    protected ?string $guardForeignKey = null;

    protected Model $model;

    public function __construct(AdminUser $model)
    {
        $this->model = $model;
    }

    /**
     * Overridden rather than inherited, for two reasons.
     *
     * is_active is part of the credential check, not a test after the fact:
     * deactivating a staff member has to stop the login itself, not merely
     * redirect them afterwards. Soft-deleted rows are excluded automatically by
     * the model's SoftDeletes scope, which is why offboarding keeps the row for
     * the audit trail without keeping the access.
     *
     * The failure message is deliberately generic. The customer login says
     * "Email is not registered", which tells an attacker which addresses exist -
     * an acceptable trade there, not here.
     */
    public function login(array $credentials)
    {
        try {
            $attempt = Auth::guard($this->guard)->attempt([
                $this->username => $credentials[$this->username],
                'password' => $credentials['password'],
                'is_active' => true,
            ], (bool) ($credentials['remember'] ?? false));

            if (! $attempt) {
                return new Exception('These credentials do not match our records.');
            }

            return $attempt;
        } catch (Exception $exception) {
            return $exception;
        }
    }
}
