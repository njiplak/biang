<?php

namespace App\Service\Admin;

use App\Contract\Admin\StaffContract;
use App\Exceptions\Domain\StaffLockout;
use App\Models\AdminUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class StaffService implements StaffContract
{
    private const GUARD = 'admin';

    private const SUPER_ADMIN = 'super-admin';

    public function search(?string $term, int $perPage): LengthAwarePaginator
    {
        // Soft-deleted rows are included on purpose: offboarding keeps the row
        // so the audit trail still resolves a name, and staff need to see that
        // somebody was removed rather than never existing.
        return AdminUser::withTrashed()
            ->when(filled($term), fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->whereLike('name', "%{$term}%")
                    ->orWhereLike('email', "%{$term}%")
            ))
            ->with('roles')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function summarise(AdminUser $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->roles->first()?->name,
            'is_active' => $admin->is_active,
            'is_offboarded' => $admin->trashed(),
            // Section 3: who was in the console and when is the first question
            // asked when somebody leaves.
            'last_login_at' => $admin->last_login_at,
            'last_login_ip' => $admin->last_login_ip,
            'created_at' => $admin->created_at,
        ];
    }

    public function create(array $attributes, ?string $role): AdminUser
    {
        return DB::transaction(function () use ($attributes, $role) {
            $admin = AdminUser::create($attributes);

            if ($role !== null) {
                $admin->syncRoles([$role]);
            }

            return $admin->fresh(['roles']);
        });
    }

    public function update(AdminUser $admin, array $attributes, ?string $role, AdminUser $actor): AdminUser
    {
        return DB::transaction(function () use ($admin, $attributes, $role, $actor) {
            // Blank means "leave it alone". Without this, saving a name change
            // would silently reset the password to an empty hash.
            if (blank($attributes['password'] ?? null)) {
                unset($attributes['password']);
            }

            $deactivating = array_key_exists('is_active', $attributes) && ! $attributes['is_active'];

            if ($deactivating) {
                $this->assertNotSelf($admin, $actor, 'you cannot deactivate your own account');
                $this->assertNotLastSuperAdmin($admin, 'that is the last super-admin, and deactivating it would lock everyone out');
            }

            // Moving somebody OFF super-admin is the same lockout as removing
            // them, and is the easier one to do without noticing.
            if ($role !== self::SUPER_ADMIN && $admin->hasRole(self::SUPER_ADMIN)) {
                $this->assertNotLastSuperAdmin($admin, 'that is the last super-admin, and changing its role would lock everyone out');
            }

            $admin->update($attributes);
            $admin->syncRoles($role === null ? [] : [$role]);

            return $admin->fresh(['roles']);
        });
    }

    /**
     * Soft delete, never a hard one. impersonation_sessions and audit_logs
     * reference admin_users with restrictOnDelete precisely so a leaver's
     * history cannot be erased by removing their account - and the model's
     * SoftDeletes scope is what makes the login refuse them.
     */
    public function offboard(AdminUser $admin, AdminUser $actor): void
    {
        DB::transaction(function () use ($admin, $actor) {
            $this->assertNotSelf($admin, $actor, 'you cannot offboard your own account');
            $this->assertNotLastSuperAdmin($admin, 'that is the last super-admin, and removing it would lock everyone out');

            // Both, deliberately. is_active is part of the credential check, so
            // it stops the login even if the row is ever restored by hand.
            $admin->update(['is_active' => false]);
            $admin->delete();
        });
    }

    public function roles(): array
    {
        return Role::query()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => ['name' => $role->name])
            ->all();
    }

    private function assertNotSelf(AdminUser $admin, AdminUser $actor, string $why): void
    {
        if ($admin->is($actor)) {
            throw new StaffLockout($why);
        }
    }

    private function assertNotLastSuperAdmin(AdminUser $admin, string $why): void
    {
        if (! $admin->hasRole(self::SUPER_ADMIN)) {
            return;
        }

        // Counted with a fresh query rather than a loaded relation, and
        // restricted to accounts that could actually sign in - a deactivated
        // or offboarded super-admin is not a way back in.
        $remaining = AdminUser::query()
            ->where('is_active', true)
            ->whereKeyNot($admin->getKey())
            ->whereHas('roles', fn (Builder $query) => $query
                ->where('name', self::SUPER_ADMIN)
                ->where('guard_name', self::GUARD))
            ->count();

        if ($remaining === 0) {
            throw new StaffLockout($why);
        }
    }
}
