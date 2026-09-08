<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
                /*
                 * Also refuse an address somebody else is already waiting to
                 * confirm. Without this the second person is told everything is
                 * fine, gets a confirmation mail, and only discovers the address
                 * was taken when their link fails - by which point they have
                 * been carrying a pending change for a week.
                 */
                Rule::unique('users', 'pending_email')->ignore($this->user()->id),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}
