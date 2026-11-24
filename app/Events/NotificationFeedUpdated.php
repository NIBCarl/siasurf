<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationFeedUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private int $userId,
        private string $role,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return match ($this->role) {
            'admin' => [new PrivateChannel('admin.notifications')],
            'instructor' => [new PrivateChannel('instructor.'.$this->userId)],
            'student' => [new PrivateChannel('student.'.$this->userId)],
            default => [],
        };
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
