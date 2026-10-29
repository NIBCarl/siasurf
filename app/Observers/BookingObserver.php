<?php

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\ConversationService;

class BookingObserver
{
    public function __construct(private ConversationService $conversations)
    {
    }

    public function created(Booking $booking): void
    {
        $this->provisionIfConfirmed($booking);
    }

    public function updated(Booking $booking): void
    {
        if (!$booking->wasChanged('status')) {
            return;
        }

        $this->provisionIfConfirmed($booking);
    }

    /**
     * A thread becomes available as soon as the lesson is confirmed. This is
     * deliberately centralised here so cash acceptance, GCash webhooks, admin
     * changes and Instant Book all behave the same way.
     */
    private function provisionIfConfirmed(Booking $booking): void
    {
        if (!in_array($booking->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
        ], true)) {
            return;
        }

        $this->conversations->ensureForBooking($booking);
    }
}
