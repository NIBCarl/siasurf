<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'amount',
        'payment_method',
        'transaction_id',
        'checkout_url',
        'status',
        'paid_at',
        'refunded_at',
        'notes',
        'receipt_path',
        'receipt_reference',
        'receipt_uploaded_at',
        'receipt_verified_at',
        'receipt_verified_by',
        'receipt_note',
    ];

    protected $casts = [
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'receipt_uploaded_at' => 'datetime',
        'receipt_verified_at' => 'datetime',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The instructor or SISA member who approved the GCash receipt.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_verified_by');
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', PaymentStatus::Completed);
    }

    public function scopePending($query)
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', PaymentStatus::Failed);
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', PaymentStatus::Refunded);
    }

    public function scopeByMethod($query, PaymentMethod $method)
    {
        return $query->where('payment_method', $method);
    }

    // Helper methods
    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isDigital(): bool
    {
        return $this->payment_method->isDigital();
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->update([
            'status' => PaymentStatus::Failed,
            'notes' => $reason,
        ]);
    }

    public function refund(): void
    {
        $this->update([
            'status' => PaymentStatus::Refunded,
            'refunded_at' => now(),
        ]);
    }

    /**
     * Whether a GCash proof-of-payment image has been uploaded.
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null && $this->receipt_path !== '';
    }

    /**
     * Whether the uploaded receipt is still waiting for a human to approve it.
     */
    public function isAwaitingVerification(): bool
    {
        return $this->hasReceipt() && $this->status === PaymentStatus::PendingVerification;
    }

    /**
     * Whether the receipt has been approved (or the payment settled another
     * way, e.g. a PayMongo webhook).
     */
    public function isVerified(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    /**
     * Mark a receipt as verified and the payment as settled.
     */
    public function markReceiptVerified(?int $userId = null): void
    {
        $this->update([
            'status' => PaymentStatus::Completed,
            'paid_at' => $this->paid_at ?? now(),
            'receipt_verified_at' => now(),
            'receipt_verified_by' => $userId,
            'receipt_note' => null,
        ]);
    }

    /**
     * Reject the uploaded receipt. The image is kept for the audit trail so the
     * student can replace it with a corrected one.
     */
    public function rejectReceipt(string $note, ?int $userId = null): void
    {
        $this->update([
            'status' => PaymentStatus::Failed,
            'receipt_verified_at' => now(),
            'receipt_verified_by' => $userId,
            'receipt_note' => $note,
        ]);
    }
}
