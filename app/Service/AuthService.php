<?php

namespace App\Service;

use App\Contract\AuthContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;

class AuthService implements AuthContract
{
    protected string $username = 'email';
    protected string|null $guard = null;
    protected string|null $guardForeignKey = null;
    protected Model $model;

    /**
     * Repositories constructor.
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @return Model
     */
    public function build(): Model
    {
        return $this->model;
    }

    /**
     * Get user id by guard name.
     *
     * @return int
     */
    public function userID(): int
    {
        return Auth::guard($this->guard)->id();
    }

    /**
     * Checks credentials and returns the USER, without starting a session.
     *
     * It used to call Auth::attempt(), which signs the person in as a side
     * effect of being right about the password. That leaves no room for a second
     * factor: by the time the caller could ask whether one is required, the
     * session already exists. Completing the login is completeLogin()'s job now,
     * and the caller decides whether it has earned it.
     *
     * One failure message for every reason. Reporting "Email is not registered."
     * separately from "Incorrect password." let anyone with the login form
     * confirm which of our customers' addresses exist, one guess at a time.
     *
     * What this does NOT fix: an unknown address still answers faster, because
     * a missing row returns before bcrypt is ever reached. Closing that needs a
     * dummy hash on the miss path. Until then the throttle in the controllers is
     * what bounds it.
     */
    public function login(array $credentials)
    {
        try {
            $provider = Auth::guard($this->guard)->getProvider();

            $user = $provider->retrieveByCredentials($this->lookupCredentials($credentials));

            if ($user === null || ! $provider->validateCredentials($user, ['password' => $credentials['password']])) {
                return new Exception('These credentials do not match our records.');
            }

            return $user;
        } catch (Exception $exception) {
            return $exception;
        }
    }

    /**
     * Which row is even allowed to try. Extra terms here are part of the
     * credential check rather than a test after the fact - see AdminAuthService,
     * where a deactivated staff member must fail the login itself.
     *
     * @return array<string, mixed>
     */
    protected function lookupCredentials(array $credentials): array
    {
        return [$this->username => $credentials[$this->username]];
    }

    /** Starts the session. Only called once every factor has been satisfied. */
    public function completeLogin(Authenticatable $user, bool $remember = false): void
    {
        Auth::guard($this->guard)->login($user, $remember);
    }

    /**
     * Register new user.
     *
     * @param array $payloads
     * @return Exception
     */
    public function register(array $payloads, $assignRole = [])
    {
        try {
            DB::beginTransaction();

            $user = $this->model->create($payloads);
            if ($assignRole)
                $user->assignRole($assignRole);

            DB::commit();

            return $user;
        } catch (Exception $exception) {
            DB::rollBack();
            return $exception;
        }
    }

    /**
     * Update user role and profile.
     *
     * @param array $payloads
     * @return Exception
     */
    public function update($id, array $payloads, $assignRole = [])
    {
        try {
            DB::beginTransaction();

            $user = $this->model->find($id);
            $user->update($payloads);
            if ($assignRole)
                $user->syncRoles($assignRole);

            DB::commit();

            return $user->first();
        } catch (Exception $exception) {
            DB::rollBack();
            return $exception;
        }
    }

    /**
     * Logout user from app.
     *
     * @return Exception|true
     */
    public function logout(): Exception|bool
    {
        try {
            Auth::guard($this->guard)->logout();
            return true;
        } catch (Exception $exception) {
            return $exception;
        }
    }
}
