<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
