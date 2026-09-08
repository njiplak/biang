<?php

namespace App\Providers;

use App\Contract\Admin\AnnouncementContract;
use App\Contract\Admin\AuditContract;
use App\Contract\Admin\PageContract;
use App\Contract\Admin\AuditViewContract;
use App\Contract\Admin\BillingOpsContract;
use App\Contract\Admin\CatalogContract;
use App\Contract\Admin\CustomerContract;
use App\Contract\Admin\ImpersonationContract;
use App\Contract\Admin\RevenueContract;
use App\Contract\Admin\SchedulerHealthContract;
use App\Contract\Admin\StaffContract;
use App\Contract\Auth\AccountContract;
use App\Contract\Auth\BrowserSessionContract;
use App\Contract\Auth\AdminAuthContract;
use App\Contract\Auth\TwoFactorContract;
use App\Contract\Auth\UserAuthContract;
use App\Contract\AuthContract;
use App\Contract\BaseContract;
use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\CatalogPublisherContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Billing\ReconcilerContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\SubscriptionPullerContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Billing\WebhookVerifierContract;
use App\Contract\Public\PricingContract;
use App\Contract\Setting\PermissionContract;
use App\Contract\Setting\RoleContract;
use App\Contract\Setting\SettingContract;
use App\Contract\Setting\UserContract;
use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Contract\Workspace\WorkspaceMemberContract;
use App\Service\Admin\AnnouncementService;
use App\Service\Admin\AuditLogger;
use App\Service\Admin\PageService;
use App\Service\Admin\AuditViewService;
use App\Service\Admin\BillingOpsService;
use App\Service\Admin\CatalogService;
use App\Service\Admin\CustomerService;
use App\Service\Admin\ImpersonationService;
use App\Service\Admin\RevenueService;
use App\Service\Admin\SchedulerHealthService;
use App\Service\Admin\StaffService;
use App\Service\Auth\AccountService;
use App\Service\Auth\BrowserSessionService;
use App\Service\Auth\AdminAuthService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\UserAuthService;
use App\Service\AuthService;
use App\Service\BaseService;
use App\Service\Billing\BillingNotifier;
use App\Service\Billing\CatalogPublisher;
use App\Service\Billing\DodoPaymentGateway;
use App\Service\Billing\DodoReconciler;
use App\Service\Billing\EntitlementService;
use App\Service\Billing\StandardWebhookVerifier;
use App\Service\Billing\SubscriptionPuller;
use App\Service\Billing\SubscriptionService;
use App\Service\Billing\UsageService;
use App\Service\Public\PricingService;
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
        BrowserSessionContract::class => BrowserSessionService::class,
        TwoFactorContract::class => TwoFactorService::class,

        // Admin console
        AnnouncementContract::class => AnnouncementService::class,
        AuditContract::class => AuditLogger::class,
        PageContract::class => PageService::class,
        AuditViewContract::class => AuditViewService::class,
        SchedulerHealthContract::class => SchedulerHealthService::class,
        BillingOpsContract::class => BillingOpsService::class,
        CatalogContract::class => CatalogService::class,
        StaffContract::class => StaffService::class,
        CustomerContract::class => CustomerService::class,
        ImpersonationContract::class => ImpersonationService::class,
        RevenueContract::class => RevenueService::class,

        // Billing
        EntitlementContract::class => EntitlementService::class,
        BillingNotifierContract::class => BillingNotifier::class,
        UsageContract::class => UsageService::class,
        SubscriptionContract::class => SubscriptionService::class,
        // Section 8: the seam where their notifications become our state.
        WebhookVerifierContract::class => StandardWebhookVerifier::class,
        ReconcilerContract::class => DodoReconciler::class,
        PaymentGatewayContract::class => DodoPaymentGateway::class,
        // The other way to hear the same news: ask, rather than be told.
        SubscriptionPullerContract::class => SubscriptionPuller::class,
        // Section 10: a price is only sellable once it exists at Dodo too.
        CatalogPublisherContract::class => CatalogPublisher::class,

        // Workspace
        WorkspaceContract::class => WorkspaceService::class,
        MembershipContract::class => MembershipService::class,
        WorkspaceMemberContract::class => WorkspaceMemberService::class,
        InvitationContract::class => InvitationService::class,

        // Public, read by the marketing site (section 11)
        PricingContract::class => PricingService::class,

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
