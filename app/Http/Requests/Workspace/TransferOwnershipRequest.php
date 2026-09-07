<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Scoped to this workspace: an id from somewhere else must not
            // resolve, regardless of what the policy would then say.
            'member_id' => [
                'required',
                Rule::exists('workspace_members', 'id')
                    ->where('workspace_id', $this->route('workspace')?->id),
            ],
        ];
    }
}
