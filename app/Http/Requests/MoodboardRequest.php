<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoodboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'trend_id' => ['nullable', 'integer', 'exists:trends,id'],
            'inputs' => ['nullable', 'array'],
            'output' => ['nullable', 'array'],
        ];
    }
}
