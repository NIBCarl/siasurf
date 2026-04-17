<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\InstructorStatus;
use App\Enums\InstructorLevel;

class InstructorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'level',
        'status',
        'rate_per_hour',
        'strike_count',
        'qr_code_path',
        'suspended_until',
    ];

    protected $casts = [
        'level' => InstructorLevel::class,
        'status' => InstructorStatus::class,
        'rate_per_hour' => 'decimal:2',
        'strike_count' => 'integer',
        'suspended_until' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'instructor_id', 'user_id');
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

    public function maxStudents(): int
    {
        return $this->level->maxStudents();
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
