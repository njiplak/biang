<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PageRequest extends FormRequest
{
    /** Authorisation is the route's `permission:` middleware, not this. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * The slug IS the address a renderer looks a page up by, so it is
             * constrained rather than merely unique: anything outside
             * lowercase, digits and single hyphens either cannot appear in a
             * clean URL or arrives percent-encoded and stops matching the row
             * it was meant to find.
             */
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pages')->ignore($this->route('id')),
            ],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            // Publishing is a separate, deliberate act - see PageController.
            'published' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only use lowercase letters, numbers and single hyphens, for example privacy-policy.',
        ];
    }
}
