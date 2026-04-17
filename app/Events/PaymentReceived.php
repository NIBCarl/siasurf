<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('student.' . $this->payment->booking->student_id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.received';
    }

    public function broadcastWith(): array
    {
        return [
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'payment_method' => $this->payment->payment_method->value,
                'status' => $this->payment->status->value,
                'transaction_id' => $this->payment->transaction_id,
            ],
            'booking' => [
                'id' => $this->payment->booking->id,
                'date' => $this->payment->booking->date->format('Y-m-d'),
            ],
            'message' => 'Payment of ₱' . number_format($this->payment->amount, 2) . ' has been received.',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
