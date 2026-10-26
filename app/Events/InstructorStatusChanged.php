<?php

namespace App\Events;

use App\Enums\InstructorStatus;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstructorStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $instructor;

    public InstructorStatus $oldStatus;

    public InstructorStatus $newStatus;

    public ?string $reason;

    public function __construct(User $instructor, InstructorStatus $oldStatus, InstructorStatus $newStatus, ?string $reason = null)
    {
        $this->instructor = $instructor;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('instructor.'.$this->instructor->id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'instructor.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'instructor' => [
                'id' => $this->instructor->id,
                'name' => $this->instructor->name,
                'email' => $this->instructor->email,
            ],
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
            'old_status_label' => $this->oldStatus->label(),
            'new_status_label' => $this->newStatus->label(),
            'reason' => $this->reason,
            'message' => $this->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function getMessage(): string
    {
        return match ($this->newStatus) {
            InstructorStatus::Active => 'Congratulations! Your instructor account is active and visible to students.',
            InstructorStatus::Suspended => 'Your instructor account has been suspended. Please contact admin for details.',
            InstructorStatus::Inactive => 'Your instructor profile is currently inactive and hidden from search.',
            default => "Your instructor status has been updated to {$this->newStatus->label()}.",
        };
    }
}
