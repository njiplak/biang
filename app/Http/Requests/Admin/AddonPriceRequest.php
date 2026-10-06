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
            // An add-on is charged on the subscription it hangs off, and a
            // lifetime plan has no subscription for it to hang off.
            'billing_interval' => ['required', (new Enum(BillingInterval::class))->except([BillingInterval::Lifetime])],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => ['required', 'integer', 'min:0'],
        ];
    }
}
