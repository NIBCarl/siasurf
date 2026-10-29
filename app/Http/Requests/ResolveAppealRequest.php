<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:10', 'max:2000'],
            'revoke_incident_ids' => ['nullable', 'array'],
            'revoke_incident_ids.*' => ['integer', 'exists:safety_incidents,id'],
        ];
    }
}
