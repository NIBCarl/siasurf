<?php

namespace App\Models;

use App\Enums\LedgerSettlementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstructorDailyLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'ledger_date',
        'completed_bookings_count',
        'gcash_bookings_count',
        'cash_bookings_count',
        'gcash_earnings',
        'cash_earnings',
        'total_earnings',
        'platform_gcash_fees',
        'cash_service_fees_owed',
        'net_payable',
        'remittance_due_date',
        'settlement_status',
        'payout_id',
        'settled_at',
        'overdue_notified_at',
    ];

    protected $casts = [
        'ledger_date' => 'date:Y-m-d',
        'remittance_due_date' => 'date:Y-m-d',
        'completed_bookings_count' => 'integer',
        'gcash_bookings_count' => 'integer',
        'cash_bookings_count' => 'integer',
        'gcash_earnings' => 'decimal:2',
        'cash_earnings' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'platform_gcash_fees' => 'decimal:2',
        'cash_service_fees_owed' => 'decimal:2',
        'net_payable' => 'decimal:2',
        'settlement_status' => LedgerSettlementStatus::class,
        'settled_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    protected $appends = [
        'effective_status',
        'effective_status_label',
        'is_overdue',
        'days_until_remittance_due',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(InstructorPayout::class, 'payout_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'daily_ledger_id');
    }

    public function isSettled(): bool
    {
        return $this->payout_id !== null;
    }

    public function isOverdue(): bool
    {
        if ($this->payout_id !== null || (float) $this->cash_service_fees_owed <= 0 || $this->remittance_due_date === null) {
            return false;
        }

        $tz = config('app.timezone', 'Asia/Manila');

        return $this->remittance_due_date
            ->copy()
            ->startOfDay()
            ->lt(now($tz)->startOfDay());
    }

    public function effectiveStatus(): LedgerSettlementStatus
    {
        if ($this->isSettled()) {
            return LedgerSettlementStatus::Settled;
        }

        if ($this->isOverdue()) {
            return LedgerSettlementStatus::Overdue;
        }

        return LedgerSettlementStatus::Unsettled;
    }

    public function daysUntilRemittanceDue(): ?int
    {
        if ($this->isSettled() || $this->remittance_due_date === null || (float) $this->cash_service_fees_owed <= 0) {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');

        return (int) now($tz)->startOfDay()->diffInDays($this->remittance_due_date->copy()->startOfDay(), false);
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->effectiveStatus()->value;
    }

    public function getEffectiveStatusLabelAttribute(): string
    {
        return $this->effectiveStatus()->label();
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function getDaysUntilRemittanceDueAttribute(): ?int
    {
        return $this->daysUntilRemittanceDue();
    }

    public function scopeUnsettled($query)
    {
        return $query->whereNull('payout_id');
    }

    public function scopeSettled($query)
    {
        return $query->whereNotNull('payout_id');
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeWithCashFeesOwed($query)
    {
        return $query->where('cash_service_fees_owed', '>', 0);
    }

    public function scopeOverdueRemittance($query)
    {
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();

        return $query->whereNull('payout_id')
            ->where('cash_service_fees_owed', '>', 0)
            ->whereNotNull('remittance_due_date')
            ->whereDate('remittance_due_date', '<', $today);
    }
}
