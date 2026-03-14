<?php

namespace App\Providers;

use App\Contract\Auth\UserAuthContract;
use App\Contract\AuthContract;
use App\Contract\BaseContract;
use App\Contract\Setting\PermissionContract;
use App\Contract\Setting\RoleContract;
use App\Contract\Setting\SettingContract;
use App\Service\Auth\UserAuthService;
use App\Service\AuthService;
use App\Service\BaseService;
use App\Service\Setting\PermissionService;
use App\Service\Setting\RoleService;
use App\Service\Setting\SettingService;
use Illuminate\Support\ServiceProvider;

class ContractProvider extends ServiceProvider
{
    public array $bindings = [
        // Base
        BaseContract::class => BaseService::class,
        AuthContract::class => AuthService::class,
        UserAuthContract::class => UserAuthService::class,

        // Setting
        SettingContract::class => SettingService::class,
        RoleContract::class => RoleService::class,
        PermissionContract::class => PermissionService::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $contract => $service) {
            $this->app->bind($contract, $service);
        }
    }

    public function boot(): void {}
}
