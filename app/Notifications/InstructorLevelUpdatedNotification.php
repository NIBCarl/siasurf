<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorLevelUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $previousLevel,
        public int $newLevel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SISA Instructor Certification Level Was Updated')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("SISA updated your certification level from Level {$this->previousLevel} to Level {$this->newLevel}.")
            ->line('Your eligible surf spots, student capacity limits, and service offerings have been adjusted to match your updated level.')
            ->action('Review Profile', route('instructor.profile.edit'))
            ->line('SiaSurf Safety & Licensing');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_level_updated',
            'previous_level' => $this->previousLevel,
            'new_level' => $this->newLevel,
            'message' => "SISA updated your certification level from Level {$this->previousLevel} to Level {$this->newLevel}.",
            'url' => route('instructor.profile.edit'),
        ];
    }
}
