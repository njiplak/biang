<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminTwoFactorChallengeController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BillingOpsController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SchedulerController;
use App\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * Section 3: the admin console, on the same host as the customer app but a
 * completely separate login. Every route here is behind the `admin` guard, so
 * a signed-in customer is simply a guest as far as this section is concerned.
 *
 * One prefix for the whole console. The staff settings screens live in
 * routes/web/setting.php under the same `admin.` name, so redirectGuestsTo can
 * recognise every staff screen from its route name alone.
 */
Route::prefix('admin')->as('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminAuthController::class, 'login'])->name('login');
        Route::post('login', [AdminAuthController::class, 'attempt'])->name('attempt');

        // The second half of a staff login: password proved, no session yet.
        Route::get('two-factor-challenge', [AdminTwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
        Route::post('two-factor-challenge', [AdminTwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');
        Route::delete('two-factor-challenge', [AdminTwoFactorChallengeController::class, 'destroy'])->name('two-factor.challenge.abandon');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        /*
         * Staff enrolment. No `permission:` gate - protecting your OWN account
         * is not a privileged action, and gating it would leave the staff
         * without that permission as the only ones who cannot.
         */
        Route::get('two-factor', [AdminTwoFactorController::class, 'edit'])->name('two-factor.edit');
        Route::post('two-factor', [AdminTwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('two-factor/confirm', [AdminTwoFactorController::class, 'confirm'])
            ->middleware('throttle:6,1')->name('two-factor.confirm');
        Route::post('two-factor/recovery-codes', [AdminTwoFactorController::class, 'regenerate'])->name('two-factor.recovery-codes');
        Route::delete('two-factor', [AdminTwoFactorController::class, 'destroy'])->name('two-factor.destroy');

        /*
         * Deliberately outside the `customer.view` gate. This is the way OUT of
         * a customer account, and a staff member who somehow lost that
         * permission mid-session must still be able to leave.
         */
        Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])
            ->name('impersonation.stop');

        /*
         * Section 10: "Change what we sell ... without an engineer. Retire a
         * plan without breaking the customers already on it." Section 13.1
         * leaves the value metric open, and this is where it lands when chosen.
         */
        Route::prefix('catalog')->as('catalog.')->middleware('permission:plan.manage')->group(function () {
            Route::get('/', [CatalogController::class, 'index'])->name('index');

            Route::post('plans', [CatalogController::class, 'storePlan'])->name('plan.store');
            Route::put('plans/{plan}', [CatalogController::class, 'updatePlan'])->name('plan.update');
            // The limits. Rebuilds every workspace resolving against this plan.
            Route::put('plans/{plan}/features', [CatalogController::class, 'syncFeatures'])->name('plan.features');
            Route::put('plans/{plan}/addons', [CatalogController::class, 'syncPlanAddons'])->name('plan.addons');
            Route::post('plans/{plan}/prices', [CatalogController::class, 'storePlanPrice'])->name('plan.price.store');
            /*
             * Section 10: publishing to Dodo is a call to somebody else's
             * system and can fail while our own save succeeded, so retrying it
             * has to be an action staff can take - otherwise an unsellable
             * price needs a deploy to fix, which is the thing this section
             * exists to avoid.
             */
            Route::post('plans/{plan}/prices/{price}/publish', [CatalogController::class, 'publishPlanPrice'])->name('plan.price.publish');
            Route::delete('plans/{plan}/prices/{price}', [CatalogController::class, 'archivePlanPrice'])->name('plan.price.archive');
            Route::delete('plans/{plan}', [CatalogController::class, 'archivePlan'])->name('plan.archive');
            Route::post('plans/{plan}/restore', [CatalogController::class, 'restorePlan'])->name('plan.restore');

            Route::post('addons', [CatalogController::class, 'storeAddon'])->name('addon.store');
            Route::put('addons/{addon}', [CatalogController::class, 'updateAddon'])->name('addon.update');
            Route::post('addons/{addon}/prices', [CatalogController::class, 'storeAddonPrice'])->name('addon.price.store');
            Route::post('addons/{addon}/prices/{price}/publish', [CatalogController::class, 'publishAddonPrice'])->name('addon.price.publish');
            Route::delete('addons/{addon}', [CatalogController::class, 'archiveAddon'])->name('addon.archive');
        });

        /*
         * Section 10: "a permanent record that we did it." Behind customer.view
         * because the people who answer tickets are the people who need to know
         * what was done to a customer.
         */
        Route::prefix('audit')->as('audit.')->middleware('permission:customer.view')->group(function () {
            Route::get('/', [AuditController::class, 'index'])->name('index');
            Route::get('fetch', [AuditController::class, 'fetch'])->name('fetch');
            Route::get('export', [AuditController::class, 'export'])->name('export');
        });

        // Section 16: trial-ending emails are a launch blocker, and they are
        // sent by a scheduled command.
        Route::prefix('scheduler')->as('scheduler.')->middleware('permission:setting.view')->group(function () {
            Route::get('/', [SchedulerController::class, 'index'])->name('index');
            Route::get('fetch', [SchedulerController::class, 'fetch'])->name('fetch');
        });

        /*
         * Section 3: staff accounts. `staff.manage` was seeded with nothing
         * behind it, which made revoking a leaver's console access a deploy.
         */
        Route::prefix('staff')->as('staff.')->middleware('permission:staff.manage')->group(function () {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::get('fetch', [StaffController::class, 'fetch'])->name('fetch');
            Route::post('/', [StaffController::class, 'store'])->name('store');
            Route::put('{staff}', [StaffController::class, 'update'])->name('update');
            Route::delete('{staff}', [StaffController::class, 'destroy'])->name('destroy');
        });

        /*
         * Section 8: what the payment integration recorded when something went
         * wrong. Gated on revenue.view - the same people who own the money.
         */
        Route::prefix('billing-ops')->as('billing-ops.')->middleware('permission:revenue.view')->group(function () {
            Route::get('/', [BillingOpsController::class, 'index'])->name('index');
            Route::get('fetch', [BillingOpsController::class, 'fetch'])->name('fetch');
            Route::post('webhooks/{event}/retry', [BillingOpsController::class, 'retry'])->name('retry');
        });

        // Section 10: "Announce maintenance or a new feature to all customers."
        Route::prefix('announcements')->as('announcement.')->middleware('permission:announcement.manage')->group(function () {
            Route::get('/', [AnnouncementController::class, 'index'])->name('index');
            Route::post('/', [AnnouncementController::class, 'store'])->name('store');
            Route::put('{announcement}', [AnnouncementController::class, 'update'])->name('update');
            Route::post('{announcement}/publish', [AnnouncementController::class, 'publish'])->name('publish');
            Route::delete('{announcement}/publish', [AnnouncementController::class, 'unpublish'])->name('unpublish');
            Route::delete('{announcement}', [AnnouncementController::class, 'destroy'])->name('destroy');
        });

        /*
         * Section 10: "Answer a support ticket in under a minute", "Close a
         * deal / rescue a customer", "Stop abuse". Staff RBAC still applies -
         * being staff is not enough, and support deliberately cannot grant.
         */
        Route::prefix('customers')->as('customer.')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])
                ->name('index')->middleware('permission:customer.view');
            Route::get('fetch', [CustomerController::class, 'fetch'])
                ->name('fetch')->middleware('permission:customer.view');
            Route::get('export', [CustomerController::class, 'export'])
                ->name('export')->middleware('permission:customer.view');

            // withTrashed: a ticket about a workspace that vanished is exactly
            // when support needs to open it. The ACTIONS below deliberately do
            // not, so a closed workspace can be read but not operated on.
            Route::get('{workspace}', [CustomerController::class, 'show'])
                ->name('show')->middleware('permission:customer.view')->withTrashed();

            // The detail page's tables. Same gate and same withTrashed as show.
            Route::get('{workspace}/fetch', [CustomerController::class, 'fetchDetail'])
                ->name('fetch-detail')->middleware('permission:customer.view')->withTrashed();

            Route::post('{workspace}/suspend', [CustomerController::class, 'suspend'])
                ->name('suspend')->middleware('permission:workspace.suspend');
            Route::delete('{workspace}/suspend', [CustomerController::class, 'unsuspend'])
                ->name('unsuspend')->middleware('permission:workspace.suspend');

            Route::post('{workspace}/plan', [CustomerController::class, 'grantPlan'])
                ->name('plan')->middleware('permission:billing.grant');
            Route::post('{workspace}/trial', [CustomerController::class, 'extendTrial'])
                ->name('trial')->middleware('permission:billing.grant');

            /*
             * Section 10: "Reproduce a complaint." Support has this permission;
             * sales and finance deliberately do not.
             */
            Route::post('{workspace}/impersonate', [ImpersonationController::class, 'start'])
                ->name('impersonate')->middleware('permission:customer.impersonate');

            Route::post('{workspace}/overrides', [CustomerController::class, 'storeOverride'])
                ->name('override.store')->middleware('permission:billing.override');
            Route::delete('{workspace}/overrides/{override}', [CustomerController::class, 'destroyOverride'])
                ->name('override.destroy')->middleware('permission:billing.override')
                ->whereNumber('override');
        });
    });
});
