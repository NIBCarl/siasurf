<?php

namespace App\Jobs;

use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use App\Events\RefundStatusUpdated;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundStatusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPayMongoRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $refundId)
    {
    }

    public function handle(PaymentServiceInterface $paymentService): void
    {
        $refund = Refund::with(['booking.student', 'booking.instructor', 'booking.surfSpot', 'payment'])
            ->find($this->refundId);

        if (! $refund || ! $refund->booking || ! $refund->payment) {
            return;
        }

        // Idempotency guard: never dispatch twice for the same refund record.
        if (! empty($refund->paymongo_refund_id) || $refund->status !== RefundStatus::Requested) {
            return;
        }

        $reasonMap = match ($refund->basis) {
            RefundBasis::InstructorCancelled,
            RefundBasis::WeatherSafety,
            RefundBasis::InstructorNoShow,
            RefundBasis::InstructorSuspended,
            RefundBasis::AdminOverride => 'others',
            default => 'requested_by_customer',
        };

        try {
            $gatewayData = $paymentService->processRefund(
                $refund->booking,
                (float) $refund->amount,
                $reasonMap,
                [
                    'refund_id' => $refund->id,
                    'booking_id' => $refund->booking_id,
                    'basis' => $refund->basis?->value,
                ]
            );
        } catch (\Throwable $exception) {
            Log::error('PayMongo refund dispatch failed', [
                'refund_id' => $refund->id,
                'booking_id' => $refund->booking_id,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            $isAsyncWorker = $this->job !== null && $this->job->getConnectionName() !== 'sync';

            if ($isAsyncWorker && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $this->markFailed($refund, $exception->getMessage());

            return;
        }

        $gatewayRefundId = $gatewayData['id'] ?? null;
        $gatewayStatus = strtolower((string) ($gatewayData['attributes']['status'] ?? 'pending'));

        DB::transaction(function () use ($refund, $gatewayData, $gatewayRefundId, $gatewayStatus) {
            $locked = Refund::with(['booking', 'payment'])
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== RefundStatus::Requested) {
                return;
            }

            $now = now();

            if ($gatewayStatus === 'succeeded') {
                $locked->update([
                    'status' => RefundStatus::Completed,
                    'paymongo_refund_id' => $gatewayRefundId,
                    'gateway_payload' => $gatewayData,
                    'processed_at' => $now,
                    'completed_at' => $now,
                ]);

                $locked->payment?->update([
                    'status' => PaymentStatus::Refunded,
                    'refunded_at' => $now,
                ]);

                $locked->booking?->update([
                    'payment_status' => PaymentStatus::Refunded,
                ]);
            } elseif ($gatewayStatus === 'failed') {
                $locked->update([
                    'status' => RefundStatus::Failed,
                    'paymongo_refund_id' => $gatewayRefundId,
                    'gateway_payload' => $gatewayData,
                    'failed_at' => $now,
                    'failure_reason' => (string) ($gatewayData['attributes']['failure_reason'] ?? 'Gateway rejected the refund request.'),
                ]);
            } else {
                $locked->update([
                    'status' => RefundStatus::Processing,
                    'paymongo_refund_id' => $gatewayRefundId,
                    'gateway_payload' => $gatewayData,
                    'processed_at' => $now,
                ]);

                $locked->payment?->update([
                    'status' => PaymentStatus::RefundProcessing,
                ]);

                $locked->booking?->update([
                    'payment_status' => PaymentStatus::RefundProcessing,
                ]);
            }
        });

        $this->dispatchNotifications($refund->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot']));
    }

    public function failed(\Throwable $exception): void
    {
        $refund = Refund::with(['booking.student', 'booking.instructor', 'booking.surfSpot'])
            ->find($this->refundId);

        if (! $refund || $refund->isCompleted()) {
            return;
        }

        $this->markFailed($refund, $exception->getMessage());
    }

    private function markFailed(Refund $refund, string $message): void
    {
        DB::transaction(function () use ($refund, $message) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->first();

            if (! $locked || $locked->isCompleted()) {
                return;
            }

            $locked->update([
                'status' => RefundStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $message,
            ]);
        });

        $fresh = $refund->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot']);
        $this->dispatchNotifications($fresh, notifyAdmins: true);
    }

    private function dispatchNotifications(?Refund $refund, bool $notifyAdmins = false): void
    {
        if (! $refund) {
            return;
        }

        try {
            broadcast(new RefundStatusUpdated($refund))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast refund status update.', [
                'refund_id' => $refund->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $refund->booking?->student?->notify(new RefundStatusNotification($refund));

        if ($notifyAdmins || $refund->status === RefundStatus::Failed) {
            User::query()->admins()->get()->each(
                fn (User $admin) => $admin->notify(new RefundStatusNotification($refund))
            );
        }
    }
}
