<?php

namespace App\Http\Requests\Auth;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            // Names the workspace created for them after verification. Optional:
            // left blank it is "<first name>'s workspace".
            'workspace_name' => ['nullable', 'string', 'max:255'],
            // An explicit tick, and only once there are published terms to agree to.
            'terms' => Page::legalVersion() === null ? ['nullable'] : ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}
