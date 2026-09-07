<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AddonPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_interval' => ['required', new Enum(BillingInterval::class)],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => ['required', 'integer', 'min:0'],
        ];
    }
}
