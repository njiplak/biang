<?php

namespace App\Service\Auth;

use App\Contract\Auth\UserAuthContract;
use App\Models\User;
use App\Service\AuthService;
use Illuminate\Database\Eloquent\Model;

class UserAuthService extends AuthService implements UserAuthContract
{
    protected string $username = 'email';
    protected string|null $guard = 'web';
    protected string|null $guardForeignKey = null;
    protected Model $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }
}
