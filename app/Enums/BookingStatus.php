<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case PaymentTimeout = 'payment_timeout';
    case Expired = 'expired';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::AwaitingPayment => 'Awaiting Payment',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::PaymentTimeout => 'Payment Timed Out',
            self::Expired => 'Expired',
            self::NoShow => 'No Show',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::AwaitingPayment => 'amber',
            self::Confirmed => 'green',
            self::InProgress => 'blue',
            self::Completed => 'indigo',
            self::Declined => 'red',
            self::Cancelled => 'red',
            self::PaymentTimeout => 'amber',
            self::Expired => 'gray',
            self::NoShow => 'gray',
        };
    }

    /**
     * Statuses that still hold the instructor's time slot.
     *
     * @return array<int, self>
     */
    public static function slotHolding(): array
    {
        return [
            self::Pending,
            self::AwaitingPayment,
            self::Confirmed,
            self::InProgress,
        ];
    }

    /**
     * Statuses where the lesson can still be cancelled or moved.
     *
     * @return array<int, self>
     */
    public static function changeable(): array
    {
        return [
            self::Pending,
            self::AwaitingPayment,
            self::Confirmed,
        ];
    }

    /**
     * Whether the booking has reached a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Declined,
            self::Cancelled,
            self::PaymentTimeout,
            self::Expired,
            self::NoShow,
        ], true);
    }

    /**
     * Whether this status represents the instructor turning the request down.
     */
    public function isDeclined(): bool
    {
        return $this === self::Declined;
    }
}
