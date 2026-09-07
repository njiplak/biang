<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(['all', 'plan', 'state'])],
            'plan_codes' => ['array'],
            'plan_codes.*' => ['string', 'exists:plans,code'],
            'states' => ['array'],
            'states.*' => ['string'],
            'severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'is_dismissible' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Targeting collapses into the jsonb column here rather than in the
     * controller, so the only shape the service ever sees is the stored one.
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $audience = $validated['audience'];

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $audience,
            'audience_filter' => match ($audience) {
                'plan' => ['plan_codes' => $validated['plan_codes'] ?? []],
                'state' => ['states' => $validated['states'] ?? []],
                default => null,
            },
            'severity' => $validated['severity'],
            'is_dismissible' => $validated['is_dismissible'] ?? true,
            'expires_at' => $validated['expires_at'] ?? null,
        ];
    }
}
