<?php

namespace App\Services;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Events\IncidentReported;
use App\Models\Booking;
use App\Models\Review;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\NewIncidentReportNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedbackService
{
    /**
     * Submit a public review and/or a private concern for a completed booking.
     *
     * The public review and the private report are separate records with
     * different visibility. A concern starts as pending and cannot add a strike
     * until SISA confirms it.
     *
     * @return array{review: ?Review, incident: ?SafetyIncident}
     */
    public function submit(Booking $booking, User $student, array $data): array
    {
        $review = null;
        $incident = null;

        DB::transaction(function () use ($booking, $student, $data, &$review, &$incident) {
            if ($data['submit_review'] ?? false) {
                if ($booking->review()->exists()) {
                    throw ValidationException::withMessages([
                        'review' => 'A public review already exists for this booking.',
                    ]);
                }

                $photoPath = null;

                if (! empty($data['photo'])) {
                    // Rendered in public review cards; the public disk backs /storage.
                    $photoPath = $data['photo']->store('reviews', 'public');
                }

                $review = Review::create([
                    'booking_id' => $booking->id,
                    'student_id' => $student->id,
                    'instructor_id' => $booking->instructor_id,
                    'rating' => $data['rating'] ?? null,
                    'comment' => $data['comment'],
                    'feedback_tags' => array_values(array_unique($data['feedback_tags'] ?? [])),
                    'photo_path' => $photoPath,
                ]);
            }

            if ($data['submit_concern'] ?? false) {
                $existing = SafetyIncident::where('booking_id', $booking->id)
                    ->where('reported_by', $student->id)
                    ->where('source', IncidentSource::StudentFeedback->value)
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'concern' => 'A concern has already been submitted for this booking.',
                    ]);
                }

                $category = FeedbackCategory::tryFrom((string) ($data['concern_category'] ?? ''));

                if (! $category || ! $category->isConcern()) {
                    throw ValidationException::withMessages([
                        'concern_category' => 'Choose a valid concern category.',
                    ]);
                }

                $evidencePath = null;

                if (! empty($data['evidence'])) {
                    $evidencePath = $data['evidence']->store('feedback/evidence', 'local');
                }

                $type = IncidentType::RuleViolation;

                if (! empty($data['injury_occurred'])) {
                    $type = IncidentType::Injury;
                } elseif (! empty($data['near_miss'])) {
                    $type = IncidentType::NearMiss;
                }

                $incident = SafetyIncident::create([
                    'booking_id' => $booking->id,
                    'review_id' => $review?->id,
                    'instructor_id' => $booking->instructor_id,
                    'reported_by' => $student->id,
                    'source' => IncidentSource::StudentFeedback,
                    'status' => IncidentStatus::Pending,
                    'category' => $category->value,
                    'type' => $type,
                    'severity' => $category->suggestedSeverity(),
                    'suggested_severity' => $category->suggestedSeverity(),
                    'strike_weight' => 0,
                    'description' => $data['concern_details'],
                    'evidence_path' => $evidencePath,
                    'location' => $booking->surfSpot?->name,
                ]);
            }
        });

        if ($incident) {
            $incident = $incident->fresh(['instructor', 'booking', 'reporter', 'review']);

            try {
                event(new IncidentReported($incident));
            } catch (\Throwable $exception) {
                \Log::warning('Failed to broadcast new incident report.', [
                    'incident_id' => $incident->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            User::where('role', 'admin')->get()->each(function (User $admin) use ($incident) {
                try {
                    $admin->notify(new NewIncidentReportNotification($incident));
                } catch (\Throwable $exception) {
                    \Log::warning('Failed to notify admin about incident report.', [
                        'incident_id' => $incident->id,
                        'admin_id' => $admin->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }

        return [
            'review' => $review?->fresh(),
            'incident' => $incident,
        ];
    }
}
