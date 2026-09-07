<?php

namespace App\Contract\Admin;

use App\Models\AdminUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Section 3: platform staff on their own guard and their own table, with
 * runtime-editable roles - unlike the fixed five workspace roles.
 *
 * The permission `staff.manage` has existed since the roles were seeded with
 * nothing behind it, which meant offboarding somebody required editing a seeder
 * and deploying. This is what it now gates.
 */
interface StaffContract
{
    /** @return LengthAwarePaginator<int, AdminUser> */
    public function search(?string $term, int $perPage): LengthAwarePaginator;

    public function summarise(AdminUser $admin): array;

    public function create(array $attributes, ?string $role): AdminUser;

    public function update(AdminUser $admin, array $attributes, ?string $role, AdminUser $actor): AdminUser;

    /** Keeps the row for the audit trail; removes the access. */
    public function offboard(AdminUser $admin, AdminUser $actor): void;

    public function roles(): array;
}
