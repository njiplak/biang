<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Section 10: "Stop abuse. Suspend a workspace immediately."
 *
 * The reason is required, not optional: it is written to the workspace and is
 * the only record of why someone lost access.
 */
class SuspendWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
