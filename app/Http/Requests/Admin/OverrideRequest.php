<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Section 10: "Override a limit for one specific customer." */
class OverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feature_id' => ['required', 'exists:features,id'],
            // Null is unlimited, which is why `present` rather than `required` -
            // an explicit null has to survive validation.
            'value' => ['present', 'nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
