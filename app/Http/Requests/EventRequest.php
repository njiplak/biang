<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventRequest extends FormRequest
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
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'creator_id' => ['nullable', 'integer', 'exists:creators,id'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'metadata' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
