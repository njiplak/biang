<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Soft-deleted staff still hold their address: reusing it would
                // make the audit trail ambiguous about which person acted.
                Rule::unique('admin_users', 'email')->ignore($staff),
            ],
            // Required on create, optional on edit - blank means "leave it".
            'password' => [
                $this->isMethod('POST') ? 'required' : 'nullable',
                'string', Password::defaults(),
            ],
            // Staff roles are runtime-editable (section 3), so this is a name
            // rather than an id and is validated against the admin guard only.
            'role' => [
                'nullable', 'string',
                Rule::exists('roles', 'name')->where('guard_name', 'admin'),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Named `payload`, not `attributes`: FormRequest::attributes() already
     * exists and supplies custom attribute NAMES for validation messages, so
     * overriding it here would quietly break every error message on this form.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'password' => $this->validated('password'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
