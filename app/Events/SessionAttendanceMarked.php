<?php

namespace App\Events;

use App\Enums\AttendanceStatus;
use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FR 1.10.1 - the instructor marked whether the student showed up. The student
 * and SISA both get to see the attendance record in real time.
 */
class SessionAttendanceMarked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public AttendanceStatus $attendance;

    public function __construct(Booking $booking, AttendanceStatus $attendance)
    {
        $this->booking = $booking;
        $this->attendance = $attendance;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('student.' . $this->booking->student_id),
            new PrivateChannel('instructor.' . $this->booking->instructor_id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.attendance';
    }

    public function broadcastWith(): array
    {
        $studentName = $this->booking->student?->name ?? 'The student';

        return [
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date instanceof \DateTimeInterface
                    ? $this->booking->date->format('Y-m-d')
                    : (string) $this->booking->date,
                'attendance_status' => $this->attendance->value,
                'attendance_label' => $this->attendance->label(),
                'attendance_marked_at' => $this->booking->attendance_marked_at instanceof \DateTimeInterface
                    ? $this->booking->attendance_marked_at->toIso8601String()
                    : null,
            ],
            'student' => [
                'id' => $this->booking->student_id,
                'name' => $studentName,
            ],
            'message' => "Attendance recorded for {$studentName}: {$this->attendance->label()}.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}