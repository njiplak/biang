<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EndImpersonationForRevokedStaff;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
        then: function () {
            $loadRoutes = function ($directory, $middleware) {
                if (! is_dir($directory)) {
                    return;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        Route::middleware($middleware)->group($file->getPathname());
                    }
                }
            };

            $loadRoutes(base_path('routes/web'), 'web');
            $loadRoutes(base_path('routes/api'), 'api');
        }
    )
    ->withSchedule(function (Schedule $schedule) {
        /*
         * Section 16: "Trial-ending emails are a launch blocker, not a
         * nice-to-have." A scheduler that silently stops looks exactly like a
         * scheduler with nothing to do, so every task here is monitored -
         * spatie/laravel-schedule-monitor records each run and flags a task
         * that misses its window.
         *
         * Run `php artisan schedule-monitor:sync` after changing this list.
         */
        $schedule->command('billing:trial-warnings')
            ->dailyAt('09:00')
            ->monitorName('trial-warnings')
            ->graceTimeInMinutes(60);

        // Hourly rather than daily: the trial ends at a time of day, not on a
        // date, and section 4 promises the charge happens at the end of day 14.
        $schedule->command('billing:convert-trials')
            ->hourly()
            ->monitorName('convert-trials')
            ->graceTimeInMinutes(90);

        /*
         * Section 9: "Renewal fails → Email + banner." Hourly, not daily,
         * because this is what sends that first email - a customer who learns
         * about a declined card a day late has spent a day of their own grace
         * window not knowing. The escalations it also sends land on day
         * boundaries regardless of how often it runs.
         */
        $schedule->command('billing:dunning-reminders')
            ->hourly()
            ->monitorName('dunning-reminders')
            ->graceTimeInMinutes(90);

        /*
         * The safety net under the webhook. Hourly because the thing it catches
         * is a notification that never arrived, and until it runs our records
         * are granting access on the strength of a payment we have not actually
         * confirmed - which with cancellation available on Dodo's own page
         * (section 8) is access somebody may have already stopped paying for.
         */
        $schedule->command('billing:reconcile-subscriptions')
            ->hourly()
            ->monitorName('reconcile-subscriptions')
            ->graceTimeInMinutes(90);

        $schedule->command('billing:expire-grace')
            ->dailyAt('02:00')
            ->monitorName('expire-grace');

        $schedule->command('workspaces:purge')
            ->dailyAt('03:00')
            ->monitorName('purge-closed-workspaces');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            /*
             * Before ResolveWorkspace, so a session whose staff member has been
             * deactivated never gets as far as resolving the customer's
             * workspace and answering policies out of it.
             */
            EndImpersonationForRevokedStaff::class,
            // after the session is started, so the switcher's choice is readable
            ResolveWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            // Both run AFTER auth:admin, which is what makes the admin guard
            // the default one and so what makes $request->user('admin') resolve.
            'admin.active' => EnsureAdminIsActive::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
        ]);

        // An unauthenticated visitor to /admin belongs at the admin login, not
        // the customer one - sending them to /auth/login would invite them to
        // sign in with an account that can never reach this section anyway.
        // Matched on route NAME, not path: every staff screen is `admin.*`,
        // which is the whole point of keeping them under one prefix.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('admin.*')
            ? route('admin.login')
            : '/auth/login');
        /*
         * Section 11: "Start trial carries the chosen plan through signup."
         * A visitor who is ALREADY signed in gets bounced off the guest-only
         * signup page, and without this the plan they picked is dropped in
         * silence - they land on their dashboard with no idea it was lost.
         * Billing is where a signed-in person can act on it.
         */
        $middleware->redirectUsersTo(fn (Request $request) => $request->routeIs('register') && $request->filled('plan')
            ? route('billing.index', ['plan' => $request->query('plan')])
            : '/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain rules are thrown from the Service layer, never returned, so a
        // nested failure cannot be committed by an outer DB::transaction().
        // Rendering them here means no controller has to remember to catch.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->userMessage()], 422);
            }

            return back()->withErrors(['errors' => $e->userMessage()]);
        });

        /*
         * Customers never see a bare framework error page. A member sent
         * somewhere they may not go, or a link to something that is gone, gets
         * a page that says what happened and how to get back.
         */
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            // JSON callers and the provider's webhooks want the status, not a page.
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $response;
            }

            $status = $response->getStatusCode();

            // An expired session on a form: send them back to try again rather
            // than to a "Page Expired" screen with no way forward.
            if ($status === 419) {
                // 303 so a PUT or DELETE is followed with a GET; this runs before
                // the Inertia middleware that would otherwise convert it.
                return back(303)->with('warning', 'That page had expired, so nothing was saved. Please try again.');
            }

            // Local and test runs keep the framework's pages and stack traces.
            if (app()->environment(['local', 'testing']) || ! in_array($status, [403, 404, 500, 503], true)) {
                return $response;
            }

            return Inertia::render('error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
