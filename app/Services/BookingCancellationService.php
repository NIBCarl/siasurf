<?php

namespace App\Services;

use App\Data\RefundCalculation;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use App\Events\BookingCancelled;
use App\Events\RefundStatusUpdated;
use App\Exceptions\BookingChangeException;
use App\Jobs\ProcessPayMongoRefundJob;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\RefundStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single orchestrator for every cancellation and refund across the platform:
 * student self-cancellations/withdrawals, instructor cancellations & suspensions,
 * reschedule declines, and admin overrides.
 */
class BookingCancellationService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected RefundCalculatorService $calculator,
        protected ?EarningsLedgerService $earningsLedger = null,
    ) {
    }

    /**
     * Cancel a booking atomically, release its slot, record any refund
     * entitlement in `refunds`, and dispatch PayMongo refund + notifications.
     *
     * @return array{booking: Booking, refund: ?Refund, calculation: RefundCalculation}
     */
    public function cancel(
        Booking $booking,
        CancellationReason $reason,
        ?string $note = null,
        ?User $actor = null,
        ?float $overrideAmount = null,
        ?string $studentNotificationMessage = null,
        bool $notifyInstructor = true,
    ): array {
        $result = DB::transaction(function () use (
            $booking,
            $reason,
            $note,
            $actor,
            $overrideAmount,
            $studentNotificationMessage,
            $notifyInstructor
        ) {
            $locked = Booking::with(['payment', 'student', 'instructor', 'surfSpot'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                throw new BookingChangeException('This booking has already been cancelled.');
            }

            if (! in_array($locked->status, [
                BookingStatus::Reserved,
                BookingStatus::Pending,
                BookingStatus::AwaitingPayment,
                BookingStatus::Confirmed,
            ], true)) {
                throw new BookingChangeException(
                    'This booking can no longer be cancelled because it is ' . $locked->status->label() . '.'
                );
            }

            $effectiveReason = ($reason === CancellationReason::Student && $locked->hasPendingRescheduleResponse())
                ? CancellationReason::RescheduleDeclined
                : $reason;

            $calculation = $overrideAmount !== null
                ? $this->buildOverrideCalculation($locked, $overrideAmount)
                : $this->calculator->calculate($locked, $effectiveReason);

            $date = $this->availabilityService->dateOf($locked);
            $period = $this->availabilityService->timePeriodOf($locked);
            $instructorId = $locked->instructor_id;

            $locked->cancel(
                $note,
                $effectiveReason,
                $calculation->refundAmount,
                $calculation->instructorRetainedAmount
            );

            $locked->update([
                'payment_deadline_at' => null,
                'accept_deadline_at' => null,
            ]);

            $this->availabilityService->releaseSlot($instructorId, $date, $period, $locked->id);

            $refund = $this->createRefundRecordIfEligible($locked, $calculation, $note, $actor);

            ($this->earningsLedger ?? app(EarningsLedgerService::class))
                ->syncBookingToLedger($locked->fresh(['payment']));

            app(BookingEventService::class)->recordCancelled(
                $locked,
                $effectiveReason,
                $actor?->id,
                $note
            );

            $refundSummary = $this->buildRefundSummary($calculation, $refund);
            $freshBooking = $locked->fresh(['student', 'instructor', 'surfSpot', 'payment', 'latestRefund']);

            DB::afterCommit(function () use (
                $freshBooking,
                $refund,
                $note,
                $studentNotificationMessage,
                $refundSummary,
                $actor,
                $notifyInstructor
            ) {
                $this->notifyAndDispatch(
                    $freshBooking,
                    $refund,
                    $note,
                    $studentNotificationMessage,
                    $refundSummary,
                    $actor,
                    $notifyInstructor
                );
            });

            return [
                'booking' => $freshBooking,
                'refund' => $refund,
                'calculation' => $calculation,
            ];
        });

        return $result;
    }

    /**
     * Issue a refund on an already-cancelled booking (admin override / legacy cleanup).
     */
    public function issueAdminRefund(
        Booking $booking,
        User $admin,
        float $amount,
        string $reason,
    ): Refund {
        return DB::transaction(function () use ($booking, $admin, $amount, $reason) {
            $locked = Booking::with(['payment', 'student', 'instructor', 'surfSpot'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->payment || $locked->payment->payment_method === PaymentMethod::Cash) {
                throw new BookingChangeException('Only paid digital bookings can receive a refund.');
            }

            if ($locked->refunds()->whereIn('status', [
                RefundStatus::Requested->value,
                RefundStatus::Processing->value,
                RefundStatus::Completed->value,
                RefundStatus::ManualRequired->value,
                RefundStatus::Failed->value,
            ])->exists()) {
                throw new BookingChangeException('A refund record already exists for this booking. Retry or complete the existing refund instead.');
            }

            $calculation = $this->buildOverrideCalculation($locked, $amount);

            $locked->update([
                'refund_amount' => $calculation->refundAmount,
                'instructor_retained_amount' => $calculation->instructorRetainedAmount,
            ]);

            ($this->earningsLedger ?? app(EarningsLedgerService::class))
                ->syncBookingToLedger($locked->fresh(['payment']));

            $refund = $this->createRefundRecordIfEligible($locked, $calculation, $reason, $admin);

            if (! $refund) {
                throw new BookingChangeException('Refund amount must be greater than zero.');
            }

            DB::afterCommit(function () use ($refund) {
                if ($refund->status === RefundStatus::Requested) {
                    ProcessPayMongoRefundJob::dispatch($refund->id);
                } else {
                    $this->broadcastRefund($refund, notifyAdmins: true);
                }
            });

            return $refund;
        });
    }

    /**
     * Retry a failed PayMongo refund from SISA Admin oversight.
     */
    public function retryRefund(Refund $refund, User $admin): Refund
    {
        $updated = DB::transaction(function () use ($refund, $admin) {
            $locked = Refund::with(['booking', 'payment'])
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canRetry()) {
                throw new BookingChangeException('Only failed gateway refunds can be retried.');
            }

            $locked->update([
                'status' => RefundStatus::Requested,
                'failure_reason' => null,
                'failed_at' => null,
                'initiated_by' => $admin->id,
                'requested_at' => now(),
            ]);

            $locked->payment?->update(['status' => PaymentStatus::RefundRequested]);
            $locked->booking?->update(['payment_status' => PaymentStatus::RefundRequested]);

            return $locked->fresh(['booking.student', 'booking.instructor', 'payment']);
        });

        ProcessPayMongoRefundJob::dispatch($updated->id);

        return $updated->fresh(['booking', 'payment']);
    }

    /**
     * Mark a manual GCash or out-of-window refund as completed by SISA Admin.
     */
    public function completeManualRefund(
        Refund $refund,
        User $admin,
        ?string $reference = null,
        ?string $note = null,
    ): Refund {
        $updated = DB::transaction(function () use ($refund, $admin, $reference, $note) {
            $locked = Refund::with(['booking', 'payment'])
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canCompleteManually()) {
                throw new BookingChangeException('This refund cannot be manually completed in its current state.');
            }

            $now = now();
            $payload = $locked->gateway_payload ?? [];
            $payload['manual_completion'] = [
                'completed_by' => $admin->id,
                'completed_by_name' => $admin->name,
                'reference' => $reference,
                'note' => $note,
                'completed_at' => $now->toIso8601String(),
            ];

            $locked->update([
                'status' => RefundStatus::Completed,
                'paymongo_refund_id' => $reference ?: $locked->paymongo_refund_id,
                'gateway_payload' => $payload,
                'processed_at' => $locked->processed_at ?? $now,
                'completed_at' => $now,
                'failure_reason' => null,
            ]);

            $locked->payment?->update([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => $now,
            ]);

            $locked->booking?->update([
                'payment_status' => PaymentStatus::Refunded,
            ]);

            return $locked->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot', 'payment']);
        });

        $this->broadcastRefund($updated);

        return $updated;
    }

    private function buildOverrideCalculation(Booking $booking, float $overrideAmount): RefundCalculation
    {
        $lessonPrice = $booking->effectiveLessonPrice();
        $serviceFee = $booking->effectiveServiceFeeAmount();
        $base = $this->calculator->calculate($booking, CancellationReason::Admin);

        $maxRefundable = $serviceFee > 0 ? $lessonPrice : (float) $booking->total_amount;
        $clamped = round(max(0.0, min($overrideAmount, $maxRefundable)), 2);
        $percentage = $lessonPrice > 0 ? (int) min(100, round(($clamped / $lessonPrice) * 100)) : 0;
        $retained = round(max(0.0, $lessonPrice - $clamped), 2);
        $dispatchMode = $clamped > 0 ? $base->dispatchMode : 'none';

        return new RefundCalculation(
            eligible: $clamped > 0,
            requiresRefundRecord: $clamped > 0 && $base->requiresRefundRecord,
            policyType: 'admin_override',
            basis: RefundBasis::AdminOverride,
            refundPercentage: $percentage,
            lessonPrice: $lessonPrice,
            serviceFeeRetained: $serviceFee,
            refundAmount: $clamped,
            instructorRetainedAmount: $retained,
            daysBeforeSession: $base->daysBeforeSession,
            daysSincePayment: $base->daysSincePayment,
            canAutoDispatch: in_array($dispatchMode, ['paymongo', 'xendit', 'mock'], true),
            dispatchMode: $dispatchMode,
            summary: $clamped > 0
                ? 'SISA Admin issued a refund of ₱' . number_format($clamped, 2) . '.'
                : 'No refund amount issued.',
        );
    }

    private function createRefundRecordIfEligible(
        Booking $booking,
        RefundCalculation $calculation,
        ?string $note,
        ?User $actor,
    ): ?Refund {
        if (! $calculation->requiresRefundRecord || $calculation->refundAmount <= 0 || ! $booking->payment) {
            return null;
        }

        $payment = $booking->payment;
        $status = $calculation->canAutoDispatch
            ? RefundStatus::Requested
            : RefundStatus::ManualRequired;

        $refund = Refund::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'initiated_by' => $actor?->id,
            'lesson_price' => $calculation->lessonPrice,
            'service_fee_retained' => $calculation->serviceFeeRetained,
            'refund_percentage' => $calculation->refundPercentage,
            'amount' => $calculation->refundAmount,
            'instructor_retained_amount' => $calculation->instructorRetainedAmount,
            'policy_type' => $calculation->policyType,
            'basis' => $calculation->basis,
            'days_before_session' => $calculation->daysBeforeSession,
            'days_since_payment' => $calculation->daysSincePayment,
            'status' => $status,
            'gateway' => in_array($calculation->dispatchMode, ['none', 'manual_required'], true) ? 'manual_gcash' : $calculation->dispatchMode,
            'paymongo_payment_id' => $payment->gateway_payment_id ?: $payment->transaction_id,
            'reason' => $note,
            'requested_at' => now(),
        ]);

        $payment->update([
            'status' => PaymentStatus::RefundRequested,
        ]);

        $booking->update([
            'payment_status' => PaymentStatus::RefundRequested,
        ]);

        return $refund;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRefundSummary(RefundCalculation $calculation, ?Refund $refund): array
    {
        if (! $refund) {
            return [
                'refund_id' => null,
                'refund_amount' => 0.0,
                'service_fee_retained' => $calculation->serviceFeeRetained,
                'refund_percentage' => 0,
                'status' => null,
                'status_label' => 'No refund',
                'basis' => $calculation->basis->value,
                'basis_label' => $calculation->basis->label(),
                'summary' => $calculation->summary,
            ];
        }

        return [
            'refund_id' => $refund->id,
            'refund_amount' => (float) $refund->amount,
            'service_fee_retained' => (float) $refund->service_fee_retained,
            'refund_percentage' => $refund->refund_percentage,
            'status' => $refund->status->value,
            'status_label' => $refund->status_label,
            'basis' => $refund->basis->value,
            'basis_label' => $refund->basis_label,
            'summary' => $calculation->summary,
        ];
    }

    private function notifyAndDispatch(
        Booking $booking,
        ?Refund $refund,
        ?string $note,
        ?string $studentMessage,
        array $refundSummary,
        ?User $actor = null,
        bool $notifyInstructor = true,
    ): void {
        try {
            broadcast(new BookingCancelled($booking, $note, $refundSummary))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast booking cancellation.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $booking->student?->notify(new BookingCancelledNotification(
            $booking,
            $studentMessage ?? $note,
            $refundSummary
        ));

        if ($notifyInstructor && ($actor === null || $actor->id !== $booking->instructor_id)) {
            $booking->instructor?->notify(new BookingCancelledNotification(
                $booking,
                $note,
                $refundSummary
            ));
        }

        if (! $refund) {
            return;
        }

        if ($refund->status === RefundStatus::Requested) {
            ProcessPayMongoRefundJob::dispatch($refund->id);
        } else {
            $this->broadcastRefund($refund, notifyAdmins: true);
        }
    }

    private function broadcastRefund(Refund $refund, bool $notifyAdmins = false): void
    {
        try {
            broadcast(new RefundStatusUpdated($refund))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast refund status.', [
                'refund_id' => $refund->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $refund->booking?->student?->notify(new RefundStatusNotification($refund));

        if ($notifyAdmins) {
            User::query()->admins()->get()->each(
                fn (User $admin) => $admin->notify(new RefundStatusNotification($refund))
            );
        }
    }
}
