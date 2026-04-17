<?php

namespace App\Notifications;

use App\Models\InstructorProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorVerified extends Notification
{
    use Queueable;

    protected $profile;

    /**
     * Create a new notification instance.
     */
    public function __construct(InstructorProfile $profile)
    {
        $this->profile = $profile;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $level = $this->profile->level;
        $levelValue = is_object($level) && isset($level->value) ? $level->value : $level;

        return (new MailMessage)
            ->subject('Your SiaSurf Instructor Account Has Been Verified!')
            ->greeting('Congratulations, ' . $notifiable->name . '!')
            ->line('Your instructor account has been verified by SISA admin.')
            ->line('You are now a Level ' . $levelValue . ' certified instructor.')
            ->line('Your QR code has been generated and is available in your profile.')
            ->action('View Profile', url('/instructor/profile'))
            ->line('You can now start accepting bookings from students.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_verified',
            'profile_id' => $this->profile->id,
            'level' => $this->profile->level->value,
            'message' => 'Your instructor account has been verified!',
        ];
    }
}