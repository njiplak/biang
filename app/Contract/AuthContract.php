<?php

namespace App\Contract;

use Illuminate\Contracts\Auth\Authenticatable;

interface AuthContract
{
    /**
     * Checks credentials WITHOUT starting a session, returning the user so the
     * caller can require a second factor before completeLogin() is called.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|\Exception
     */
    public function login(array $credentials);

    public function completeLogin(Authenticatable $user, bool $remember = false): void;
    public function register(array $payloads, $assignRole = []);
    public function logout();
    public function update($id, array $payloads, $assignRole = []);
}
