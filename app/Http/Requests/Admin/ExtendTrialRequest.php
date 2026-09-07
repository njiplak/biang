<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Section 10: "Extend a trial." */
class ExtendTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Bounded on purpose. An unbounded extension is a comp, and a comp
            // goes through grantPlan so it is recorded as one.
            'days' => ['required', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
