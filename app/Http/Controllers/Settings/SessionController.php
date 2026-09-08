<?php

namespace App\Http\Controllers\Settings;

use App\Contract\Auth\BrowserSessionContract;
use App\Http\Controllers\Controller;
use App\Service\Auth\BrowserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Where am I signed in, and get me out of everywhere else."
 *
 * The account pages now offer a second factor, and a second factor with no way
 * to end a session somebody else already has is half a control: it stops the
 * next login and does nothing about the one already open.
 */
class SessionController extends Controller
{
    public function __construct(private readonly BrowserSessionContract $sessions) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/sessions', [
            'sessions' => $this->sessions->forUser(
                $request->user(),
                $request->session()->getId(),
            ),
            /*
             * Said out loud rather than rendered as an empty list. Only the
             * database driver keeps sessions where they can be read, and a
             * screen that silently shows nothing would read as "you are signed
             * in nowhere" - which is the opposite of the truth.
             */
            'available' => app(BrowserSessionService::class)->isDatabaseDriver(),
            // Flashed by destroy(). Read explicitly, the way the other account
            // screens do - only `flash.warning` is shared globally, so a
            // `status` nobody passes never reaches the page.
            'status' => session('status'),
        ]);
    }

    /**
     * Behind `password.confirm`, for the same reason the two-factor changes
     * are: someone who has walked up to an unlocked laptop should not be able
     * to lock the real owner out of every other device they own.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $ended = $this->sessions->logOutOthers(
            $request->user(),
            $request->session()->getId(),
        );

        return back()->with('status', $ended === 1
            ? 'One other session was signed out.'
            : "{$ended} other sessions were signed out.");
    }
}
