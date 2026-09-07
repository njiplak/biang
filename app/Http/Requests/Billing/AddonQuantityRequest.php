<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddonQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'addon_id' => ['required', Rule::exists('addons', 'id')],
            // Zero removes the add-on entirely.
            'quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
