<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    protected static ?string $password = null;

    protected static ?string $secret = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
            /*
             * Enrolled by default, because EnsureAdminTwoFactor means an
             * unenrolled staff member cannot open a single console screen. A
             * factory that produced one would be building an account that does
             * not exist in production for longer than it takes to scan a QR
             * code.
             *
             * A real secret rather than a placeholder, so a test that drives
             * the challenge with it gets a code that actually verifies.
             */
            'two_factor_secret' => static::$secret ??= (new Google2FA)->generateSecretKey(),
            'two_factor_recovery_codes' => collect(range(1, 8))
                ->map(fn () => Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10)))
                ->all(),
            'two_factor_confirmed_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * A staff member who has not enrolled yet. The console is shut to them -
     * which is the point - so this is for the login and enrolment flows.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
