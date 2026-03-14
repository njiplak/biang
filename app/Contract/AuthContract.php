<?php

namespace App\Contract;

interface AuthContract
{
    public function login(array $credentials);
    public function register(array $payloads, $assignRole = []);
    public function logout();
    public function update($id, array $payloads, $assignRole = []);
    public function sendOTP(array $payloads);
    public function validateOTP(array $payloads);
    public function resetPassword(array $payloads);
}
