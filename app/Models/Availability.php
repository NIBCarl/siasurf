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
        'is_available',
        'specific_date',
    ];

    protected $casts = [
        'day_of_week' => DayOfWeek::class,
        'time_period' => TimePeriod::class,
        'is_available' => 'boolean',
        'specific_date' => 'date',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
