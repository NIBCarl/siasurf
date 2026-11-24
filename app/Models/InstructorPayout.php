<?php

namespace App\Models;

use App\Enums\PayoutChannel;
use App\Enums\PayoutType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstructorPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_code',
        'instructor_id',
        'recorded_by',
        'settlement_type',
        'payment_channel',
        'period_start',
        'period_end',
        'ledger_days_count',
        'gcash_earnings_settled',
        'cash_fees_settled',
        'net_payout_amount',
        'receipt_reference',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'settlement_type' => PayoutType::class,
        'payment_channel' => PayoutChannel::class,
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'ledger_days_count' => 'integer',
        'gcash_earnings_settled' => 'decimal:2',
        'cash_fees_settled' => 'decimal:2',
        'net_payout_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'settlement_type_label',
        'payment_channel_label',
    ];

    /**
     * Payout records are immutable audit entries and cannot be deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \RuntimeException('Payout records are immutable and cannot be deleted.');
        });
    }

    public function getSettlementTypeLabelAttribute(): string
    {
        return $this->settlement_type?->label() ?? '';
    }

    public function getPaymentChannelLabelAttribute(): string
    {
        return $this->payment_channel?->label() ?? '';
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function dailyLedgers(): HasMany
    {
        return $this->hasMany(InstructorDailyLedger::class, 'payout_id');
    }
}
