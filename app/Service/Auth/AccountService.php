<?php

namespace App\Service\Auth;

use App\Contract\Auth\AccountContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\EmailAlreadyTaken;
use App\Exceptions\Domain\EmailChangeNotPending;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class AccountService implements AccountContract
{
    /**
     * A new address is PARKED, never applied.
     *
     * This used to write the new address straight onto `email` and null out
     * `email_verified_at`. That made an established customer look exactly like a
     * signup who had never verified, and once verification became a gate the
     * consequence was a lockout: mistype your new address and you lose your
     * workspaces, your invitations and the cancel button until you can prove an
     * inbox you cannot reach. The proof stays with the address that still owns
     * the account.
     */
    public function updateProfile(User $user, array $payload): User
    {
        $toConfirm = DB::transaction(function () use ($user, $payload) {
            $user->name = $payload['name'];

            $requested = $payload['email'];
            $previouslyPending = $user->pending_email;

            // Asking for the address they already have cancels a pending change
            // rather than parking a pointless one.
            $user->pending_email = $requested === $user->email ? null : $requested;

            $user->save();

            $pending = $user->pending_email;

            return $pending !== null && $pending !== $previouslyPending ? $pending : null;
        });

        // Outside the transaction on purpose. Sending inside it means a later
        // rollback still leaves a confirmation link in someone's inbox for a
        // change this account never recorded.
        if ($toConfirm !== null) {
            $this->sendEmailChangeConfirmation($user, $toConfirm);
        }

        return $user->refresh();
    }

    /**
     * Confirmation goes to the new address; the warning goes to the old one.
     * Only the second reaches the real owner if the session doing this was
     * stolen, which is the whole reason it is sent.
     */
    private function sendEmailChangeConfirmation(User $user, string $pending): void
    {
        Notification::route('mail', $pending)
            ->notify(new ConfirmEmailChangeNotification($user, $pending));

        $user->notify(new EmailChangeRequestedNotification($pending));
    }

    public function confirmEmailChange(User $user, string $hash): User
    {
        return DB::transaction(function () use ($user, $hash) {
            $pending = $user->pending_email;

            // A link issued for an address they have since changed their mind
            // about must not apply the one they asked for later.
            if ($pending === null || ! hash_equals(sha1($pending), $hash)) {
                throw new EmailChangeNotPending;
            }

            // `pending_email` carries no unique index, so the row can still be
            // taken between the request and this click.
            $taken = User::query()
                ->where('email', $pending)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw new EmailAlreadyTaken;
            }

            $user->forceFill([
                'email' => $pending,
                'pending_email' => null,
                // Clicking a link sent to that address IS the proof, so this
                // does not send them round the verification flow again.
                'email_verified_at' => now(),
            ])->save();

            return $user->refresh();
        });
    }

    public function cancelEmailChange(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $user->forceFill(['pending_email' => null])->save();

            return $user->refresh();
        });
    }

    public function changePassword(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            // The `hashed` cast on the model does the hashing.
            $user->update(['password' => $password]);

            return $user->refresh();
        });
    }

    /**
     * Section 3: a workspace must always have at least one owner, and deleting
     * an account is just another way of leaving. Without this the cascade on
     * workspace_members would silently orphan a workspace - possibly a paying
     * one - with nobody able to administer or close it.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->assertNotSoleOwnerAnywhere($user);

            $user->delete();
        });
    }

    private function assertNotSoleOwnerAnywhere(User $user): void
    {
        $soleOwnerships = WorkspaceMember::query()
            ->where('user_id', $user->id)
            ->where('role', WorkspaceRole::Owner)
            ->whereHas('workspace', fn ($query) => $query
                ->whereDoesntHave('members', fn ($other) => $other
                    ->where('role', WorkspaceRole::Owner)
                    ->where('user_id', '!=', $user->id)))
            ->exists();

        if ($soleOwnerships) {
            throw new LastOwnerCannotLeave;
        }
    }
}
