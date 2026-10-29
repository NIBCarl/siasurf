<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The methodology defines a standard 2-hour surf lesson. Session length is
 * derived from each instructor's saved slot duration, but every row was stored
 * as 60 minutes because the old form hard-coded (and only accepted) 60. That
 * made every booking 1 hour even after the code was corrected.
 *
 * This promotes the legacy 60-minute default to the 2-hour standard and brings
 * live bookings' duration/end time (and unpaid totals) in line with it.
 */
return new class extends Migration
{
    private const STANDARD_MINUTES = 120;

    public function up(): void
    {
        // Legacy default -> standard lesson length.
        DB::table('availabilities')
            ->where('slot_duration_minutes', 60)
            ->update(['slot_duration_minutes' => self::STANDARD_MINUTES]);

        // Live (not finished) bookings: align duration and end time.
        $liveStatuses = ['pending', 'awaiting_payment', 'confirmed', 'in_progress'];

        DB::table('bookings')
            ->whereIn('status', $liveStatuses)
            ->whereNotNull('start_time')
            ->orderBy('id')
            ->get()
            ->each(function ($booking) {
                $start = Carbon::parse($booking->start_time);
                $update = [
                    'duration_hours' => (int) (self::STANDARD_MINUTES / 60),
                    'end_time' => $start->copy()->addMinutes(self::STANDARD_MINUTES)->format('H:i:s'),
                ];

                // Only re-price lessons that have not been paid for yet, so
                // historical payments are never rewritten.
                if (in_array($booking->status, ['pending', 'awaiting_payment'], true)) {
                    $rate = DB::table('instructor_profiles')
                        ->where('user_id', $booking->instructor_id)
                        ->value('rate_per_hour');

                    if ($rate !== null) {
                        $update['total_amount'] = round(
                            (float) $rate
                            * (self::STANDARD_MINUTES / 60)
                            * max(1, (int) $booking->student_count),
                            2
                        );
                    }
                }

                DB::table('bookings')->where('id', $booking->id)->update($update);
            });
    }

    public function down(): void
    {
        DB::table('availabilities')
            ->where('slot_duration_minutes', self::STANDARD_MINUTES)
            ->update(['slot_duration_minutes' => 60]);

        $liveStatuses = ['pending', 'awaiting_payment', 'confirmed', 'in_progress'];

        DB::table('bookings')
            ->whereIn('status', $liveStatuses)
            ->whereNotNull('start_time')
            ->orderBy('id')
            ->get()
            ->each(function ($booking) {
                $start = Carbon::parse($booking->start_time);

                DB::table('bookings')->where('id', $booking->id)->update([
                    'duration_hours' => 1,
                    'end_time' => $start->copy()->addHour()->format('H:i:s'),
                ]);
            });
    }
};
