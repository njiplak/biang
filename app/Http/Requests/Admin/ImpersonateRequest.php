<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Section 10 and the impersonation_sessions migration agree: the reason is not
 * optional. An unexplained entry into a customer account is indistinguishable
 * from abuse once everyone has forgotten the ticket.
 */
class ImpersonateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'ticket_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
