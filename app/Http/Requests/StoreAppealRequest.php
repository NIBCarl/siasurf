<?php

namespace App\Http\Requests;

use App\Enums\AppealGround;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:50', 'max:3000'],
            'ground' => ['nullable', 'string', Rule::enum(AppealGround::class)],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'truth_declaration' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Please explain why the suspension should be reviewed.',
            'message.min' => 'Please provide at least 50 characters so SISA can evaluate the appeal.',
            'truth_declaration.accepted' => 'Please confirm that the appeal is truthful.',
        ];
    }
}
