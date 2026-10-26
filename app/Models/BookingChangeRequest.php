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
        'original_date',
        'original_time_period',
        'original_start_time',
        'original_end_time',
        'new_date',
        'new_time_period',
        'new_start_time',
        'new_end_time',
        'reviewed_by',
        'reviewed_at',
        'admin_note',
        'applied_at',
    ];

    protected $casts = [
        'type' => ChangeRequestType::class,
        'status' => ChangeRequestStatus::class,
        'requires_admin_approval' => 'boolean',
        'original_date' => 'date',
        'original_start_time' => 'datetime:H:i',
        'original_end_time' => 'datetime:H:i',
        'new_date' => 'date',
        'new_start_time' => 'datetime:H:i',
        'new_end_time' => 'datetime:H:i',
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

    public function isReschedule(): bool
    {
        return $this->type === ChangeRequestType::Reschedule;
    }

    public function isCancellation(): bool
    {
        return $this->type === ChangeRequestType::Cancellation;
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
}