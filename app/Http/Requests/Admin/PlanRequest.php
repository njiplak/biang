<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            // Immutable after creation - the service drops it on update - but
            // still validated here so a create cannot collide.
            'code' => [
                Rule::requiredIf($plan === null),
                'string', 'max:64', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('plans', 'code')->ignore($plan),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:10000'],
        ];
    }
}
