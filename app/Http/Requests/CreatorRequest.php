<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatorRequest extends FormRequest
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
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'handle' => [
                'required',
                'string',
                'max:255',
                Rule::unique('creators')->where('platform_id', $this->platform_id)->ignore($this->route('id')),
            ],
            'display_name' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'profile_url' => ['nullable', 'string', 'url', 'max:255'],
            'profile_image' => ['nullable', 'string', 'max:255'],
            'follower_count' => ['nullable', 'integer', 'min:0'],
            'themes' => ['nullable', 'array'],
            'languages' => ['nullable', 'array'],
            'last_refreshed_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
