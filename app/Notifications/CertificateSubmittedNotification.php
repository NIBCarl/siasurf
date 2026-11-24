<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Alerts SISA administrators in-app when an instructor uploads new certificates
 * that require review.
 */
class CertificateSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $instructor,
        protected int $count,
    ) {}

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
        return [
            'type' => 'certificate_submitted',
            'instructor_id' => $this->instructor->id,
            'count' => $this->count,
            'message' => "{$this->instructor->name} uploaded {$this->count} certificate(s) pending SISA verification.",
            'url' => route('admin.instructors.show', $this->instructor->id),
        ];
    }
}
