<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('final_outcome') && $this->filled('severity')) {
            $mapped = match ($this->input('severity')) {
                'minor' => IncidentOutcome::OneStrike->value,
                'major' => IncidentOutcome::TwoStrikes->value,
                'critical' => IncidentOutcome::Critical->value,
                default => null,
            };

            if ($mapped !== null) {
                $this->merge(['final_outcome' => $mapped]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'final_outcome' => ['required', 'string', Rule::enum(IncidentOutcome::class)],
            'category' => ['nullable', 'string', Rule::enum(FeedbackCategory::class)],
            'decision_reason' => ['required', 'string', 'min:10', 'max:2000'],
            'admin_note' => ['required', 'string', 'min:5', 'max:2000'],
            'instructor_summary' => ['nullable', 'string', 'max:2000'],
            'place_manual_hold' => ['nullable', 'boolean'],
            'manual_hold_days' => ['nullable', 'required_if:place_manual_hold,true', 'integer', 'min:1', 'max:365'],
        ];
    }
}
