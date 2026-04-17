<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\PaymentStatus;
use App\Enums\IncidentSeverity;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\SafetyIncident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Get overview statistics for dashboard cards
     */
    public function getOverviewStats(): array
    {
        $today = now()->format('Y-m-d');

        return [
            'total_bookings' => [
                'value' => Booking::count(),
                'today' => Booking::whereDate('created_at', $today)->count(),
                'trend' => $this->calculateTrend(Booking::class),
            ],
            'active_instructors' => [
                'value' => InstructorProfile::where('status', InstructorStatus::Active->value)->count(),
                'pending_verification' => InstructorProfile::where('status', InstructorStatus::PendingVerification->value)->count(),
            ],
            'revenue' => [
                'total' => $this->formatCurrency(Payment::where('status', PaymentStatus::Completed->value)->sum('amount')),
                'this_month' => $this->formatCurrency(
                    Payment::where('status', PaymentStatus::Completed->value)
                        ->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                        ->sum('amount')
                ),
            ],
            'incidents' => [
                'total' => SafetyIncident::count(),
                'critical' => SafetyIncident::where('severity', IncidentSeverity::Critical->value)->count(),
                'this_month' => SafetyIncident::whereMonth('created_at', now()->month)->count(),
            ],
            'ongoing_sessions' => [
                'value' => Booking::where('status', BookingStatus::InProgress->value)->count(),
            ],
        ];
    }

    /**
     * Get revenue data for charts
     */
    public function getRevenueData(string $period = 'monthly', int $months = 12): array
    {
        $data = [];
        $labels = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            
            $revenue = Payment::where('status', PaymentStatus::Completed->value)
                ->whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year)
                ->sum('amount');

            $labels[] = $date->format('M Y');
            $data[] = round($revenue, 2);
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get booking trends data
     */
    public function getBookingTrends(int $days = 30): array
    {
        $data = [];
        $labels = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            
            $count = Booking::whereDate('created_at', $date)->count();

            $labels[] = $date->format('M d');
            $data[] = $count;
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get popular surf spots
     */
    public function getPopularSurfSpots(int $limit = 5): array
    {
        return Booking::select('surf_spot_id', DB::raw('count(*) as total'))
            ->with('surfSpot')
            ->groupBy('surf_spot_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->surfSpot->name ?? 'Unknown',
                    'total_bookings' => $item->total,
                ];
            })
            ->toArray();
    }

    /**
     * Get top performing instructors
     */
    public function getTopInstructors(int $limit = 10): array
    {
        return InstructorProfile::whereHas('user', function ($query) {
                $query->where('role', 'instructor');
            })
            ->with(['user', 'user.bookingsAsInstructor'])
            ->get()
            ->map(function ($profile) {
                $completedBookings = $profile->user->bookingsAsInstructor
                    ->where('status', BookingStatus::Completed->value)
                    ->count();

                $avgRating = $profile->user->reviewsAsInstructor()
                    ->avg('rating') ?? 0;

                return [
                    'id' => $profile->user_id,
                    'name' => $profile->user->name,
                    'level' => $profile->level,
                    'completed_bookings' => $completedBookings,
                    'status' => $profile->status->value,
                ];
            })
            ->sortByDesc('completed_bookings')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Get recent activity feed
     */
    public function getRecentActivity(int $limit = 20): array
    {
        $activities = [];

        // Recent bookings
        $bookings = Booking::with(['student', 'instructor'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        foreach ($bookings as $booking) {
            $activities[] = [
                'type' => 'booking_created',
                'message' => "New booking by {$booking->student->name}",
                'details' => "Lesson with {$booking->instructor->name} on {$booking->date->format('M d, Y')}",
                'time' => $booking->created_at,
                'icon' => 'calendar',
                'color' => 'blue',
            ];
        }

        // Recent incidents
        $incidents = SafetyIncident::with(['instructor'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        foreach ($incidents as $incident) {
            $activities[] = [
                'type' => 'incident_reported',
                'message' => "Safety incident reported",
                'details' => $incident->type->label() . ' - ' . $incident->severity->label(),
                'time' => $incident->created_at,
                'icon' => 'alert',
                'color' => 'red',
            ];
        }

        // Recent reviews
        $reviews = \App\Models\Review::with(['student', 'instructor'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        foreach ($reviews as $review) {
            $activities[] = [
                'type' => 'review_submitted',
                'message' => "New review from {$review->student->name}",
                'details' => "{$review->rating} stars for {$review->instructor->name}",
                'time' => $review->created_at,
                'icon' => 'star',
                'color' => 'yellow',
            ];
        }

        // Session starts
        $sessions = Booking::with(['student', 'instructor'])
            ->where('status', BookingStatus::InProgress->value)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        foreach ($sessions as $session) {
            $activities[] = [
                'type' => 'session_started',
                'message' => "Session started with {$session->student->name}",
                'details' => "Instructor: {$session->instructor->name} at {$session->surfSpot->name}",
                'time' => $session->started_at,
                'icon' => 'play',
                'color' => 'green',
            ];
        }

        // Sort by time and limit
        usort($activities, fn($a, $b) => $b['time'] <=> $a['time']);

        return array_slice($activities, 0, $limit);
    }

    /**
     * Get list of currently ongoing sessions
     */
    public function getOngoingSessions(int $limit = 10): array
    {
        return Booking::with(['student', 'instructor', 'surfSpot'])
            ->where('status', BookingStatus::InProgress->value)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'student_name' => $booking->student->name,
                    'instructor_name' => $booking->instructor->name,
                    'spot_name' => $booking->surfSpot->name,
                    'started_at' => $booking->started_at,
                    'duration_hours' => $booking->duration_hours,
                    'time_remaining' => $booking->getSessionTimeRemaining(),
                ];
            })
            ->toArray();
    }

    /**
     * Get safety statistics
     */
    public function getSafetyStats(): array
    {
        $stats = [
            'by_severity' => [],
            'by_type' => [],
            'recent_trend' => [],
        ];

        // By severity
        foreach (IncidentSeverity::cases() as $severity) {
            $stats['by_severity'][$severity->value] = SafetyIncident::where('severity', $severity->value)->count();
        }

        // By type
        foreach (\App\Enums\IncidentType::cases() as $type) {
            $stats['by_type'][$type->value] = SafetyIncident::where('type', $type->value)->count();
        }

        // Recent trend (last 30 days)
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $stats['recent_trend'][] = [
                'date' => $date->format('M d'),
                'count' => SafetyIncident::whereDate('created_at', $date)->count(),
            ];
        }

        return $stats;
    }

    /**
     * Get booking status distribution
     */
    public function getBookingStatusDistribution(): array
    {
        $distribution = [];

        foreach (BookingStatus::cases() as $status) {
            $distribution[$status->value] = Booking::where('status', $status->value)->count();
        }

        return $distribution;
    }

    /**
     * Get dashboard data specifically for an instructor
     */
    public function getInstructorDashboardData(User $user): array
    {
        $profile = $user->instructorProfile;
        $activeBookings = $user->bookingsAsInstructor()
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::InProgress])
            ->get();

        $completedBookings = $user->bookingsAsInstructor()
            ->where('status', BookingStatus::Completed)
            ->get();

        $earningsTotal = $completedBookings->where('payment_status', PaymentStatus::Completed)->sum('total_amount');
        $earningsThisMonth = $completedBookings->where('payment_status', PaymentStatus::Completed)
            ->where('completed_at', '>=', now()->startOfMonth())
            ->sum('total_amount');

        $ongoingSession = $activeBookings->where('status', BookingStatus::InProgress)->first();
        
        $upcomingBookings = $activeBookings->where('status', BookingStatus::Confirmed)
            ->where('date', '>=', now()->format('Y-m-d'))
            ->sortBy('date')
            ->take(5);

        $pendingRequests = $activeBookings->where('status', BookingStatus::Pending)->count();

        $recentReviews = $user->reviewsAsInstructor()
            ->with('student')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        return [
            'stats' => [
                'total_bookings' => $user->bookingsAsInstructor()->count(),
                'completed_lessons' => $completedBookings->count(),
                'pending_requests' => $pendingRequests,
                'total_earnings' => $this->formatCurrency($earningsTotal),
                'this_month_earnings' => $this->formatCurrency($earningsThisMonth),
            ],
            'profile' => [
                'status' => $profile->status->value,
                'strike_count' => $profile->strike_count,
                'average_rating' => round($user->reviewsAsInstructor()->avg('rating') ?? 0, 1),
                'level' => $profile->level->value,
            ],
            'activities' => [
                'ongoing_session' => $ongoingSession ? [
                    'id' => $ongoingSession->id,
                    'student_name' => $ongoingSession->student->name,
                    'spot_name' => $ongoingSession->surfSpot->name,
                    'started_at' => $ongoingSession->started_at,
                    'duration_hours' => $ongoingSession->duration_hours,
                    'time_remaining' => $ongoingSession->getSessionTimeRemaining(),
                ] : null,
                'upcoming_bookings' => $upcomingBookings->map(fn($b) => [
                    'id' => $b->id,
                    'student_name' => $b->student->name,
                    'date' => $b->date->format('M d, Y'),
                    'time_period' => $b->time_period->value,
                    'spot_name' => $b->surfSpot->name,
                ])->values()->toArray(),
                'recent_reviews' => $recentReviews->map(fn($r) => [
                    'id' => $r->id,
                    'student_name' => $r->student->name,
                    'rating' => $r->rating,
                    'comment' => $r->comment,
                    'date' => $r->created_at->diffForHumans(),
                ])->toArray(),
            ],
        ];
    }

    /**
     * Calculate trend percentage
     */
    private function calculateTrend(string $model): int
    {
        $thisMonth = $model::whereMonth('created_at', now()->month)->count();
        $lastMonth = $model::whereMonth('created_at', now()->subMonth()->month)->count();

        if ($lastMonth === 0) {
            return $thisMonth > 0 ? 100 : 0;
        }

        return round((($thisMonth - $lastMonth) / $lastMonth) * 100);
    }

    /**
     * Format currency for display
     */
    private function formatCurrency(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }
}
