<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingChangeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'requested_by',
        'type',
        'status',
        'requires_admin_approval',
        'reason',
        'cancellation_reason',
        'original_date',
        'original_time_period',
        'original_start_time',
        'original_end_time',
        'new_date',
        'new_time_period',
        'new_start_time',
        'new_end_time',
        'student_notified_at',
        'student_agreed_at',
        'student_declined_at',
        'student_response_note',
        'reviewed_by',
        'reviewed_at',
        'admin_note',
        'applied_at',
    ];

    protected $casts = [
        'type' => ChangeRequestType::class,
        'status' => ChangeRequestStatus::class,
        'cancellation_reason' => \App\Enums\CancellationReason::class,
        'requires_admin_approval' => 'boolean',
        'original_date' => 'date:Y-m-d',
        'original_start_time' => 'datetime:H:i',
        'original_end_time' => 'datetime:H:i',
        'new_date' => 'date:Y-m-d',
        'new_start_time' => 'datetime:H:i',
        'new_end_time' => 'datetime:H:i',
        'student_notified_at' => 'datetime',
        'student_agreed_at' => 'datetime',
        'student_declined_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', ChangeRequestStatus::Pending);
    }

    public function scopeAwaitingStudent($query)
    {
        return $query->where('status', ChangeRequestStatus::AwaitingStudent);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            ChangeRequestStatus::Pending,
            ChangeRequestStatus::AwaitingStudent,
        ]);
    }

    public function scopeAwaitingAdmin($query)
    {
        return $query->pending()->where('requires_admin_approval', true);
    }

    public function scopeOfType($query, ChangeRequestType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeReschedule($query)
    {
        return $query->where('type', ChangeRequestType::Reschedule);
    }

    public function scopeCancellation($query)
    {
        return $query->where('type', ChangeRequestType::Cancellation);
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === ChangeRequestStatus::Pending;
    }

    public function isAwaitingStudent(): bool
    {
        return $this->status === ChangeRequestStatus::AwaitingStudent;
    }

    public function isOpen(): bool
    {
        return $this->status instanceof ChangeRequestStatus && $this->status->isOpen();
    }

    public function hasStudentAgreed(): bool
    {
        return $this->student_agreed_at !== null;
    }

    public function isReschedule(): bool
    {
        return $this->type === ChangeRequestType::Reschedule;
    }

    public function isCancellation(): bool
    {
        return $this->type === ChangeRequestType::Cancellation;
    }

    /**
     * Format a 12-hour time range or period label.
     */
    public function describeTimeRange(?\Carbon\Carbon $start, ?\Carbon\Carbon $end, ?string $period): string
    {
        if ($start && $end) {
            return $start->format('g:i A') . ' – ' . $end->format('g:i A');
        }

        if ($start) {
            return $start->format('g:i A');
        }

        return $period ? ucfirst($period) : '—';
    }

    /**
     * Whether the requested change moves the lesson to a new slot.
     */
    public function hasNewSlot(): bool
    {
        return $this->new_date !== null;
    }

    /**
     * Human readable description of the requested slot.
     */
    public function describeNewSlot(): string
    {
        if (!$this->hasNewSlot()) {
            return '—';
        }

        $slot = $this->new_date->format('M d, Y');

        if ($this->new_time_period) {
            $slot .= ' · ' . ucfirst($this->new_time_period);
        }

        if ($this->new_start_time) {
            $slot .= ' · ' . $this->new_start_time->format('H:i');
        }

        return $slot;
    }

    /**
     * Human readable description of the original slot.
     */
    public function describeOriginalSlot(): string
    {
        if (!$this->original_date) {
            return '—';
        }

        $slot = $this->original_date->format('M d, Y');

        if ($this->original_time_period) {
            $slot .= ' · ' . ucfirst($this->original_time_period);
        }

        if ($this->original_start_time) {
            $slot .= ' · ' . $this->original_start_time->format('H:i');
        }

        return $slot;
    }

    /**
     * Mark the request as resolved by an admin.
     */
    public function resolve(ChangeRequestStatus $status, ?int $reviewerId = null, ?string $note = null): void
    {
        $this->update([
            'status' => $status,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);
    }

    /**
     * Mark the request as applied to the booking.
     */
    public function markApplied(): void
    {
        $this->update(['applied_at' => now()]);
    }

    /**
     * Serialized proposal summary shared across student and instructor views.
     *
     * @return array<string, mixed>
     */
    public function toProposalPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'requires_admin_approval' => (bool) $this->requires_admin_approval,
            'reason' => $this->reason,
            'admin_note' => $this->admin_note,
            'original_date' => $this->original_date?->format('Y-m-d'),
            'original_time_period' => $this->original_time_period,
            'original_start_time' => $this->original_start_time?->format('H:i'),
            'original_end_time' => $this->original_end_time?->format('H:i'),
            'original_time_range' => $this->describeTimeRange(
                $this->original_start_time,
                $this->original_end_time,
                $this->original_time_period
            ),
            'new_date' => $this->new_date?->format('Y-m-d'),
            'new_time_period' => $this->new_time_period,
            'new_start_time' => $this->new_start_time?->format('H:i'),
            'new_end_time' => $this->new_end_time?->format('H:i'),
            'new_time_range' => $this->describeTimeRange(
                $this->new_start_time,
                $this->new_end_time,
                $this->new_time_period
            ),
            'student_notified_at' => $this->student_notified_at?->toIso8601String(),
            'student_agreed_at' => $this->student_agreed_at?->toIso8601String(),
            'student_declined_at' => $this->student_declined_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}