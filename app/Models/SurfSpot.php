<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\SkillLevel;

class SurfSpot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'difficulty',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
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
        return $query->where('difficulty', SkillLevel::Beginner->value);
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
}
