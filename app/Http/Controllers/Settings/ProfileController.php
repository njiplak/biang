<?php

namespace App\Http\Controllers\Settings;

use App\Contract\Auth\AccountContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteAccountRequest;
use App\Http\Requests\Settings\ProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountContract $service) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => ! $request->user()->hasVerifiedEmail(),
            // The address they asked to move to and have not confirmed. Shown
            // so a change that is waiting on an inbox they cannot reach is
            // visible and cancellable, rather than silently stuck.
            'pendingEmail' => $request->user()->pending_email,
            'status' => session('status'),
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $this->service->updateProfile($request->user(), $request->validated());

        return to_route('profile.edit');
    }

    /**
     * LastOwnerCannotLeave is thrown by the service and rendered centrally, so
     * a sole owner is told to hand over first rather than silently orphaning a
     * workspace (section 3).
     */
    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Log out BEFORE deleting. SessionGuard::logout() cycles the remember
        // token, which calls save() on the model - and saving an already
        // deleted model re-INSERTs it, silently undoing the deletion.
        Auth::guard('web')->logout();

        $this->service->deleteAccount($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
