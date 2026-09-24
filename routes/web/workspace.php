<?php

use App\Http\Controllers\Workspace\InvitationAcceptController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Section 5 Paths A and B both read "sign up → verify email → name your
 * workspace", in that order, so the whole group is gated.
 *
 * It used to be gated route by route, to spare an owner who had just changed
 * their email address: that cleared the proof, and a group-wide gate would have
 * locked them out of their own cleanup. AccountService no longer clears it - a
 * new address is parked on `pending_email` until it is confirmed - so an
 * unverified account can now only be one thing, a signup that has never
 * verified, and such an account owns no workspace to be locked out of.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('workspaces')->as('workspace.')->group(function () {
        Route::post('/', [WorkspaceController::class, 'store'])->name('store');
        Route::get('{workspace}/settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::put('{workspace}', [WorkspaceController::class, 'update'])->name('update');
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy'])->name('destroy');
        // withTrashed: a closed workspace is soft-deleted, and restoring one is
        // the only route that must still find it. The policy does the gating.
        Route::post('{workspace}/restore', [WorkspaceController::class, 'restore'])
            ->withTrashed()->name('restore');
        Route::post('{workspace}/switch', [WorkspaceController::class, 'switchTo'])->name('switch');
        Route::post('{workspace}/transfer', [WorkspaceController::class, 'transferOwnership'])->name('transfer');

        Route::get('{workspace}/members', [MemberController::class, 'index'])->name('member.index');
        Route::get('{workspace}/members/fetch', [MemberController::class, 'fetch'])->name('member.fetch');
        Route::get('{workspace}/members/export', [MemberController::class, 'export'])->name('member.export');
        // Sending mail in the customer's name from an address nobody has
        // proved they own is how a signup form becomes a spam relay.
        Route::post('{workspace}/invitations', [InvitationController::class, 'store'])
            ->name('invitation.store');
    });

    Route::prefix('members')->as('workspace.member.')->group(function () {
        Route::put('{member}', [MemberController::class, 'update'])->name('update');
        Route::delete('{member}', [MemberController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('invitations')->as('workspace.invitation.')->group(function () {
        Route::post('{invitation}/resend', [InvitationController::class, 'resend'])
            ->name('resend');
        Route::delete('{invitation}', [InvitationController::class, 'destroy'])->name('destroy');
    });

    /*
     * Section 5 Path C. Holding the token is the authorisation, but the person
     * still has to be signed in so we know who is joining.
     *
     * Outside the `verified` gate on purpose. The workspace owner chose to
     * invite this address, joining creates nothing billable in the joiner's own
     * name, and gating it would strand the one person whose email we have
     * already mailed. It is also the only way an unverified account can reach
     * anything, which is why nothing here sends mail or spends money.
     */
    Route::post('invite/{token}', [InvitationAcceptController::class, 'accept'])
        ->withoutMiddleware('verified')
        ->name('invitation.accept');
});

// Public: the emailed link lands here before the invitee has signed in.
Route::get('invite/{token}', [InvitationAcceptController::class, 'show'])->name('invitation.show');
