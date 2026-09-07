<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\CheckPermission;
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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
        then: function () {
            $loadRoutes = function ($directory, $middleware) {
                if (!is_dir($directory)) {
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
            // after the session is started, so the switcher's choice is readable
            ResolveWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
        ]);

        // An unauthenticated visitor to /admin belongs at the admin login, not
        // the customer one - sending them to /auth/login would invite them to
        // sign in with an account that can never reach this section anyway.
        // Matched on route NAME, not path: the staff console is `admin.*` plus
        // the older `backoffice.*` screens, which live on a different URL
        // prefix but the same guard. Keying off the path silently sent those
        // to the customer login - an account that can never reach them.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('admin.*', 'backoffice.*')
            ? route('admin.login')
            : '/auth/login');
        // Customers land in their own dashboard. /backoffice is staff-only now.
        $middleware->redirectUsersTo('/dashboard');
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
    })->create();
