<?php

namespace App\Models;

use App\Enums\SkillLevel;
use App\Enums\WaiverType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingParticipant extends Model
{
    use HasFactory;

    public const ROLE_PRIMARY = 'primary';
    public const ROLE_COMPANION = 'companion';

    protected $fillable = [
        'booking_id',
        'role',
        'full_name',
        'age',
        'height',
        'weight',
        'skill_level',
        'has_board',
        'emergency_contact_name',
        'emergency_contact_phone',
        'medical_info',
        'guardian_name',
        'guardian_phone',
    ];

    protected $casts = [
        'age' => 'integer',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'skill_level' => SkillLevel::class,
        'has_board' => 'boolean',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class, 'participant_id');
    }

    public function isPrimary(): bool
    {
        return $this->role === self::ROLE_PRIMARY;
    }

    public function isMinor(): bool
    {
        return $this->age !== null && $this->age < 18;
    }

    /**
     * Whether this participant has signed a given waiver type.
     *
     * Legacy waivers were stored without a participant_id. Treat one of those
     * as covering the primary participant so older bookings still validate.
     */
    public function hasSigned(WaiverType $type): bool
    {
        return $this->waivers()
            ->where('type', $type->value)
            ->where(function ($query) {
                $query->where('participant_id', $this->id);

                if ($this->isPrimary()) {
                    $query->orWhereNull('participant_id');
                }
            })
            ->exists();
    }

    public function requiresParentalConsent(): bool
    {
        return $this->isMinor();
    }

    /**
     * Surfboard size recommendation based on height/weight, mirroring the
     * booking-level helper so the instructor sees one per participant.
     */
    public function getRecommendedBoardSize(): ?string
    {
        if ($this->height === null || $this->weight === null) {
            return null;
        }

        $height = (float) $this->height;
        $weight = (float) $this->weight;

        $length = match (true) {
            $weight < 50 => 7.5,
            $weight < 65 => 7.8,
            $weight < 80 => 8.0,
            $weight < 95 => 8.2,
            default => 8.5,
        };

        if ($height > 180) {
            $length += 0.2;
        } elseif ($height < 160) {
            $length -= 0.2;
        }

        return sprintf('%.1f ft', $length);
    }
}
