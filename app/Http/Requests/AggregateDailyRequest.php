<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AggregateDailyRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'creator_id' => ['nullable', 'integer', 'exists:creators,id'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'metrics' => ['nullable', 'array'],
            'computed_at' => ['nullable', 'date'],
        ];
    }
}
