<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Alerts SISA administrators in-app whenever a new user registers.
 * Database-only by design so routine signups do not spam admin inboxes.
 */
class NewUserRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected User $registeredUser) {}

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
        $isInstructor = $this->registeredUser->isInstructor();
        $roleLabel = $isInstructor ? 'instructor' : 'student';

        return [
            'type' => 'new_user_registered',
            'user_id' => $this->registeredUser->id,
            'user_role' => $this->registeredUser->role,
            'message' => "New {$roleLabel} registered: {$this->registeredUser->name} ({$this->registeredUser->email}).",
            'url' => $isInstructor
                ? route('admin.instructors.show', $this->registeredUser->id)
                : route('admin.students.show', $this->registeredUser->id),
        ];
    }
}
