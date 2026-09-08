<?php

namespace App\Service\Auth;

use App\Contract\Auth\AdminAuthContract;
use App\Models\AdminUser;
use App\Service\AuthService;
use Illuminate\Database\Eloquent\Model;

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
     * is_active is part of the credential check, not a test after the fact:
     * deactivating a staff member has to stop the login itself, not merely
     * redirect them afterwards. Soft-deleted rows are excluded automatically by
     * the model's SoftDeletes scope, which is why offboarding keeps the row for
     * the audit trail without keeping the access.
     *
     * @return array<string, mixed>
     */
    protected function lookupCredentials(array $credentials): array
    {
        return [
            $this->username => $credentials[$this->username],
            'is_active' => true,
        ];
    }
}
