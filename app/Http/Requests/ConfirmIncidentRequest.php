<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'severity' => ['required', 'string', Rule::enum(IncidentSeverity::class)],
            'category' => ['nullable', 'string', Rule::enum(FeedbackCategory::class)],
            'instructor_summary' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
