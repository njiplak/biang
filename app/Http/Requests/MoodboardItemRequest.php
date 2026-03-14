<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoodboardItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'moodboard_id' => ['required', 'integer', 'exists:moodboards,id'],
            'type' => ['required', 'string', 'in:ai_text,ai_image,trend_reference'],
            'content' => ['nullable', 'string'],
            'media_url' => ['nullable', 'string', 'url'],
            'source_trend_example_id' => ['nullable', 'integer', 'exists:trend_examples,id'],
            'sort_order' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
