<?php

namespace App\Notifications;

use App\Models\SkillUpgradeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SkillUpgradeRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SkillUpgradeRequest $upgradeRequest)
    {
        $this->upgradeRequest = $upgradeRequest->loadMissing(['student', 'instructor', 'booking']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $instructorName = $this->upgradeRequest->instructor?->name ?? 'An instructor';
        $studentName = $this->upgradeRequest->student?->name ?? 'a student';
        $from = ucfirst((string) $this->upgradeRequest->current_level);
        $to = ucfirst((string) $this->upgradeRequest->requested_level);

        return [
            'type' => 'skill_upgrade_requested',
            'skill_upgrade_request_id' => $this->upgradeRequest->id,
            'booking_id' => $this->upgradeRequest->booking_id,
            'message' => "{$instructorName} recommended upgrading {$studentName} from {$from} to {$to}.",
            'url' => route('admin.skill-upgrades.index'),
        ];
    }
}
