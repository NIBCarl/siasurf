<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\InstructorStatus;
use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Enums\SkillLevel;

class InstructorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'area',
        'meeting_point',
        'latitude',
        'longitude',
        'level',
        'status',
        'rate_per_hour',
        'guiding_rate',
        'coaching_rate',
        'strike_count',
        'qr_code_path',
        'suspended_until',
        'suspension_reason',
        'suspended_at',
        'auto_accept_bookings',
    ];

    protected $casts = [
        'level' => InstructorLevel::class,
        'status' => InstructorStatus::class,
        'rate_per_hour' => 'decimal:2',
        'guiding_rate' => 'decimal:2',
        'coaching_rate' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'strike_count' => 'integer',
        'suspended_until' => 'datetime',
        'suspended_at' => 'datetime',
        'auto_accept_bookings' => 'boolean',
    ];

    /**
     * Instant Book: this instructor's calendar confirms requests immediately, so
     * the student skips the 2-hour acceptance wait entirely.
     */
    public function usesInstantBook(): bool
    {
        return $this->auto_accept_bookings === true;
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'instructor_id', 'user_id');
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(InstructorSuspension::class, 'instructor_id', 'user_id');
    }

    public function strikes(): HasMany
    {
        return $this->hasMany(InstructorStrike::class, 'instructor_id', 'user_id');
    }

    public function activeSuspension()
    {
        return $this->hasOne(InstructorSuspension::class, 'instructor_id', 'user_id')
            ->where('status', \App\Enums\SuspensionStatus::Active->value)
            ->latestOfMany();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', InstructorStatus::Active);
    }

    public function scopeVerified($query)
    {
        return $query->whereIn('status', [InstructorStatus::Active, InstructorStatus::Inactive]);
    }

    public function scopeVisible($query)
    {
        return $query->where('status', InstructorStatus::Active)
                     ->where(function ($q) {
                         $q->whereNull('suspended_until')
                           ->orWhere('suspended_until', '<=', now());
                     });
    }

    public function scopeByLevel($query, InstructorLevel $level)
    {
        return $query->where('level', $level);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->status === InstructorStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === InstructorStatus::Suspended;
    }

    public function shouldBeVisible(): bool
    {
        return $this->status === InstructorStatus::Active &&
               ($this->suspended_until === null || $this->suspended_until <= now());
    }

    public function hasActiveSuspension(): bool
    {
        return $this->suspensions()->active()->exists();
    }

    public function maxStudents(): int
    {
        return $this->level->maxStudents();
    }

    public function maxStudentsFor(SkillLevel $skill): int
    {
        return $this->level->maxStudentsFor($skill);
    }

    /**
     * Resolve this instructor's active hourly rate for a given service.
     * Falls back to the service's published floor when not yet explicitly set.
     */
    public function rateForService(SessionType $service): float
    {
        $raw = match ($service) {
            SessionType::Teaching => $this->rate_per_hour,
            SessionType::Guiding => $this->guiding_rate,
            SessionType::Coaching => $this->coaching_rate,
        };

        return $raw !== null && (float) $raw > 0
            ? (float) $raw
            : $service->defaultRate();
    }

    /**
     * Authorized services and active hourly rates for this instructor's level.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serviceRates(): array
    {
        return array_map(fn (SessionType $service) => [
            'type' => $service->value,
            'label' => $service->label(),
            'description' => $service->description(),
            'rate' => $this->rateForService($service),
            'min_rate' => $service->minRate(),
            'max_rate' => $service->maxRate(),
            'range_label' => $service->rateRangeLabel(),
        ], $this->level->allowedServices());
    }

    public function canTeachSkillLevel(string $skillLevel): bool
    {
        return in_array($skillLevel, $this->level->allowedSkillLevels());
    }

    public function addStrike(int $strikes = 1): void
    {
        $this->increment('strike_count', $strikes);
        
        if ($this->strike_count >= 3) {
            $this->update([
                'status' => InstructorStatus::Suspended,
                'suspended_until' => now()->addMonth(),
            ]);
        }
    }
}
