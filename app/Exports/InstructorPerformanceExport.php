<?php

namespace App\Exports;

use App\Models\InstructorProfile;
use App\Models\Booking;
use App\Enums\BookingStatus;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InstructorPerformanceExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    public function collection()
    {
        return InstructorProfile::with(['user'])
            ->get()
            ->map(function ($profile) {
                $instructor = $profile->user;
                
                // Get booking stats
                $totalBookings = Booking::forInstructor($instructor->id)->count();
                $completedBookings = Booking::forInstructor($instructor->id)
                    ->where('status', BookingStatus::Completed->value)
                    ->count();
                
                // Get revenue
                $revenue = \App\Models\Payment::whereHas('booking', function ($q) use ($instructor) {
                        $q->where('instructor_id', $instructor->id);
                    })
                    ->where('status', 'completed')
                    ->sum('amount');
                
                // Get average rating
                $avgRating = $instructor->reviewsAsInstructor()->avg('rating') ?? 0;
                
                return [
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'level' => $profile->level->value,
                    'status' => $profile->status->label(),
                    'total_bookings' => $totalBookings,
                    'completed_bookings' => $completedBookings,
                    'completion_rate' => $totalBookings > 0 
                        ? round(($completedBookings / $totalBookings) * 100, 1) . '%' 
                        : '0%',
                    'total_revenue' => '₱' . number_format($revenue, 2),
                    'avg_rating' => round($avgRating, 1),
                    'strike_count' => $profile->strike_count,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'Instructor Name',
            'Email',
            'Level',
            'Status',
            'Total Bookings',
            'Completed Bookings',
            'Completion Rate',
            'Total Revenue',
            'Average Rating',
            'Strike Count',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E7FF'],
                ],
            ],
        ];
    }
}
