<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\InstructorStatus;
use App\Enums\PaymentStatus;
use App\Enums\SuspensionStatus;
use App\Models\Booking;
use App\Models\InstructorFine;
use App\Models\InstructorProfile;
use App\Models\InstructorSuspension;
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
                'awaiting_reinstatement' => InstructorSuspension::where('status', SuspensionStatus::AwaitingReinstatement->value)->count(),
            ],
            'revenue' => [
                'total' => $this->formatCurrency($this->sumServiceFeeRevenue()),
                'this_month' => $this->formatCurrency(
                    $this->sumServiceFeeRevenue(now()->startOfMonth(), now()->endOfMonth())
                ),
                'service_fee_percent' => PricingService::serviceFeePercent(),
            ],
            'incidents' => [
                'total' => SafetyIncident::count(),
                'critical' => SafetyIncident::where('status', IncidentStatus::Confirmed->value)
                    ->where(function ($q) {
                        $q->where('final_outcome', IncidentOutcome::Critical->value)
                            ->orWhere('handled_externally', true);
                    })
                    ->count(),
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

        if ($period === 'yearly') {
            $anchorYear = now()->startOfYear();

            for ($i = 4; $i >= 0; $i--) {
                $yearDate = $anchorYear->copy()->subYears($i);

                $revenue = $this->sumServiceFeeRevenue(
                    $yearDate->copy()->startOfYear(),
                    $yearDate->copy()->endOfYear()
                );

                $labels[] = $yearDate->format('Y');
                $data[] = round($revenue, 2);
            }

            return [
                'labels' => $labels,
                'data' => $data,
            ];
        }

        $anchor = now()->startOfMonth();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $anchor->copy()->subMonths($i);

            $revenue = $this->sumServiceFeeRevenue(
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth()
            );

            $labels[] = $date->format('M Y');
            $data[] = round($revenue, 2);
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Real platform insights for Admin Analytics (never hardcoded placeholders).
     *
     * @return array<string, mixed>
     */
    public function getAnalyticsSummary(): array
    {
        $thisMonthRevenue = $this->sumServiceFeeRevenue(now()->startOfMonth(), now()->endOfMonth());
        $lastMonthRevenue = $this->sumServiceFeeRevenue(
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth()
        );

        $revenueGrowthPct = null;
        if ($lastMonthRevenue > 0) {
            $revenueGrowthPct = round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1);
        } elseif ($thisMonthRevenue > 0) {
            $revenueGrowthPct = 100.0;
        }

        $bookings = Booking::query()
            ->whereNotNull('date')
            ->get(['skill_level', 'student_age', 'date']);

        $totalBookings = $bookings->count();
        $skillCounts = ['beginner' => 0, 'intermediate' => 0, 'advanced' => 0];
        $ageBuckets = ['5–12' => 0, '13–17' => 0, '18–24' => 0, '25–34' => 0, '35+' => 0];
        $dayCounts = [];
        $agedCount = 0;

        foreach ($bookings as $booking) {
            $skill = $booking->skill_level instanceof \BackedEnum
                ? $booking->skill_level->value
                : (string) $booking->skill_level;

            if (isset($skillCounts[$skill])) {
                $skillCounts[$skill]++;
            }

            $age = (int) ($booking->student_age ?? 0);
            if ($age > 0) {
                $agedCount++;
                if ($age <= 12) {
                    $ageBuckets['5–12']++;
                } elseif ($age <= 17) {
                    $ageBuckets['13–17']++;
                } elseif ($age <= 24) {
                    $ageBuckets['18–24']++;
                } elseif ($age <= 34) {
                    $ageBuckets['25–34']++;
                } else {
                    $ageBuckets['35+']++;
                }
            }

            if ($booking->date) {
                $dayName = $booking->date->format('l');
                $dayCounts[$dayName] = ($dayCounts[$dayName] ?? 0) + 1;
            }
        }

        $topSkillKey = 'beginner';
        $topSkillCount = 0;
        foreach ($skillCounts as $key => $count) {
            if ($count > $topSkillCount) {
                $topSkillKey = $key;
                $topSkillCount = $count;
            }
        }

        $dominantSkillPct = $totalBookings > 0 ? round(($topSkillCount / $totalBookings) * 100) : 0;
        $beginnerSharePct = $totalBookings > 0 ? round(($skillCounts['beginner'] / $totalBookings) * 100) : 0;

        $peakAgeGroup = '—';
        $peakAgeCount = 0;
        foreach ($ageBuckets as $bucket => $count) {
            if ($count > $peakAgeCount) {
                $peakAgeGroup = $bucket;
                $peakAgeCount = $count;
            }
        }
        $peakAgePct = $agedCount > 0 ? round(($peakAgeCount / $agedCount) * 100) : 0;

        arsort($dayCounts);
        $busiestDay = ! empty($dayCounts) ? (string) array_key_first($dayCounts) : '—';
        $busiestDayCount = ! empty($dayCounts) ? (int) reset($dayCounts) : 0;
        $busiestDayPct = $totalBookings > 0 ? round(($busiestDayCount / $totalBookings) * 100) : 0;

        return [
            'service_fee_percent' => PricingService::serviceFeePercent(),
            'revenue_growth_pct' => $revenueGrowthPct,
            'total_bookings' => $totalBookings,
            'beginner_share_pct' => $beginnerSharePct,
            'dominant_skill_label' => $totalBookings > 0 ? ucfirst($topSkillKey) : 'No bookings',
            'dominant_skill_pct' => $dominantSkillPct,
            'peak_age_group' => $peakAgeGroup,
            'peak_age_pct' => $peakAgePct,
            'peak_age_note' => $agedCount > 0
                ? "Represents {$peakAgePct}% of {$agedCount} recorded booking" . ($agedCount === 1 ? '' : 's') . '.'
                : 'Age demographics populate once bookings are recorded.',
            'busiest_day' => $busiestDay,
            'busiest_day_pct' => $busiestDayPct,
            'busiest_day_note' => $totalBookings > 0
                ? "{$busiestDayCount} of {$totalBookings} booking" . ($totalBookings === 1 ? '' : 's') . " ({$busiestDayPct}%)."
                : 'Day-of-week trends populate once bookings are recorded.',
        ];
    }

    /**
     * Sum the non-refundable tourist service fee collected by the platform
     * across settled and refunded payments (BR-PRC-03, BR-CXL-05).
     */
    private function sumServiceFeeRevenue(?Carbon $start = null, ?Carbon $end = null): float
    {
        $collectedStatuses = array_map(
            fn (PaymentStatus $status) => $status->value,
            PaymentStatus::serviceFeeCollected()
        );

        $query = Payment::query()
            ->leftJoin('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->whereIn('payments.status', $collectedStatuses);

        if ($start !== null && $end !== null) {
            $query->whereRaw(
                'COALESCE(payments.paid_at, payments.created_at) BETWEEN ? AND ?',
                [$start->toDateTimeString(), $end->toDateTimeString()]
            );
        }

        return round((float) $query->sum(DB::raw(
            'CASE WHEN payments.service_fee_amount > 0 THEN payments.service_fee_amount ELSE COALESCE(bookings.service_fee_amount, 0) END'
        )), 2);
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
        app(AvailabilityService::class)->releaseExpiredCheckoutHolds($user->id);

        $profile = $user->instructorProfile;
        $activeBookings = $user->bookingsAsInstructor()
            ->submittedToInstructor()
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::InProgress])
            ->get();

        $completedBookings = $user->bookingsAsInstructor()
            ->where('status', BookingStatus::Completed)
            ->get();

        $retainedCancelledBookings = $user->bookingsAsInstructor()
            ->whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->where('instructor_retained_amount', '>', 0)
            ->get();

        $earningsTotal = $completedBookings->where('payment_status', PaymentStatus::Completed)
            ->sum(fn (Booking $b) => $b->effectiveLessonPrice())
            + $retainedCancelledBookings->sum(fn (Booking $b) => (float) $b->instructor_retained_amount);

        $earningsThisMonth = $completedBookings->where('payment_status', PaymentStatus::Completed)
            ->where('completed_at', '>=', now()->startOfMonth())
            ->sum(fn (Booking $b) => $b->effectiveLessonPrice())
            + $retainedCancelledBookings
                ->filter(fn (Booking $b) => ($b->cancelled_at ?? $b->updated_at)?->greaterThanOrEqualTo(now()->startOfMonth()))
                ->sum(fn (Booking $b) => (float) $b->instructor_retained_amount);

        $ongoingSession = $activeBookings->where('status', BookingStatus::InProgress)->first();
        
        $upcomingBookings = $activeBookings->where('status', BookingStatus::Confirmed)
            ->where('date', '>=', now()->format('Y-m-d'))
            ->sortBy('date')
            ->take(5);

        $pendingRequests = $activeBookings->where('status', BookingStatus::Pending)->count();
        $reservedHolds = $user->bookingsAsInstructor()->inStudentCheckout()->count();

        // FR 1.9.3 - the live request queue, inside the 2 hour response window.
        $pendingRequestList = $activeBookings->where('status', BookingStatus::Pending)
            ->filter(fn (Booking $booking) => !$booking->acceptDeadlinePassed())
            ->sortBy('accept_deadline_at')
            ->take(5)
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'student_name' => $booking->student->name,
                'date' => $booking->date->format('M d, Y'),
                'time_period' => $booking->time_period->value,
                // Exact range, so the queue card shows the time immediately.
                'start_time' => $booking->start_time?->format('H:i'),
                'end_time' => $booking->end_time?->format('H:i'),
                'skill_level' => $booking->skill_level->value,
                'accept_time_remaining' => $booking->getAcceptTimeRemaining(),
            ])
            ->values()
            ->toArray();

        // FR 1.10.1 - lessons that still need an attendance record and have reached their scheduled start.
        $attendanceDue = $user->bookingsAsInstructor()
            ->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
            ])
            ->whereNull('attendance_status')
            ->get()
            ->filter(fn (Booking $booking) => $booking->canMarkAttendance())
            ->count();

        $recentReviews = $user->reviewsAsInstructor()
            ->with('student')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $dueFines = $user->instructorFines()
            ->due()
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (InstructorFine $fine) => [
                'id' => $fine->id,
                'amount' => (float) $fine->amount,
                'reason' => $fine->reason,
                'issued_at' => $fine->issued_at?->format('M d, Y'),
            ])
            ->values()
            ->toArray();

        return [
            'stats' => [
                'total_bookings' => $user->bookingsAsInstructor()->count(),
                'completed_lessons' => $completedBookings->count(),
                'pending_requests' => $pendingRequests,
                'reserved_holds' => $reservedHolds,
                'total_earnings' => $this->formatCurrency($earningsTotal),
                'this_month_earnings' => $this->formatCurrency($earningsThisMonth),
            ],
            'profile' => [
                'status' => $profile->status->value,
                'strike_count' => $profile->strike_count,
                'average_rating' => round($user->reviewsAsInstructor()->avg('rating') ?? 0, 1),
                'level' => $profile->level->value,
            ],
            'due_fines' => $dueFines,
            'activities' => [
                'ongoing_session' => $ongoingSession ? [
                    'id' => $ongoingSession->id,
                    'student_name' => $ongoingSession->student->name,
                    'spot_name' => $ongoingSession->surfSpot->name,
                    'started_at' => $ongoingSession->started_at,
                    'duration_hours' => $ongoingSession->duration_hours,
                ] : null,
                'upcoming_bookings' => $upcomingBookings->map(fn($b) => [
                    'id' => $b->id,
                    'student_name' => $b->student->name,
                    'date' => $b->date->format('M d, Y'),
                    'time_period' => $b->time_period->value,
                    'start_time' => $b->start_time?->format('H:i'),
                    'end_time' => $b->end_time?->format('H:i'),
                    'spot_name' => $b->surfSpot->name,
                ])->values()->toArray(),
                'recent_reviews' => $recentReviews->map(fn($r) => [
                    'id' => $r->id,
                    'student_name' => $r->student->name,
                    'rating' => $r->rating,
                    'comment' => $r->comment,
                    'date' => $r->created_at->diffForHumans(),
                ])->toArray(),
                // FR 1.9.3 / 1.10.1 - action items for the instructor.
                'pending_request_list' => $pendingRequestList,
                'attendance_due' => $attendanceDue,
            ],
            // FR 1.16 - daily ledger summary, cash fees owed, and net payable.
            'earnings' => app(EarningsLedgerService::class)->getInstructorSummary($user),
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
