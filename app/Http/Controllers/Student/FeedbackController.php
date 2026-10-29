<?php

namespace App\Http\Controllers\Student;

use App\Enums\BookingStatus;
use App\Enums\FeedbackCategory;
use App\Enums\IncidentSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Booking;
use App\Models\SafetyIncident;
use App\Services\FeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function create(Request $request, Booking $booking): Response|RedirectResponse
    {
        $this->ensureOwnCompletedBooking($booking);

        $booking->load(['instructor', 'surfSpot']);

        $review = $booking->review;
        $report = SafetyIncident::where('booking_id', $booking->id)
            ->where('reported_by', $request->user()->id)
            ->where('source', IncidentSource::StudentFeedback->value)
            ->latest()
            ->first();

        if ($review && $report) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('info', 'You have already submitted feedback for this booking.');
        }

        return Inertia::render('Student/Feedback/Create', [
            'booking' => $booking,
            'review' => $review,
            'report' => $report,
            'canReview' => $review === null,
            'canReport' => $report === null,
            'positiveTags' => FeedbackCategory::positiveOptions(),
            'concernCategories' => FeedbackCategory::concernOptions(),
        ]);
    }

    public function store(StoreFeedbackRequest $request, Booking $booking, FeedbackService $service): RedirectResponse
    {
        $this->ensureOwnCompletedBooking($booking);

        $data = $request->validated();
        $data['photo'] = $request->file('photo');
        $data['evidence'] = $request->file('evidence');

        $result = $service->submit($booking, $request->user(), $data);

        $message = match (true) {
            $result['review'] !== null && $result['incident'] !== null
                => 'Your public review was published and your private concern was sent to SISA for review.',
            $result['review'] !== null
                => 'Your public review was published.',
            default
                => 'Your private concern was sent to SISA for review. No strike is applied until SISA confirms it.',
        };

        return redirect()->route('student.bookings.show', $booking)->with('success', $message);
    }

    private function ensureOwnCompletedBooking(Booking $booking): void
    {
        if ($booking->student_id !== auth()->id()) {
            abort(403);
        }

        if ($booking->status !== BookingStatus::Completed) {
            abort(403, 'Feedback is only available after the lesson is completed.');
        }
    }
}
