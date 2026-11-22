<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Refund $refund;

    public function __construct(Refund $refund)
    {
        $this->refund = $refund->loadMissing(['booking']);
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('admin.notifications')];

        if ($this->refund->booking) {
            $channels[] = new PrivateChannel('student.' . $this->refund->booking->student_id);
            $channels[] = new PrivateChannel('instructor.' . $this->refund->booking->instructor_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'refund.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'refund' => [
                'id' => $this->refund->id,
                'booking_id' => $this->refund->booking_id,
                'status' => $this->refund->status?->value,
                'status_label' => $this->refund->status_label,
                'amount' => (float) $this->refund->amount,
                'service_fee_retained' => (float) $this->refund->service_fee_retained,
                'refund_percentage' => $this->refund->refund_percentage,
                'paymongo_refund_id' => $this->refund->paymongo_refund_id,
            ],
            'title' => $this->refund->status_label,
            'message' => sprintf(
                'Refund of ₱%s for booking #SURF-%s is %s.',
                number_format((float) $this->refund->amount, 2),
                str_pad((string) $this->refund->booking_id, 5, '0', STR_PAD_LEFT),
                strtolower($this->refund->status_label)
            ),
            'type' => match ($this->refund->status?->value) {
                'completed' => 'success',
                'failed' => 'error',
                'manual_required' => 'warning',
                default => 'info',
            },
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
