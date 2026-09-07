<?php

namespace App\Contract\Admin;

use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;

/**
 * Section 10: "Reproduce a complaint. Enter a customer's workspace as them,
 * with an obvious banner saying so and a permanent record that we did it."
 *
 * The row in impersonation_sessions IS that permanent record. Nothing here
 * touches the HTTP session - becoming the customer is the controller's job, and
 * keeping the two apart is what lets the record outlive the browser.
 */
interface ImpersonationContract
{
    public function start(
        AdminUser $admin,
        User $user,
        Workspace $workspace,
        string $reason,
        ?string $ticketReference = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ImpersonationSession;

    public function stop(ImpersonationSession $session): ImpersonationSession;

    /** The one open session for this staff member, if any. */
    public function open(AdminUser $admin): ?ImpersonationSession;
}
