<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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
        'birthday',
        'area',
        'meeting_point',
        'latitude',
        'longitude',
        'level',
        'offered_services',
        'status',
        'rate_per_hour',
        'guiding_rate',
        'coaching_rate',
        'strike_count',
        'qr_code_path',
        'suspended_until',
        'suspension_reason',
        'suspended_at',
        'rejection_reason',
        'rejected_at',
        'auto_accept_bookings',
    ];

    protected $casts = [
        'level' => InstructorLevel::class,
        'birthday' => 'date:Y-m-d',
        'offered_services' => 'array',
        'status' => InstructorStatus::class,
        'rate_per_hour' => 'decimal:2',
        'guiding_rate' => 'decimal:2',
        'coaching_rate' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'strike_count' => 'integer',
        'suspended_until' => 'datetime',
        'suspended_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function fines(): HasMany
    {
        return $this->hasMany(InstructorFine::class, 'instructor_id', 'user_id');
    }

    public function activeSuspension()
    {
        return $this->hasOne(InstructorSuspension::class, 'instructor_id', 'user_id')
            ->enforced()
            ->latestOfMany();
    }

    public function surfSpots(): BelongsToMany
    {
        return $this->belongsToMany(SurfSpot::class, 'instructor_surf_spots')
            ->withTimestamps();
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
        return $this->suspensions()->enforced()->exists();
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
     * Lesson types this instructor actively offers, intersected with their approved level.
     * When `offered_services` is null (legacy profiles), all level-allowed services apply.
     *
     * @return array<int, SessionType>
     */
    public function offeredServices(): array
    {
        $allowed = $this->level->allowedServices();

        if ($this->offered_services === null) {
            return $allowed;
        }

        return array_values(array_filter(
            $allowed,
            fn (SessionType $service) => in_array($service->value, $this->offered_services, true)
        ));
    }

    /**
     * @return array<int, string>
     */
    public function offeredServiceValues(): array
    {
        return array_map(fn (SessionType $service) => $service->value, $this->offeredServices());
    }

    public function offersService(SessionType $service): bool
    {
        return in_array($service, $this->offeredServices(), true);
    }

    /**
     * Lesson types this instructor offers that a student of `$skill` can book.
     *
     * @return array<int, SessionType>
     */
    public function serviceOptionsFor(SkillLevel $skill): array
    {
        return array_values(array_filter(
            $this->offeredServices(),
            fn (SessionType $service) => $service->canBeBookedBy($skill)
        ));
    }

    public function recommendedServiceFor(SkillLevel $skill): ?SessionType
    {
        $options = $this->serviceOptionsFor($skill);

        if ($options === []) {
            return null;
        }

        $natural = SessionType::recommendedFor($skill, $this->level->value);

        return in_array($natural, $options, true) ? $natural : $options[0];
    }

    /**
     * Authorized and actively offered services with their hourly rates.
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
        ], $this->offeredServices());
    }

    public function startingRate(): float
    {
        $rates = array_column($this->serviceRates(), 'rate');

        if ($rates !== []) {
            return (float) min($rates);
        }

        return $this->rate_per_hour !== null
            ? (float) $this->rate_per_hour
            : SessionType::Teaching->defaultRate();
    }

    /**
     * Whether this instructor has explicitly selected surf zones on their profile.
     * Legacy instructors with none selected remain bookable at level-permitted spots.
     */
    public function hasConfiguredSurfSpots(): bool
    {
        if ($this->relationLoaded('surfSpots')) {
            return $this->surfSpots->isNotEmpty();
        }

        return $this->surfSpots()->exists();
    }

    /**
     * Active surf spots selected by this instructor that remain permitted by their current level.
     *
     * @return Collection<int, SurfSpot>
     */
    public function activeAllowedSurfSpots(): Collection
    {
        $allowedDifficulties = $this->level->allowedSurfSpotDifficulties();
        $rank = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];

        if ($this->relationLoaded('surfSpots')) {
            return $this->surfSpots
                ->filter(fn (SurfSpot $spot) => $spot->is_active && in_array($spot->difficulty, $allowedDifficulties, true))
                ->sortBy(fn (SurfSpot $spot) => sprintf('%d-%s', $rank[$spot->difficulty] ?? 9, strtolower($spot->name)))
                ->values();
        }

        return $this->surfSpots()
            ->active()
            ->whereIn('difficulty', $allowedDifficulties)
            ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();
    }

    /**
     * Surf spots a student with `$studentSkill` may book with this instructor.
     *
     * @return Collection<int, SurfSpot>
     */
    public function bookableSurfSpotsFor(SkillLevel $studentSkill): Collection
    {
        $allowedDifficulties = array_values(array_intersect(
            $this->level->allowedSurfSpotDifficulties(),
            $studentSkill->allowedSurfSpotDifficulties()
        ));

        if ($allowedDifficulties === []) {
            return collect();
        }

        if ($this->hasConfiguredSurfSpots()) {
            $rank = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];

            if ($this->relationLoaded('surfSpots')) {
                return $this->surfSpots
                    ->filter(fn (SurfSpot $spot) => $spot->is_active && in_array($spot->difficulty, $allowedDifficulties, true))
                    ->sortBy(fn (SurfSpot $spot) => sprintf('%d-%s', $rank[$spot->difficulty] ?? 9, strtolower($spot->name)))
                    ->values();
            }

            return $this->surfSpots()
                ->active()
                ->whereIn('difficulty', $allowedDifficulties)
                ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
                ->orderBy('name')
                ->get();
        }

        return SurfSpot::query()
            ->active()
            ->whereIn('difficulty', $allowedDifficulties)
            ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();
    }

    public function teachesAtSurfSpot(SurfSpot $spot): bool
    {
        if (! $spot->is_active || ! $this->level->canTeachAtDifficulty((string) $spot->difficulty)) {
            return false;
        }

        if (! $this->hasConfiguredSurfSpots()) {
            return true;
        }

        if ($this->relationLoaded('surfSpots')) {
            return $this->surfSpots->contains('id', $spot->id);
        }

        return $this->surfSpots()->where('surf_spots.id', $spot->id)->exists();
    }

    /**
     * Persist selected surf spots and keep directory area/coordinates aligned when needed.
     *
     * @param  array<int, int|string>  $spotIds
     */
    public function syncSurfSpotsAndLocation(array $spotIds, bool $syncCoordinates = true, ?string $submittedArea = null): void
    {
        $normalizedIds = array_values(array_unique(array_map('intval', $spotIds)));

        $this->surfSpots()->sync($normalizedIds);
        $this->unsetRelation('surfSpots');

        if ($normalizedIds === []) {
            return;
        }

        $primarySpot = SurfSpot::query()
            ->whereIn('id', $normalizedIds)
            ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->first();

        if (! $primarySpot) {
            return;
        }

        $updates = [];

        if (blank($submittedArea) && ($syncCoordinates || blank($this->area)) && $primarySpot->area) {
            $updates['area'] = $primarySpot->area;
        }

        if ($syncCoordinates && $primarySpot->hasCoordinates()) {
            $updates['latitude'] = $primarySpot->latitude;
            $updates['longitude'] = $primarySpot->longitude;
        }

        if ($updates !== []) {
            $this->update($updates);
        }
    }

    /**
     * Remove surf spots and lesson types/rates that are no longer allowed after an admin level change.
     */
    public function pruneForCurrentLevel(): void
    {
        $allowedDifficulties = $this->level->allowedSurfSpotDifficulties();
        $disallowedSpotIds = $this->surfSpots()
            ->whereNotIn('difficulty', $allowedDifficulties)
            ->pluck('surf_spots.id')
            ->all();

        if ($disallowedSpotIds !== []) {
            $this->surfSpots()->detach($disallowedSpotIds);
            $this->unsetRelation('surfSpots');
        }

        $allowedServiceValues = $this->level->allowedServiceValues();
        $updates = [];

        if ($this->offered_services !== null) {
            $prunedServices = array_values(array_intersect($this->offered_services, $allowedServiceValues));
            if ($prunedServices === []) {
                $prunedServices = [SessionType::Teaching->value];
            }
            $updates['offered_services'] = $prunedServices;
        }

        foreach (SessionType::cases() as $service) {
            $col = $service->profileColumn();
            if (! $service->canBeDeliveredBy($this->level->value)) {
                $updates[$col] = null;
            } elseif (
                $updates !== []
                && isset($updates['offered_services'])
                && in_array($service->value, $updates['offered_services'], true)
                && $this->{$col} === null
            ) {
                $updates[$col] = $service->defaultRate();
            }
        }

        if ($updates !== []) {
            $this->update($updates);
        }
    }

    public function canTeachSkillLevel(string $skillLevel): bool
    {
        return in_array($skillLevel, $this->level->allowedSkillLevels());
    }
}
