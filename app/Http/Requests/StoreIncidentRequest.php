<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'instructor_id' => ['required', 'exists:users,id'],
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'category' => ['nullable', 'string', Rule::enum(FeedbackCategory::class)],
            'type' => ['required', 'string', Rule::enum(IncidentType::class)],
            'severity' => ['required', 'string', Rule::enum(IncidentSeverity::class)],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'instructor_summary' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
