<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff RBAC, on the `admin` guard.
 *
 * Section 3 keeps the two worlds apart, and that separation is expressed here
 * by guard_name: nothing this seeder writes is visible to a customer account,
 * and the customer side never uses spatie at all - it uses WorkspaceRole and
 * the workspace policies.
 *
 * Permissions are grouped by section 10's jobs rather than by table, because
 * that is how the team will actually reason about who should have what.
 */
class AdminRoleSeeder extends Seeder
{
    private const GUARD = 'admin';

    /** @var array<string, string[]> */
    private const ROLES = [
        // Everything, plus the Gate::before bypass registered in AppServiceProvider.
        'super-admin' => ['*'],

        // "Answer a support ticket in under a minute" and "reproduce a complaint".
        'support' => [
            'customer.view',
            'customer.impersonate',
            'workspace.suspend',
            'user.view',
        ],

        // "Close a deal / rescue a customer. Sales cannot wait for a deploy."
        'sales' => [
            'customer.view',
            'billing.grant',
            'billing.override',
            'plan.manage',
        ],

        // "Understand the business": MRR, ARR, churn, conversion.
        'finance' => [
            'customer.view',
            'revenue.view',
        ],
    ];

    /** @var array<string, string> */
    private const PERMISSIONS = [
        'customer.view' => 'Find and inspect customers and workspaces',
        'customer.impersonate' => 'Enter a customer workspace as them',
        'workspace.suspend' => 'Suspend a workspace immediately',
        'billing.grant' => 'Grant a plan or extend a trial by hand',
        'billing.override' => 'Override a limit for one customer',
        'plan.manage' => 'Create and retire plans, prices and add-ons',
        'revenue.view' => 'See MRR, ARR, churn and conversion',
        'announcement.manage' => 'Announce maintenance or a new feature',
        'staff.manage' => 'Manage staff accounts and their roles',

        // Gating the pre-existing backoffice screens, which moved onto the
        // admin guard when section 3's separation was enforced.
        'setting.view' => 'View application settings',
        'setting.create' => 'Create application settings',
        'setting.update' => 'Update application settings',
        'setting.delete' => 'Delete application settings',
        'role.view' => 'View staff roles',
        'role.create' => 'Create staff roles',
        'role.update' => 'Update staff roles',
        'role.delete' => 'Delete staff roles',
        'permission.view' => 'View permissions',
        'permission.create' => 'Create permissions',
        'permission.update' => 'Update permissions',
        'permission.delete' => 'Delete permissions',
        'user.view' => 'View customer accounts',
        'user.create' => 'Create customer accounts',
        'user.update' => 'Update customer accounts',
        'user.delete' => 'Delete customer accounts',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (array_keys(self::PERMISSIONS) as $name) {
                Permission::findOrCreate($name, self::GUARD);
            }

            $all = array_keys(self::PERMISSIONS);

            foreach (self::ROLES as $role => $permissions) {
                Role::findOrCreate($role, self::GUARD)
                    ->syncPermissions($permissions === ['*'] ? $all : $permissions);
            }
        });

        // Spatie caches the permission map; without this the roles just written
        // are invisible for the rest of the process, including the next seeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
