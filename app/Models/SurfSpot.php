<?php

namespace App\Models;

use App\Enums\SkillLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ];
    }
}
