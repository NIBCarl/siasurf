<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\DayOfWeek;
use App\Enums\TimePeriod;

class Availability extends Model
{
    use HasFactory;

    protected $table = 'availabilities';

    protected $fillable = [
        'user_id',
        'day_of_week',
        'time_period',
        'start_time',
        'end_time',
        'open_slots',
        'slot_duration_minutes',
        'is_available',
        'specific_date',
        'blocked_by_booking_id',
    ];

    protected $casts = [
        'day_of_week' => DayOfWeek::class,
        'time_period' => TimePeriod::class,
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'open_slots' => 'array',
        'slot_duration_minutes' => 'integer',
        'is_available' => 'boolean',
        'specific_date' => 'date',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockedByBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'blocked_by_booking_id');
    }

    /**
     * A block the instructor created themselves (holiday, personal time) as
     * opposed to one the system created because a booking claimed the slot.
     */
    public function scopeManualBlock($query)
    {
        return $query->where('is_available', false)->whereNull('blocked_by_booking_id');
    }

    /**
     * A slot locked automatically because a confirmed booking occupies it.
     */
    public function scopeBookingBlock($query)
    {
        return $query->whereNotNull('blocked_by_booking_id');
    }

    public function isBookingBlock(): bool
    {
        return $this->blocked_by_booking_id !== null;
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeUnavailable($query)
    {
        return $query->where('is_available', false);
    }

    public function scopeForDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeForTimePeriod($query, TimePeriod $timePeriod)
    {
        return $query->where('time_period', $timePeriod);
    }

    public function scopeForStartTime($query, string $startTime)
    {
        return $query->where('start_time', '<=', $startTime)
                     ->where('end_time', '>', $startTime);
    }

    public function scopeIsAvailableAt($query, string $time)
    {
        return $query->where('start_time', '<=', $time)
                     ->where('end_time', '>', $time);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('specific_date', $date);
    }

    public function scopeRecurring($query)
    {
        return $query->whereNull('specific_date');
    }

    public function scopeOneTime($query)
    {
        return $query->whereNotNull('specific_date');
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('user_id', $instructorId);
    }
}
