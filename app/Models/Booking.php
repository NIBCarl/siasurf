<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_id',
        'instructor_id',
        'surf_spot_id',
        'date',
        'time_period',
        'start_time',
        'end_time',
        'duration_hours',
        'skill_level',
        'student_age',
        'student_count',
        'status',
        'total_amount',
        'payment_status',
        'notes',
        'has_board',
        'started_at',
        'cancelled_at',
        'completed_at',
        'height',
        'weight',
    ];

    protected $casts = [
        'date' => 'date',
        'time_period' => TimePeriod::class,
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'skill_level' => SkillLevel::class,
        'status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'has_board' => 'boolean',
        'duration_hours' => 'integer',
        'total_amount' => 'decimal:2',
        'student_age' => 'integer',
        'student_count' => 'integer',
        'started_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    // Relationships
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function surfSpot(): BelongsTo
    {
        return $this->belongsTo(SurfSpot::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function waiver(): HasOne
    {
        return $this->hasOne(Waiver::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function safetyIncident(): HasOne
    {
        return $this->hasOne(SafetyIncident::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', BookingStatus::Pending);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', BookingStatus::Confirmed);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', BookingStatus::Completed);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', BookingStatus::Cancelled);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->format('Y-m-d'))
                     ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]);
    }

    public function scopePast($query)
    {
        return $query->where('date', '<', now()->format('Y-m-d'));
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeOnDate($query, $date)
    {
        return $query->where('date', $date);
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === BookingStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function isCompleted(): bool
    {
        return $this->status === BookingStatus::Completed;
    }

    public function isInProgress(): bool
    {
        return $this->status === BookingStatus::InProgress;
    }

    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Completed;
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() || $this->isConfirmed();
    }

    public function confirm(): void
    {
        $this->update(['status' => BookingStatus::Confirmed]);
    }

    public function complete(): void
    {
        $this->update([
            'status' => BookingStatus::Completed,
            'completed_at' => now(),
        ]);

        // Dispatch review request job to be sent after 2 hours
        \App\Jobs\SendReviewRequestJob::dispatch($this)
            ->delay(now()->addHours(2));
    }

    public function cancel(string $reason = null): void
    {
        $this->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'notes' => $reason ? $this->notes . "\n\nCancellation reason: " . $reason : $this->notes,
        ]);
    }

    public function startSession(): void
    {
        $this->update([
            'status' => BookingStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function getSessionTimeRemaining(): int
    {
        if (!$this->isInProgress() || !$this->started_at) {
            return 0;
        }

        $endTime = $this->started_at->addHours($this->duration_hours);
        $remaining = now()->diffInSeconds($endTime, false);

        return max(0, (int) $remaining);
    }
}
