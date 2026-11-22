<?php

namespace App\Models;

use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'payment_id',
        'initiated_by',
        'lesson_price',
        'service_fee_retained',
        'refund_percentage',
        'amount',
        'instructor_retained_amount',
        'policy_type',
        'basis',
        'days_before_session',
        'days_since_payment',
        'status',
        'gateway',
        'paymongo_refund_id',
        'paymongo_payment_id',
        'gateway_payload',
        'reason',
        'failure_reason',
        'requested_at',
        'processed_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'lesson_price' => 'decimal:2',
        'service_fee_retained' => 'decimal:2',
        'refund_percentage' => 'integer',
        'amount' => 'decimal:2',
        'instructor_retained_amount' => 'decimal:2',
        'days_before_session' => 'integer',
        'days_since_payment' => 'integer',
        'status' => RefundStatus::class,
        'basis' => RefundBasis::class,
        'gateway_payload' => 'array',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'basis_label',
    ];

    /**
     * BR-PAY-05: Financial refund records are immutable and cannot be deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \RuntimeException('Refund records are immutable and cannot be deleted.');
        });
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status?->label() ?? '';
    }

    public function getBasisLabelAttribute(): string
    {
        return $this->basis?->label() ?? '';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function scopePendingAction($query)
    {
        return $query->whereIn('status', [
            RefundStatus::Requested->value,
            RefundStatus::Processing->value,
            RefundStatus::ManualRequired->value,
            RefundStatus::Failed->value,
        ]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', RefundStatus::Completed->value);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', RefundStatus::Failed->value);
    }

    public function isCompleted(): bool
    {
        return $this->status === RefundStatus::Completed;
    }

    public function canRetry(): bool
    {
        return $this->status === RefundStatus::Failed && $this->gateway !== 'manual_gcash';
    }

    public function canCompleteManually(): bool
    {
        return in_array($this->status, [RefundStatus::ManualRequired, RefundStatus::Failed], true);
    }
}
