<?php

namespace App\Contract\Workspace;

use App\Contract\BaseContract;

/**
 * Read side of workspace membership, for the NextTable `/fetch` endpoint.
 *
 * Deliberately separate from MembershipContract: that one owns the RULES
 * (last-owner protection, seat accounting) and throws; this one is the generic
 * paginated query BaseService already provides.
 */
interface WorkspaceMemberContract extends BaseContract {}
