<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // An archived price is history - a subscription may still point at
            // one, but nobody may newly buy it (section 10).
            'plan_price_id' => [
                'required',
                Rule::exists('plan_prices', 'id')->whereNull('archived_at'),
            ],
        ];
    }
}
