<?php

namespace App\Observers;

use App\Models\SafetyIncident;
use App\Models\InstructorProfile;
use App\Enums\InstructorStatus;
use Illuminate\Support\Facades\Mail;
use App\Mail\InstructorSuspended;

class SafetyIncidentObserver
{
    /**
     * Handle the SafetyIncident "created" event.
     */
    public function created(SafetyIncident $incident): void
    {
        $instructor = $incident->instructor;
        
        if (!$instructor || !$instructor->instructorProfile) {
            return;
        }

        $profile = $instructor->instructorProfile;
        
        // Calculate strikes based on severity
        $strikes = match($incident->severity->value) {
            'minor' => 1,
            'major' => 2,
            'critical' => 3,
            default => 1,
        };
        
        // Add strikes
        $profile->increment('strike_count', $strikes);
        
        // Check if should be suspended (3 or more strikes)
        if ($profile->strike_count >= 3) {
            $profile->update([
                'status' => InstructorStatus::Suspended,
                'suspended_until' => now()->addMonth(),
            ]);
            
            // Notify admin and instructor
            try {
                Mail::to(config('app.admin_email'))->send(new InstructorSuspended($instructor));
                $instructor->notify(new \App\Notifications\SuspensionNotification($incident));
            } catch (\Exception $e) {
                // Log error but don't stop execution
                \Log::error('Failed to send suspension notifications: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle the SafetyIncident "updated" event.
     */
    public function updated(SafetyIncident $incident): void
    {
        //
    }

    /**
     * Handle the SafetyIncident "deleted" event.
     */
    public function deleted(SafetyIncident $incident): void
    {
        // Optionally restore strikes if incident is deleted
    }
}