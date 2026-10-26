<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'instructor_id',
        'latitude',
        'longitude',
        'accuracy_m',
        'speed_mps',
        'heading',
        'distance_from_spot_m',
        'recorded_at',
        'source',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy_m' => 'decimal:2',
        'speed_mps' => 'decimal:2',
        'heading' => 'decimal:2',
        'distance_from_spot_m' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * A ping older than this is treated as stale and must not be rendered as
     * "live" — the instructor has closed the app or lost signal.
     */
    public function isFresh(int $seconds = 120): bool
    {
        return $this->recorded_at !== null
            && $this->recorded_at->greaterThan(now()->subSeconds($seconds));
    }

    /**
     * Seconds since this ping was recorded.
     */
    public function ageInSeconds(): int
    {
        return $this->recorded_at === null
            ? PHP_INT_MAX
            : max(0, (int) $this->recorded_at->diffInSeconds(now(), false));
    }

    public function toMapPayload(): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'instructor_id' => $this->instructor_id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'accuracy_m' => $this->accuracy_m !== null ? (float) $this->accuracy_m : null,
            'distance_from_spot_m' => $this->distance_from_spot_m !== null
                ? (float) $this->distance_from_spot_m
                : null,
            'recorded_at' => optional($this->recorded_at)->toIso8601String(),
            'age_seconds' => $this->ageInSeconds(),
            'is_fresh' => $this->isFresh(),
        ];
    }
}
