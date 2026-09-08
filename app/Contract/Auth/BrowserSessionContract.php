<?php

namespace App\Contract\Auth;

use App\Models\User;

interface BrowserSessionContract
{
    /** @return array<int, array<string, mixed>> */
    public function forUser(User $user, string $currentSessionId): array;

    /** @return int the number of sessions ended */
    public function logOutOthers(User $user, string $currentSessionId): int;
}
