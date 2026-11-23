<?php

namespace App\Models;

use App\Enums\InstructorLevel;
use App\Enums\SkillLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SurfSpot extends Model
{
    use HasFactory;

    /**
     * Coast sides published by the Siargao surf-spot directory. The island has
     * no listed West breaks, so a West filter must render an empty state.
     */
    public const SIDES = [
        'north' => 'North',
        'east' => 'East',
        'south' => 'South',
        'west' => 'West',
    ];

    /**
     * Break zone: the soft inside of a reef versus the hollow outer line-up.
     * Both sections can share one line-up but carry different difficulties.
     */
    public const SECTIONS = [
        'inner' => 'Inner',
        'outer' => 'Outer',
    ];

    protected $fillable = [
        'name',
        'area',
        'barangay',
        'side',
        'section',
        'region',
        'description',
        'difficulty',
        'break_type',
        'best_tide',
        'best_season',
        'access',
        'plus_code',
        'hazard_notes',
        'latitude',
        'longitude',
        'is_active',
        'is_safe_zone',
        'sort_order',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
        'is_safe_zone' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function instructorProfiles(): BelongsToMany
    {
        return $this->belongsToMany(InstructorProfile::class, 'instructor_surf_spots')
            ->withTimestamps();
    }

    /**
     * Active surf zones maintained by SISA that an instructor at `$level` is permitted to select.
     */
    public static function activeForInstructorLevel(int|InstructorLevel $level): Builder
    {
        $levelEnum = $level instanceof InstructorLevel
            ? $level
            : (InstructorLevel::tryFrom($level) ?? InstructorLevel::Level1);

        return self::query()
            ->active()
            ->whereIn('difficulty', $levelEnum->allowedSurfSpotDifficulties())
            ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
            ->orderBy('name');
    }

    /**
     * Validation rules for selecting surf zones from SISA's registry within an instructor's approved level.
     *
     * @return array<string, mixed>
     */
    public static function instructorSelectionValidationRules(int $level, bool $requireWhenPresent = false): array
    {
        $levelEnum = InstructorLevel::tryFrom($level) ?? InstructorLevel::Level1;

        return [
            'surf_spot_ids' => [
                'sometimes',
                'nullable',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($levelEnum, $requireWhenPresent): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $ids = array_values(array_unique(array_map('intval', $value)));

                    if ($requireWhenPresent && $ids === [] && self::activeForInstructorLevel($levelEnum)->exists()) {
                        $fail('Select at least one surf zone from the map.');

                        return;
                    }

                    if ($ids === []) {
                        return;
                    }

                    $spots = self::query()
                        ->whereIn('id', $ids)
                        ->get(['id', 'name', 'difficulty', 'is_active']);

                    if ($spots->count() !== count($ids) || $spots->contains(fn (self $spot) => ! $spot->is_active)) {
                        $fail('One or more selected surf zones are inactive or unavailable.');

                        return;
                    }

                    foreach ($spots as $spot) {
                        if (! $levelEnum->canTeachAtDifficulty((string) $spot->difficulty)) {
                            $fail("{$spot->name} is a {$spot->difficulty} zone and cannot be selected by a {$levelEnum->shortLabel()} instructor. {$levelEnum->surfZoneRuleLabel()}");

                            return;
                        }
                    }
                },
            ],
            'surf_spot_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('surf_spots', 'id')->where('is_active', true),
            ],
        ];
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    public function scopeSafeForBeginners($query)
    {
        // FR 3.5 - beginners may only be taken to a break flagged as a safe zone.
        return $query->where('is_safe_zone', true)
            ->where('difficulty', SkillLevel::Beginner->value);
    }

    public function scopeInArea($query, string $area)
    {
        return $query->where('area', $area);
    }

    public function scopeOnSide($query, string $side)
    {
        return $query->where('side', $side);
    }

    public function scopeInSection($query, string $section)
    {
        return $query->where('section', $section);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helper methods
    public function isSafeFor(string $skillLevel): bool
    {
        // Single source of truth lives on SkillLevel so the booking form, the
        // SQL filter and the safety engine can never drift apart.
        $level = SkillLevel::tryFrom($skillLevel);

        return $level?->canSurfDifficulty($this->difficulty) ?? false;
    }

    public function isBeginnerFriendly(): bool
    {
        return $this->difficulty === SkillLevel::Beginner->value;
    }

    /**
     * Human label used across the UI, e.g. "Cloud 9 (Outer) — General Luna".
     */
    public function displayLabel(): string
    {
        return $this->area ? "{$this->name} — {$this->area}" : $this->name;
    }

    public function sideLabel(): ?string
    {
        return self::SIDES[$this->side] ?? null;
    }

    public function sectionLabel(): ?string
    {
        return self::SECTIONS[$this->section] ?? null;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Normalized location slug shared by paired Inner/Outer breaks when
     * looking up curated gallery imagery in config/surf_spot_images.php.
     */
    public function imageSlug(): string
    {
        $baseName = preg_replace('/\s*\((?:inner|outer)\)\s*$/i', '', (string) $this->name) ?? '';
        $normalized = str_replace(["'", '’'], '', $baseName);

        return Str::slug($normalized);
    }

    /**
     * Curated gallery photos for this surf spot location.
     *
     * @return array<int, array{url: string, caption: string, accuracy: string, width: int, height: int}>
     */
    public function images(): array
    {
        $slug = $this->imageSlug();

        if ($slug === '') {
            return [];
        }

        return array_values(config('surf_spot_images.'.$slug, []));
    }

    /**
     * Payload used by the Leaflet map components and the JSON API.
     */
    public function toMapPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'area' => $this->area,
            'barangay' => $this->barangay,
            'side' => $this->side,
            'side_label' => $this->sideLabel(),
            'section' => $this->section,
            'section_label' => $this->sectionLabel(),
            'region' => $this->region,
            'label' => $this->displayLabel(),
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'break_type' => $this->break_type,
            'best_tide' => $this->best_tide,
            'best_season' => $this->best_season,
            'access' => $this->access,
            'plus_code' => $this->plus_code,
            'hazard_notes' => $this->hazard_notes,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'is_active' => $this->is_active,
            'is_safe_zone' => $this->is_safe_zone,
            'images' => $this->images(),
        ];
    }
}
