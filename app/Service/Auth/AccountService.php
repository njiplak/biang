<?php

namespace App\Service\Auth;

use App\Contract\Auth\AccountContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

class AccountService implements AccountContract
{
    public function updateProfile(User $user, array $payload): User
    {
        return DB::transaction(function () use ($user, $payload) {
            $user->fill($payload);

            // A changed address has not been proved to belong to them, so the
            // proof goes with it and the verification flow starts again.
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

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
