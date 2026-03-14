<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrendRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'trend_type_id' => ['required', 'integer', 'exists:trend_types,id'],
            'trend_category_id' => ['nullable', 'integer', 'exists:trend_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'safety_flag' => ['nullable', 'string', 'in:unknown,safe,review,unsafe'],
            'raw_source' => ['nullable', 'array'],
            'first_seen_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'region' => ['nullable', 'string', 'max:10'],
            'rank' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'string', 'max:255'],
        ];
    }
}
