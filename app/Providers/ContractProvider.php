<?php

namespace App\Providers;

use App\Contract\Auth\AccountContract;
use App\Contract\Auth\AdminAuthContract;
use App\Contract\Auth\UserAuthContract;
use App\Contract\AuthContract;
use App\Contract\BaseContract;
use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Setting\PermissionContract;
use App\Contract\Setting\RoleContract;
use App\Contract\Setting\SettingContract;
use App\Contract\Setting\UserContract;
use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Contract\Workspace\WorkspaceMemberContract;
use App\Service\Auth\AccountService;
use App\Service\Auth\AdminAuthService;
use App\Service\Auth\UserAuthService;
use App\Service\AuthService;
use App\Service\BaseService;
use App\Service\Billing\BillingNotifier;
use App\Service\Billing\EntitlementService;
use App\Service\Billing\SubscriptionService;
use App\Service\Billing\UsageService;
use App\Service\Setting\PermissionService;
use App\Service\Setting\RoleService;
use App\Service\Setting\SettingService;
use App\Service\Setting\UserService;
use App\Service\Workspace\InvitationService;
use App\Service\Workspace\MembershipService;
use App\Service\Workspace\WorkspaceMemberService;
use App\Service\Workspace\WorkspaceService;
use Illuminate\Support\ServiceProvider;

class ContractProvider extends ServiceProvider
{
    public array $bindings = [
        // Base
        BaseContract::class => BaseService::class,
        AuthContract::class => AuthService::class,
        UserAuthContract::class => UserAuthService::class,
        AdminAuthContract::class => AdminAuthService::class,
        AccountContract::class => AccountService::class,

        // Billing
        EntitlementContract::class => EntitlementService::class,
        BillingNotifierContract::class => BillingNotifier::class,
        UsageContract::class => UsageService::class,
        SubscriptionContract::class => SubscriptionService::class,

        // Workspace
        WorkspaceContract::class => WorkspaceService::class,
        MembershipContract::class => MembershipService::class,
        WorkspaceMemberContract::class => WorkspaceMemberService::class,
        InvitationContract::class => InvitationService::class,

        // Setting
        SettingContract::class => SettingService::class,
        RoleContract::class => RoleService::class,
        PermissionContract::class => PermissionService::class,
        UserContract::class => UserService::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $contract => $service) {
            $this->app->bind($contract, $service);
        }
    }

    public function boot(): void {}
}
