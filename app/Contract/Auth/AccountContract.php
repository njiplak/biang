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
    /** @param  array{name: string, email: string}  $payload */
    public function updateProfile(User $user, array $payload): User;

    public function changePassword(User $user, string $password): User;

    /** Throws LastOwnerCannotLeave if they are the sole owner of any workspace. */
    public function deleteAccount(User $user): void;
}
