<?php

use App\Http\Controllers\Workspace\InvitationAcceptController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Section 5 Paths A and B both read "sign up → verify email → name your
 * workspace", in that order. `verified` is what makes the order real - without
 * it the notice on the dashboard is advice, not a step.
 *
 * Applied to specific routes rather than the whole group, deliberately. Changing
 * an email address clears the proof and starts verification again
 * (AccountService::updateProfile), so anything gated here is something the owner
 * loses for as long as that takes. Reading, switching workspace, revoking an
 * invitation, removing a member and cancelling stay open for that reason: none
 * of them is made dangerous by an unproved address, and locking someone out of
 * their own cleanup is a support ticket we would be creating on purpose.
 */
Route::middleware('auth')->group(function () {
    Route::prefix('workspaces')->as('workspace.')->group(function () {
        Route::post('/', [WorkspaceController::class, 'store'])->middleware('verified')->name('store');
        Route::get('{workspace}/settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::put('{workspace}', [WorkspaceController::class, 'update'])->name('update');
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy'])->name('destroy');
        Route::post('{workspace}/switch', [WorkspaceController::class, 'switchTo'])->name('switch');
        Route::post('{workspace}/transfer', [WorkspaceController::class, 'transferOwnership'])->name('transfer');

        Route::get('{workspace}/members', [MemberController::class, 'index'])->name('member.index');
        Route::get('{workspace}/members/fetch', [MemberController::class, 'fetch'])->name('member.fetch');
        Route::get('{workspace}/members/export', [MemberController::class, 'export'])->name('member.export');
        // Sending mail in the customer's name from an address nobody has
        // proved they own is how a signup form becomes a spam relay.
        Route::post('{workspace}/invitations', [InvitationController::class, 'store'])
            ->middleware('verified')->name('invitation.store');
    });

    Route::prefix('members')->as('workspace.member.')->group(function () {
        Route::put('{member}', [MemberController::class, 'update'])->name('update');
        Route::delete('{member}', [MemberController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('invitations')->as('workspace.invitation.')->group(function () {
        Route::post('{invitation}/resend', [InvitationController::class, 'resend'])
            ->middleware('verified')->name('resend');
        Route::delete('{invitation}', [InvitationController::class, 'destroy'])->name('destroy');
    });

    // Section 5 Path C. Holding the token is the authorisation, but the person
    // still has to be signed in so we know who is joining.
    Route::post('invite/{token}', [InvitationAcceptController::class, 'accept'])->name('invitation.accept');
});

// Public: the emailed link lands here before the invitee has signed in.
Route::get('invite/{token}', [InvitationAcceptController::class, 'show'])->name('invitation.show');
