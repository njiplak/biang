<?php

namespace App\Http\Requests\Admin;

use App\Enums\AddonKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Section 4's three add-on kinds: quantity, paid unlock, and metered. */
class AddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $addon = $this->route('addon');

        return [
            'key' => [
                Rule::requiredIf($addon === null),
                'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('addons', 'key')->ignore($addon),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', new Enum(AddonKind::class)],
            // An add-on grants a feature exactly as a plan does, which is what
            // keeps entitlement resolution on one code path.
            'feature_id' => ['nullable', 'integer', 'exists:features,id'],
            'grant_per_unit' => ['nullable', 'integer', 'min:1'],
            'max_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
