<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrendExampleRequest extends FormRequest
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
            'trend_id' => ['required', 'integer', 'exists:trends,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'author_handle' => ['nullable', 'string', 'max:255'],
            'author_avatar' => ['nullable', 'string', 'url', 'max:255'],
            'content_type' => ['nullable', 'string', 'in:video,image,carousel,reel'],
            'media_url' => ['nullable', 'string', 'url', 'max:255'],
            'platform_id' => ['nullable', 'integer', 'exists:platforms,id'],
            'post_url' => ['nullable', 'string', 'url', 'max:255'],
            'post_external_id' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}
