<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** The limits on a plan. A null value is unlimited, not "unset". */
class PlanFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limits' => ['present', 'array'],
            'limits.*.feature_id' => ['required', 'integer', 'exists:features,id'],
            // `present` so an explicit null survives - see App\Models\Feature.
            'limits.*.value' => ['present', 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<int, int|null> feature_id => limit */
    public function limits(): array
    {
        return collect($this->validated('limits'))
            ->mapWithKeys(fn (array $row) => [(int) $row['feature_id'] => $row['value']])
            ->all();
    }
}
