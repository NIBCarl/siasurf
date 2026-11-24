<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'skill_level',
        'skill_assessed_at',
        'height',
        'weight',
        'has_board',
        'emergency_contact_name',
        'emergency_contact_phone',
        'medical_info',
        'is_first_time',
        'birthday',
        'country',
        'status',
        'onboarding_completed_at',
        'onboarding_skipped_at',
    ];

    protected $casts = [
        'is_first_time' => 'boolean',
        'birthday' => 'date:Y-m-d',
        'has_board' => 'boolean',
        'skill_assessed_at' => 'datetime',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'onboarding_completed_at' => 'datetime',
        'onboarding_skipped_at' => 'datetime',
    ];

    protected $appends = [
        'age',
    ];

    /**
     * Age right now, derived from the stored date of birth.
     *
     * Deliberately not a stored column: the 5-12 rule in SafetyService compares
     * against a live age, and a stored one would be wrong from the next birthday
     * onward. A minor booking must not slip through because a row was written
     * eleven months ago.
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birthday?->age;
    }

    /**
     * Whether the student has filled in enough for the booking form to prefill.
     *
     * Only the fields the booking form actually requires: date of birth, and
     * height/weight unless they bring their own board.
     */
    public function hasBookingDetails(): bool
    {
        if ($this->birthday === null) {
            return false;
        }

        if ($this->has_board) {
            return true;
        }

        return $this->height !== null && $this->weight !== null;
    }

    /**
     * The prompt is shown until the student either completes or skips it.
     */
    public function needsOnboarding(): bool
    {
        return $this->onboarding_completed_at === null
            && $this->onboarding_skipped_at === null;
    }

    public function markOnboarded(): void
    {
        $this->forceFill([
            'onboarding_completed_at' => now(),
            'onboarding_skipped_at' => null,
        ])->save();
    }

    public function markOnboardingSkipped(): void
    {
        $this->forceFill([
            'onboarding_skipped_at' => now(),
        ])->save();
    }

    /**
     * What the booking form should prefill with.
     *
     * Returns only values the student has actually supplied, so the form's own
     * defaults still apply to anything missing.
     *
     * @return array<string, mixed>
     */
    public function bookingPrefill(): array
    {
        return array_filter([
            'student_age' => $this->age,
            'height' => $this->height !== null ? (float) $this->height : null,
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'has_board' => $this->has_board,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'medical_info' => $this->medical_info,
        ], fn ($value) => $value !== null);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
