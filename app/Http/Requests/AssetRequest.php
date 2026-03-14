<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetRequest extends FormRequest
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
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'creator_id' => ['nullable', 'integer', 'exists:creators,id'],
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'asset_type_id' => ['nullable', 'integer', 'exists:asset_types,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'post_url' => ['nullable', 'string', 'url', 'max:255'],
            'post_external_id' => ['nullable', 'string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'published_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
