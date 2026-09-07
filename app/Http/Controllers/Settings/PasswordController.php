<?php

namespace App\Http\Controllers\Settings;

use App\Contract\Auth\AccountContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function __construct(private readonly AccountContract $service) {}

    public function edit(): Response
    {
        return Inertia::render('settings/password', ['status' => session('status')]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $this->service->changePassword($request->user(), $request->validated('password'));

        return back()->with('status', 'Password updated.');
    }
}
