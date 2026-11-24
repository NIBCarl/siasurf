<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingEvent extends Model
{
    use HasFactory;

    public const TYPE_CREATED = 'booking_created';
    public const TYPE_CONFIRMED = 'booking_confirmed';
    public const TYPE_PAYMENT_COMPLETED = 'payment_completed';
    public const TYPE_RESCHEDULED = 'booking_rescheduled';
    public const TYPE_CANCELLED = 'booking_cancelled';
    public const TYPE_COMPLETED = 'session_completed';
    public const TYPE_ATTENDANCE_MARKED = 'attendance_marked';

    protected $fillable = [
        'booking_id',
        'actor_id',
        'event_type',
        'title',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Event types highlighted in the admin bookings manifest.
     *
     * @return array<int, string>
     */
    public static function manifestTypes(): array
    {
        return [
            self::TYPE_CREATED,
            self::TYPE_CONFIRMED,
            self::TYPE_PAYMENT_COMPLETED,
            self::TYPE_RESCHEDULED,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
