<?php

namespace App\Models;

use App\Enums\SkillLevel;
use App\Services\LocationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurfSpot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'area',
        'region',
        'description',
        'difficulty',
        'break_type',
        'best_tide',
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

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helper methods
    public function isSafeFor(string $skillLevel): bool
    {
        $difficulties = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
        $spotLevel = $difficulties[$this->difficulty] ?? 3;
        $userLevel = $difficulties[$skillLevel] ?? 3;

        return $userLevel >= $spotLevel;
    }

    public function isBeginnerFriendly(): bool
    {
        return $this->difficulty === SkillLevel::Beginner->value;
    }

    /**
     * Human label used across the UI, e.g. "Cloud 9 — General Luna".
     */
    public function displayLabel(): string
    {
        return $this->area ? "{$this->name} — {$this->area}" : $this->name;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Great-circle distance in metres between this spot and a point.
     * Returns null when the spot has no coordinates.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        return LocationService::haversineMeters(
            (float) $this->latitude,
            (float) $this->longitude,
            $latitude,
            $longitude
        );
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
            'region' => $this->region,
            'label' => $this->displayLabel(),
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'break_type' => $this->break_type,
            'best_tide' => $this->best_tide,
            'hazard_notes' => $this->hazard_notes,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'is_active' => $this->is_active,
            'is_safe_zone' => $this->is_safe_zone,
        ];
    }
}
