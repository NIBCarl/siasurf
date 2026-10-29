<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Chat is rendered as text, never HTML. Strip tags before storing so a
        // pasted rich-text payload cannot smuggle markup into the database.
        $body = $this->input('body');

        $this->merge([
            'body' => is_string($body) ? trim(strip_tags($body)) : $body,
        ]);
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write a message before sending.',
            'body.max' => 'Messages cannot exceed 2,000 characters.',
        ];
    }
}
