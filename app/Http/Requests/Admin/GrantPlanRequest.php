<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Section 10: "Grant a plan by hand. Sales cannot wait for a deploy." */
class GrantPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // An archived price is history: a live subscription may still point
            // at one, but nobody may newly be put on it.
            'plan_price_id' => [
                'required',
                Rule::exists('plan_prices', 'id')->whereNull('archived_at'),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
