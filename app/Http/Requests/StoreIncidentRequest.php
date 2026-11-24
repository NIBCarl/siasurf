<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentReportTopic;
use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
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
            'instructor_id' => ['required', 'exists:users,id'],
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'category' => ['nullable', 'string', Rule::enum(FeedbackCategory::class)],
            'report_type' => ['nullable', 'string', Rule::enum(IncidentReportTopic::class)],
            'type' => ['required', 'string', Rule::enum(IncidentType::class)],
            'final_outcome' => [
                'required',
                'string',
                Rule::in([
                    IncidentOutcome::OneStrike->value,
                    IncidentOutcome::TwoStrikes->value,
                    IncidentOutcome::ThreeStrikes->value,
                    IncidentOutcome::Critical->value,
                ]),
            ],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'decision_reason' => ['required', 'string', 'min:10', 'max:2000'],
            'admin_note' => ['required', 'string', 'min:5', 'max:2000'],
            'instructor_summary' => ['nullable', 'string', 'max:2000'],
            'place_manual_hold' => ['nullable', 'boolean'],
            'manual_hold_days' => ['nullable', 'required_if:place_manual_hold,true', 'integer', 'min:1', 'max:365'],
        ];
    }
}
