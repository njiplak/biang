<?php

use App\Enums\WorkspaceRole;

// This test IS spec section 3's table. If someone widens a role, this fails
// before it reaches a policy.
it('grants workspace administration to the owner alone', function () {
    expect(WorkspaceRole::Owner->canAdministerWorkspace())->toBeTrue();

    foreach ([WorkspaceRole::Admin, WorkspaceRole::BillingManager, WorkspaceRole::Member, WorkspaceRole::Viewer] as $role) {
        expect($role->canAdministerWorkspace())->toBeFalse("{$role->value} must not administer the workspace");
    }
});

it('lets admins manage people but never billing', function () {
    expect(WorkspaceRole::Admin->canManageMembers())->toBeTrue()
        ->and(WorkspaceRole::Admin->canManageBilling())->toBeFalse();
});

it('lets billing managers touch billing but never people', function () {
    expect(WorkspaceRole::BillingManager->canManageBilling())->toBeTrue()
        ->and(WorkspaceRole::BillingManager->canManageMembers())->toBeFalse();
});

it('makes viewers read only', function () {
    expect(WorkspaceRole::Viewer->canWrite())->toBeFalse()
        ->and(WorkspaceRole::Member->canWrite())->toBeTrue();
});

// Section 9: regular members should not learn about their company's card
// problems from us.
it('sends billing notifications only to owners and billing managers', function () {
    expect(WorkspaceRole::Owner->receivesBillingNotifications())->toBeTrue()
        ->and(WorkspaceRole::BillingManager->receivesBillingNotifications())->toBeTrue()
        ->and(WorkspaceRole::Admin->receivesBillingNotifications())->toBeFalse()
        ->and(WorkspaceRole::Member->receivesBillingNotifications())->toBeFalse()
        ->and(WorkspaceRole::Viewer->receivesBillingNotifications())->toBeFalse();
});
