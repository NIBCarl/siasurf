<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\CancellationReason;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\BookingEvent;
use App\Models\Payment;
use Carbon\Carbon;

class BookingEventService
{
    /**
     * Record a completed lifecycle event and bump the booking's last_event_at
     * timestamp so the Admin Manifest stays ordered by latest booking activity.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Booking $booking,
        string $eventType,
        string $title,
        ?string $description = null,
        ?int $actorId = null,
        array $metadata = [],
        ?Carbon $occurredAt = null,
    ): BookingEvent {
        $moment = $occurredAt ?? now();

        $event = BookingEvent::create([
            'booking_id' => $booking->id,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => $moment,
        ]);

        if (! $booking->last_event_at || $moment->greaterThanOrEqualTo($booking->last_event_at)) {
            $booking->forceFill(['last_event_at' => $moment])->saveQuietly();
        }

        return $event;
    }

    public function recordCreated(Booking $booking, ?int $actorId = null): BookingEvent
    {
        $booking->loadMissing(['student', 'instructor', 'surfSpot']);
        $dateLabel = $booking->date?->format('M d, Y') ?? (string) $booking->date;
        $slot = $this->formatSlot($booking->start_time?->format('H:i'), $booking->end_time?->format('H:i'));

        return $this->record(
            $booking,
            BookingEvent::TYPE_CREATED,
            'Booking Created',
            sprintf(
                '%s booked %s with %s for %s%s.',
                $booking->student?->name ?? 'Student',
                $booking->surfSpot?->name ?? 'surf lesson',
                $booking->instructor?->name ?? 'instructor',
                $dateLabel,
                $slot ? " ({$slot})" : ''
            ),
            $actorId ?? $booking->student_id,
            [
                'date' => $booking->date?->format('Y-m-d'),
                'start_time' => $booking->start_time?->format('H:i'),
                'end_time' => $booking->end_time?->format('H:i'),
                'total_amount' => (float) $booking->total_amount,
            ],
            $booking->created_at ?? now(),
        );
    }

    public function recordConfirmed(Booking $booking, ?int $actorId = null, ?string $via = null): BookingEvent
    {
        $booking->loadMissing(['instructor', 'surfSpot']);
        $dateLabel = $booking->date?->format('M d, Y') ?? (string) $booking->date;
        $slot = $this->formatSlot($booking->start_time?->format('H:i'), $booking->end_time?->format('H:i'));

        return $this->record(
            $booking,
            BookingEvent::TYPE_CONFIRMED,
            'Booking Confirmed',
            sprintf(
                'Lesson with %s confirmed for %s%s%s.',
                $booking->instructor?->name ?? 'instructor',
                $dateLabel,
                $slot ? " ({$slot})" : '',
                $via ? " via {$via}" : ''
            ),
            $actorId ?? $booking->instructor_id,
            [
                'date' => $booking->date?->format('Y-m-d'),
                'start_time' => $booking->start_time?->format('H:i'),
                'end_time' => $booking->end_time?->format('H:i'),
                'via' => $via,
            ],
        );
    }

    public function recordPaymentCompleted(Booking $booking, ?Payment $payment = null, ?int $actorId = null): BookingEvent
    {
        $payment = $payment ?? $booking->payment;
        $amount = (float) ($payment?->amount ?? $booking->total_amount ?? 0);
        $method = $payment?->payment_method?->value ?? 'cash';
        $methodLabel = $method === 'gcash' ? 'GCash' : ucfirst($method);
        $isUnderpaid = $payment?->status === \App\Enums\PaymentStatus::Underpaid;

        return $this->record(
            $booking,
            BookingEvent::TYPE_PAYMENT_COMPLETED,
            $isUnderpaid ? 'Partial Cash Payment Recorded' : 'Payment Completed',
            sprintf(
                '%s payment of ₱%s recorded%s.',
                $methodLabel,
                number_format($amount, 2),
                $isUnderpaid ? ' (Underpaid violation flagged)' : ''
            ),
            $actorId,
            [
                'amount' => $amount,
                'payment_method' => $method,
                'payment_status' => $payment?->status?->value,
            ],
        );
    }

    public function recordRescheduled(
        Booking $booking,
        BookingChangeRequest $changeRequest,
        ?int $actorId = null,
    ): BookingEvent {
        $oldDate = $changeRequest->original_date?->format('M d, Y') ?? 'original date';
        $oldSlot = $changeRequest->describeTimeRange(
            $changeRequest->original_start_time,
            $changeRequest->original_end_time,
            $changeRequest->original_time_period
        );
        $newDate = $changeRequest->new_date?->format('M d, Y') ?? ($booking->date?->format('M d, Y') ?? '');
        $newSlot = $changeRequest->describeTimeRange(
            $changeRequest->new_start_time,
            $changeRequest->new_end_time,
            $changeRequest->new_time_period
        );

        return $this->record(
            $booking,
            BookingEvent::TYPE_RESCHEDULED,
            'Booking Rescheduled',
            sprintf(
                'Rescheduled from %s (%s) to %s (%s) after student agreement.',
                $oldDate,
                $oldSlot,
                $newDate,
                $newSlot
            ),
            $actorId ?? $changeRequest->reviewed_by ?? $changeRequest->requested_by,
            [
                'change_request_id' => $changeRequest->id,
                'old_date' => $changeRequest->original_date?->format('Y-m-d'),
                'old_start_time' => $changeRequest->original_start_time?->format('H:i'),
                'old_end_time' => $changeRequest->original_end_time?->format('H:i'),
                'new_date' => $changeRequest->new_date?->format('Y-m-d'),
                'new_start_time' => $changeRequest->new_start_time?->format('H:i'),
                'new_end_time' => $changeRequest->new_end_time?->format('H:i'),
                'student_agreed_at' => $changeRequest->student_agreed_at?->toIso8601String(),
                'reason' => $changeRequest->reason,
            ],
        );
    }

    public function recordCancelled(
        Booking $booking,
        CancellationReason $reason,
        ?int $actorId = null,
        ?string $note = null,
    ): BookingEvent {
        return $this->record(
            $booking,
            BookingEvent::TYPE_CANCELLED,
            'Booking Cancelled',
            trim($reason->label() . ($note ? ": {$note}" : '')),
            $actorId,
            [
                'reason' => $reason->value,
                'refund_amount' => (float) ($booking->refund_amount ?? 0),
            ],
        );
    }

    public function recordCompleted(Booking $booking, ?int $actorId = null): BookingEvent
    {
        return $this->record(
            $booking,
            BookingEvent::TYPE_COMPLETED,
            'Session Completed',
            'Surf lesson marked completed.',
            $actorId ?? $booking->completed_by ?? $booking->instructor_id,
        );
    }

    public function recordAttendanceMarked(
        Booking $booking,
        AttendanceStatus $status,
        ?int $actorId = null,
    ): BookingEvent {
        return $this->record(
            $booking,
            BookingEvent::TYPE_ATTENDANCE_MARKED,
            'Attendance Recorded',
            'Student marked ' . $status->label() . '.',
            $actorId ?? $booking->instructor_id,
            ['attendance_status' => $status->value],
        );
    }

    private function formatSlot(?string $start, ?string $end): ?string
    {
        if (! $start) {
            return null;
        }

        $startLabel = Carbon::createFromFormat('H:i', $start)->format('g:i A');
        if (! $end) {
            return $startLabel;
        }

        return $startLabel . ' – ' . Carbon::createFromFormat('H:i', $end)->format('g:i A');
    }
}
