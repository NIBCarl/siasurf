<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\LedgerSettlementStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutChannel;
use App\Enums\PayoutType;
use App\Models\Booking;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorPayout;
use App\Models\User;
use App\Notifications\CashRemittanceOverdueNotification;
use App\Notifications\PayoutRecordedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EarningsLedgerService
{
    public static function remittanceDays(): int
    {
        return max(1, (int) config('siasurf.fees.cash_remittance_days', 7));
    }

    /**
     * Classify a booking's ledger contribution without mutating state.
     *
     * @return array{
     *     entry_type: string,
     *     ledger_date: string,
     *     is_gcash: bool,
     *     is_cash: bool,
     *     gcash_earnings: float,
     *     cash_earnings: float,
     *     platform_gcash_fees: float,
     *     cash_service_fees_owed: float
     * }|null
     */
    public function classifyBooking(Booking $booking): ?array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $payment = $booking->payment;

        if (! $payment) {
            return null;
        }

        // 1. Digital (GCash / PayMaya) completed lesson: SISA holds the money
        // and owes the instructor 100% of the lesson price.
        if (
            $payment->payment_method->isDigital()
            && $booking->status === BookingStatus::Completed
            && $booking->payment_status === PaymentStatus::Completed
        ) {
            $anchor = $booking->completed_at ?? $payment->paid_at ?? $booking->date ?? $booking->created_at ?? now();

            return [
                'entry_type' => 'gcash_completed',
                'ledger_date' => $anchor->copy()->timezone($tz)->toDateString(),
                'is_gcash' => true,
                'is_cash' => false,
                'gcash_earnings' => $booking->effectiveLessonPrice(),
                'cash_earnings' => 0.0,
                'platform_gcash_fees' => $payment->effectiveServiceFeeAmount(),
                'cash_service_fees_owed' => 0.0,
            ];
        }

        // 2. Digital cancelled / no-show booking where instructor retained part
        // or all of the lesson price per tiered refund policy.
        if (
            $payment->payment_method->isDigital()
            && in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)
            && (float) ($booking->instructor_retained_amount ?? 0) > 0
        ) {
            $anchor = $booking->cancelled_at ?? $booking->attendance_marked_at ?? $booking->updated_at ?? $booking->date ?? now();

            return [
                'entry_type' => 'gcash_retained',
                'ledger_date' => $anchor->copy()->timezone($tz)->toDateString(),
                'is_gcash' => true,
                'is_cash' => false,
                'gcash_earnings' => round((float) $booking->instructor_retained_amount, 2),
                'cash_earnings' => 0.0,
                'platform_gcash_fees' => $payment->effectiveServiceFeeAmount(),
                'cash_service_fees_owed' => 0.0,
            ];
        }

        // 3. Settled cash-on-hand booking (full or underpaid): instructor
        // collected cash at the beach and owes SISA the 7% service fee.
        if (
            $payment->payment_method === PaymentMethod::Cash
            && in_array($booking->payment_status, [PaymentStatus::Completed, PaymentStatus::Underpaid], true)
            && in_array($booking->status, [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
                BookingStatus::NoShow,
            ], true)
        ) {
            $cashFee = $payment->effectiveServiceFeeAmount();
            $cashEarning = $booking->payment_status === PaymentStatus::Completed
                ? $booking->effectiveLessonPrice()
                : round(max(0.0, (float) $payment->amount - $cashFee), 2);
            $anchor = $payment->paid_at ?? $booking->completed_at ?? $booking->date ?? $booking->created_at ?? now();

            return [
                'entry_type' => 'cash_settled',
                'ledger_date' => $anchor->copy()->timezone($tz)->toDateString(),
                'is_gcash' => false,
                'is_cash' => true,
                'gcash_earnings' => 0.0,
                'cash_earnings' => $cashEarning,
                'platform_gcash_fees' => 0.0,
                'cash_service_fees_owed' => $cashFee,
            ];
        }

        return null;
    }

    /**
     * Synchronize a booking onto its instructor's open daily ledger row.
     */
    public function syncBookingToLedger(Booking $booking): ?InstructorDailyLedger
    {
        return DB::transaction(function () use ($booking) {
            User::query()->whereKey($booking->instructor_id)->lockForUpdate()->first();

            $lockedBooking = Booking::with(['payment', 'dailyLedger'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBooking) {
                return null;
            }

            // Closed/settled daily ledger rows are immutable once included in a payout.
            if ($lockedBooking->dailyLedger && $lockedBooking->dailyLedger->isSettled()) {
                return $lockedBooking->dailyLedger;
            }

            $previousLedger = $lockedBooking->dailyLedger;
            $classified = $this->classifyBooking($lockedBooking);

            if ($classified === null) {
                if ($lockedBooking->daily_ledger_id !== null) {
                    $lockedBooking->update([
                        'daily_ledger_id' => null,
                        'ledger_entry_type' => null,
                    ]);

                    if ($previousLedger) {
                        $this->recalculateLedger($previousLedger);
                    }
                }

                return null;
            }

            $ledger = InstructorDailyLedger::query()
                ->where('instructor_id', $lockedBooking->instructor_id)
                ->whereDate('ledger_date', $classified['ledger_date'])
                ->whereNull('payout_id')
                ->lockForUpdate()
                ->first();

            if (! $ledger) {
                $ledger = InstructorDailyLedger::create([
                    'instructor_id' => $lockedBooking->instructor_id,
                    'ledger_date' => $classified['ledger_date'],
                    'settlement_status' => LedgerSettlementStatus::Unsettled->value,
                ]);
            }

            $lockedBooking->update([
                'daily_ledger_id' => $ledger->id,
                'ledger_entry_type' => $classified['entry_type'],
            ]);

            if ($previousLedger && $previousLedger->id !== $ledger->id) {
                $this->recalculateLedger($previousLedger);
            }

            return $this->recalculateLedger($ledger);
        });
    }

    /**
     * Recalculate totals, net payable, and remittance due date on an unsettled ledger row.
     */
    public function recalculateLedger(InstructorDailyLedger $ledger): ?InstructorDailyLedger
    {
        $lockedLedger = InstructorDailyLedger::query()
            ->whereKey($ledger->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedLedger) {
            return null;
        }

        if ($lockedLedger->isSettled()) {
            return $lockedLedger;
        }

        $bookings = $lockedLedger->bookings()->with('payment')->get();

        if ($bookings->isEmpty()) {
            $lockedLedger->delete();

            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $completedCount = 0;
        $gcashCount = 0;
        $cashCount = 0;
        $gcashEarnings = 0.0;
        $cashEarnings = 0.0;
        $platformGcashFees = 0.0;
        $cashFeesOwed = 0.0;

        foreach ($bookings as $booking) {
            $classified = $this->classifyBooking($booking);

            if ($classified === null) {
                continue;
            }

            $completedCount++;
            if ($classified['is_gcash']) {
                $gcashCount++;
            }
            if ($classified['is_cash']) {
                $cashCount++;
            }

            $gcashEarnings += $classified['gcash_earnings'];
            $cashEarnings += $classified['cash_earnings'];
            $platformGcashFees += $classified['platform_gcash_fees'];
            $cashFeesOwed += $classified['cash_service_fees_owed'];
        }

        if ($completedCount === 0) {
            $lockedLedger->delete();

            return null;
        }

        $gcashEarnings = round($gcashEarnings, 2);
        $cashEarnings = round($cashEarnings, 2);
        $platformGcashFees = round($platformGcashFees, 2);
        $cashFeesOwed = round($cashFeesOwed, 2);
        $totalEarnings = round($gcashEarnings + $cashEarnings, 2);
        $netPayable = round($gcashEarnings - $cashFeesOwed, 2);

        $remittanceDueDate = $cashFeesOwed > 0
            ? $lockedLedger->ledger_date->copy()->timezone($tz)->startOfDay()->addDays(self::remittanceDays())->toDateString()
            : null;

        $isOverdue = $remittanceDueDate !== null
            && Carbon::parse($remittanceDueDate, $tz)->startOfDay()->lt(now($tz)->startOfDay());

        $lockedLedger->update([
            'completed_bookings_count' => $completedCount,
            'gcash_bookings_count' => $gcashCount,
            'cash_bookings_count' => $cashCount,
            'gcash_earnings' => $gcashEarnings,
            'cash_earnings' => $cashEarnings,
            'total_earnings' => $totalEarnings,
            'platform_gcash_fees' => $platformGcashFees,
            'cash_service_fees_owed' => $cashFeesOwed,
            'net_payable' => $netPayable,
            'remittance_due_date' => $remittanceDueDate,
            'settlement_status' => $isOverdue
                ? LedgerSettlementStatus::Overdue->value
                : LedgerSettlementStatus::Unsettled->value,
        ]);

        return $lockedLedger->fresh();
    }

    /**
     * Record an in-person SISA admin settlement (payout, remittance, or zero-cash offset)
     * across an instructor's unsettled daily ledger rows.
     *
     * @param  array{ledger_ids?: array<int>, period_end?: ?string, payment_channel?: ?string, receipt_reference?: ?string, notes?: ?string}  $attributes
     */
    public function recordInPersonSettlement(User $instructor, User $admin, array $attributes = []): InstructorPayout
    {
        if (! $instructor->isInstructor()) {
            throw new \DomainException('Settlements can only be recorded for instructor accounts.');
        }

        return DB::transaction(function () use ($instructor, $admin, $attributes) {
            User::query()->whereKey($instructor->id)->lockForUpdate()->firstOrFail();

            $query = InstructorDailyLedger::query()
                ->where('instructor_id', $instructor->id)
                ->whereNull('payout_id')
                ->orderBy('ledger_date')
                ->orderBy('id')
                ->lockForUpdate();

            $ledgerIds = array_values(array_filter(array_map('intval', $attributes['ledger_ids'] ?? [])));

            if (! empty($ledgerIds)) {
                $query->whereIn('id', $ledgerIds);
            } elseif (! empty($attributes['period_end'])) {
                $query->whereDate('ledger_date', '<=', (string) $attributes['period_end']);
            }

            $ledgers = $query->get();

            if ($ledgers->isEmpty()) {
                throw new \DomainException('There are no unsettled daily ledger entries to settle for this instructor.');
            }

            $gcashSettled = round((float) $ledgers->sum(fn (InstructorDailyLedger $row) => (float) $row->gcash_earnings), 2);
            $cashFeesSettled = round((float) $ledgers->sum(fn (InstructorDailyLedger $row) => (float) $row->cash_service_fees_owed), 2);
            $netPayable = round($gcashSettled - $cashFeesSettled, 2);

            $settlementType = match (true) {
                $netPayable > 0 => PayoutType::Payout,
                $netPayable < 0 => PayoutType::Remittance,
                default => PayoutType::Offset,
            };

            $channel = $settlementType === PayoutType::Offset
                ? PayoutChannel::Offset
                : (PayoutChannel::tryFrom((string) ($attributes['payment_channel'] ?? '')) ?? PayoutChannel::InPersonCash);

            if ($channel === PayoutChannel::Offset && $settlementType !== PayoutType::Offset) {
                $channel = PayoutChannel::InPersonCash;
            }

            $now = now();
            $periodStart = $ledgers->min(fn (InstructorDailyLedger $row) => $row->ledger_date->toDateString());
            $periodEnd = $ledgers->max(fn (InstructorDailyLedger $row) => $row->ledger_date->toDateString());

            $payout = InstructorPayout::create([
                'reference_code' => $this->generateReferenceCode(),
                'instructor_id' => $instructor->id,
                'recorded_by' => $admin->id,
                'settlement_type' => $settlementType->value,
                'payment_channel' => $channel->value,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'ledger_days_count' => $ledgers->count(),
                'gcash_earnings_settled' => $gcashSettled,
                'cash_fees_settled' => $cashFeesSettled,
                'net_payout_amount' => $netPayable,
                'receipt_reference' => ! empty($attributes['receipt_reference']) ? trim((string) $attributes['receipt_reference']) : null,
                'notes' => ! empty($attributes['notes']) ? trim((string) $attributes['notes']) : null,
                'paid_at' => $now,
            ]);

            InstructorDailyLedger::query()
                ->whereIn('id', $ledgers->pluck('id'))
                ->update([
                    'payout_id' => $payout->id,
                    'settlement_status' => LedgerSettlementStatus::Settled->value,
                    'settled_at' => $now,
                ]);

            DB::afterCommit(function () use ($payout, $instructor) {
                try {
                    $fresh = $payout->fresh(['instructor', 'recordedBy']);
                    if ($fresh) {
                        $instructor->notify(new PayoutRecordedNotification($fresh));
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send payout recorded notification.', [
                        'payout_id' => $payout->id,
                        'instructor_id' => $instructor->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

            return $payout->fresh(['instructor', 'recordedBy', 'dailyLedgers']);
        });
    }

    /**
     * Aggregate unsettled balances, remittance deadline status, and settled totals for one instructor.
     *
     * @return array<string, mixed>
     */
    public function getInstructorSummary(User $instructor): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->startOfDay();
        $monthStart = $today->copy()->startOfMonth()->toDateString();

        $allLedgers = InstructorDailyLedger::query()
            ->where('instructor_id', $instructor->id)
            ->orderBy('ledger_date')
            ->get();

        $unsettled = $allLedgers->whereNull('payout_id');
        $settledPayouts = InstructorPayout::query()
            ->where('instructor_id', $instructor->id)
            ->get();

        $unsettledGcash = round((float) $unsettled->sum(fn (InstructorDailyLedger $r) => (float) $r->gcash_earnings), 2);
        $unsettledCashEarnings = round((float) $unsettled->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_earnings), 2);
        $unsettledCashFees = round((float) $unsettled->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed), 2);
        $netPayable = round($unsettledGcash - $unsettledCashFees, 2);

        $cashFeeRows = $unsettled->filter(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed > 0 && $r->remittance_due_date !== null);
        $earliestDueRow = $cashFeeRows->sortBy('remittance_due_date')->first();
        $earliestDueDate = $earliestDueRow?->remittance_due_date?->toDateString();
        $daysUntilDue = $earliestDueRow?->daysUntilRemittanceDue();

        $overdueRows = $cashFeeRows->filter(fn (InstructorDailyLedger $r) => $r->isOverdue());
        $overdueCashFees = round((float) $overdueRows->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed), 2);

        $direction = match (true) {
            $unsettled->isEmpty() => 'clear',
            $netPayable > 0 => 'payout',
            $netPayable < 0 => 'remittance',
            default => 'offset',
        };

        $totalEarnings = round((float) $allLedgers->sum(fn (InstructorDailyLedger $r) => (float) $r->total_earnings), 2);
        $thisMonthEarnings = round((float) $allLedgers
            ->filter(fn (InstructorDailyLedger $r) => $r->ledger_date->toDateString() >= $monthStart)
            ->sum(fn (InstructorDailyLedger $r) => (float) $r->total_earnings), 2);

        $totalPayoutsDisbursed = round((float) $settledPayouts
            ->filter(fn (InstructorPayout $p) => (float) $p->net_payout_amount > 0)
            ->sum(fn (InstructorPayout $p) => (float) $p->net_payout_amount), 2);

        $totalRemittancesCollected = round((float) $settledPayouts
            ->filter(fn (InstructorPayout $p) => (float) $p->net_payout_amount < 0)
            ->sum(fn (InstructorPayout $p) => abs((float) $p->net_payout_amount)), 2);

        $totalCashFeesSettled = round((float) $settledPayouts
            ->sum(fn (InstructorPayout $p) => (float) $p->cash_fees_settled), 2);

        return [
            'unsettled_days_count' => $unsettled->count(),
            'unsettled_gcash_earnings' => $unsettledGcash,
            'unsettled_cash_earnings' => $unsettledCashEarnings,
            'unsettled_cash_fees_owed' => $unsettledCashFees,
            'net_payable' => $netPayable,
            'settlement_direction' => $direction,
            'earliest_remittance_due_date' => $earliestDueDate,
            'days_until_remittance_due' => $daysUntilDue,
            'overdue_days_count' => $overdueRows->count(),
            'overdue_cash_fees_amount' => $overdueCashFees,
            'has_overdue_remittance' => $overdueRows->isNotEmpty(),
            'total_earnings' => $totalEarnings,
            'this_month_earnings' => $thisMonthEarnings,
            'total_payouts_disbursed' => $totalPayoutsDisbursed,
            'total_remittances_collected' => $totalRemittancesCollected,
            'total_cash_fees_settled' => $totalCashFeesSettled,
            'payout_count' => $settledPayouts->count(),
            'remittance_days' => self::remittanceDays(),
        ];
    }

    /**
     * Platform-wide payout and cash fee remittance overview for SISA Admin.
     *
     * @return array<string, mixed>
     */
    public function getPlatformSummary(): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth();
        $monthEnd = now($tz)->endOfMonth();

        $unsettled = InstructorDailyLedger::query()->unsettled()->get();
        $overdue = $unsettled->filter(fn (InstructorDailyLedger $r) => $r->isOverdue());

        $totalUnsettledGcash = round((float) $unsettled->sum(fn (InstructorDailyLedger $r) => (float) $r->gcash_earnings), 2);
        $totalUnsettledCashFees = round((float) $unsettled->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed), 2);

        $monthPayouts = InstructorPayout::query()
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->get();

        return [
            'total_unsettled_gcash_payable' => $totalUnsettledGcash,
            'total_unsettled_cash_fees_owed' => $totalUnsettledCashFees,
            'net_platform_payable' => round($totalUnsettledGcash - $totalUnsettledCashFees, 2),
            'unsettled_instructors_count' => $unsettled->pluck('instructor_id')->unique()->count(),
            'overdue_instructors_count' => $overdue->pluck('instructor_id')->unique()->count(),
            'overdue_cash_fees_total' => round((float) $overdue->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed), 2),
            'disbursed_this_month' => round((float) $monthPayouts
                ->filter(fn (InstructorPayout $p) => (float) $p->net_payout_amount > 0)
                ->sum(fn (InstructorPayout $p) => (float) $p->net_payout_amount), 2),
            'remittances_this_month' => round((float) $monthPayouts
                ->filter(fn (InstructorPayout $p) => (float) $p->net_payout_amount < 0)
                ->sum(fn (InstructorPayout $p) => abs((float) $p->net_payout_amount)), 2),
            'payouts_this_month_count' => $monthPayouts->count(),
            'as_of_date' => $today,
            'remittance_days' => self::remittanceDays(),
        ];
    }

    /**
     * Backfill daily ledger rows from existing completed/retained bookings that
     * are not yet attached to a daily ledger.
     */
    public function backfillExistingBookings(?int $instructorId = null): int
    {
        $query = Booking::with('payment')
            ->whereNull('daily_ledger_id')
            ->whereHas('payment')
            ->when($instructorId !== null, fn ($q) => $q->where('instructor_id', $instructorId))
            ->where(function ($q) {
                $q->where(function ($completed) {
                    $completed->where('status', BookingStatus::Completed->value)
                        ->whereIn('payment_status', [PaymentStatus::Completed->value, PaymentStatus::Underpaid->value]);
                })->orWhere(function ($retained) {
                    $retained->whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                        ->where('instructor_retained_amount', '>', 0);
                })->orWhere(function ($cashSettled) {
                    $cashSettled->whereIn('payment_status', [PaymentStatus::Completed->value, PaymentStatus::Underpaid->value])
                        ->whereHas('payment', fn ($p) => $p->where('payment_method', PaymentMethod::Cash->value));
                });
            })
            ->orderBy('id');

        $synced = 0;

        $query->chunkById(100, function ($bookings) use (&$synced) {
            foreach ($bookings as $booking) {
                if ($this->syncBookingToLedger($booking) !== null) {
                    $synced++;
                }
            }
        });

        return $synced;
    }

    /**
     * Mark unsettled ledger rows with expired cash remittance deadlines as Overdue
     * and notify affected instructors once.
     *
     * @return array{updated_rows: int, notified_instructors: int, overdue_rows: int}
     */
    public function markOverdueRemittances(bool $sendNotifications = true): array
    {
        $overdueRows = InstructorDailyLedger::query()
            ->with('instructor')
            ->overdueRemittance()
            ->get();

        if ($overdueRows->isEmpty()) {
            return [
                'updated_rows' => 0,
                'notified_instructors' => 0,
                'overdue_rows' => 0,
            ];
        }

        $updatedCount = InstructorDailyLedger::query()
            ->whereIn('id', $overdueRows->pluck('id'))
            ->where('settlement_status', LedgerSettlementStatus::Unsettled->value)
            ->update(['settlement_status' => LedgerSettlementStatus::Overdue->value]);

        $notifiedInstructors = 0;

        if ($sendNotifications) {
            $unnotifiedByInstructor = $overdueRows
                ->whereNull('overdue_notified_at')
                ->groupBy('instructor_id');

            $admins = $unnotifiedByInstructor->isNotEmpty()
                ? User::query()->admins()->get()
                : collect();

            foreach ($unnotifiedByInstructor as $rows) {
                $instructor = $rows->first()?->instructor;
                if (! $instructor) {
                    continue;
                }

                $overdueAmount = round((float) $rows->sum(fn (InstructorDailyLedger $r) => (float) $r->cash_service_fees_owed), 2);
                $earliestDue = $rows->min(fn (InstructorDailyLedger $r) => $r->remittance_due_date?->toDateString()) ?? '';

                try {
                    $instructor->notify(new CashRemittanceOverdueNotification(
                        $overdueAmount,
                        $rows->count(),
                        $earliestDue
                    ));

                    InstructorDailyLedger::query()
                        ->whereIn('id', $rows->pluck('id'))
                        ->update(['overdue_notified_at' => now()]);

                    $notifiedInstructors++;
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send overdue cash remittance notification.', [
                        'instructor_id' => $instructor->id,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                foreach ($admins as $admin) {
                    try {
                        $admin->notify(new CashRemittanceOverdueNotification(
                            $overdueAmount,
                            $rows->count(),
                            $earliestDue,
                            $instructor->id,
                            $instructor->name
                        ));
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to notify admin of overdue cash remittance.', [
                            'instructor_id' => $instructor->id,
                            'admin_id' => $admin->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        return [
            'updated_rows' => $updatedCount,
            'notified_instructors' => $notifiedInstructors,
            'overdue_rows' => $overdueRows->count(),
        ];
    }

    private function generateReferenceCode(): string
    {
        $prefix = 'SISA-PAY-' . now(config('app.timezone', 'Asia/Manila'))->format('Ymd');

        do {
            $candidate = $prefix . '-' . strtoupper(Str::random(6));
        } while (InstructorPayout::query()->where('reference_code', $candidate)->exists());

        return $candidate;
    }
}
