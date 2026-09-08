<?php

namespace App\Contract\Auth;

use App\Models\User;

/**
 * The person's own account, as distinct from anything workspace-scoped.
 * Nothing here is per-workspace: section 2 keeps who you are separate from
 * what you may do in a given workspace.
 */
interface AccountContract
{
    /**
     * A changed email is parked on `pending_email`, not applied - the account
     * keeps the address it has proved until the new one is confirmed.
     *
     * @param  array{name: string, email: string}  $payload
     */
    public function updateProfile(User $user, array $payload): User;

    /**
     * Confirms the parked address, hash being sha1 of the address the link was
     * issued for.
     *
     * @throws \App\Exceptions\Domain\EmailChangeNotPending
     * @throws \App\Exceptions\Domain\EmailAlreadyTaken
     */
    public function confirmEmailChange(User $user, string $hash): User;

    public function cancelEmailChange(User $user): User;

    public function changePassword(User $user, string $password): User;

    /** Throws LastOwnerCannotLeave if they are the sole owner of any workspace. */
    public function deleteAccount(User $user): void;
}
