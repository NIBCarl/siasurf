<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['booking_id', 'occurred_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('last_event_at')->nullable()->index()->after('updated_at');
        });

        $now = now()->toDateTimeString();

        DB::table('bookings')
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($now) {
                $bookingIds = $bookings->pluck('id')->all();
                $payments = DB::table('payments')
                    ->whereIn('booking_id', $bookingIds)
                    ->get()
                    ->keyBy('booking_id');

                $eventsToInsert = [];

                foreach ($bookings as $booking) {
                    $payment = $payments->get($booking->id);
                    $timestamps = array_filter([
                        $booking->created_at,
                        $booking->accepted_at,
                        $booking->rescheduled_by_instructor_at,
                        $booking->completed_at,
                        $booking->cancelled_at,
                        $payment?->paid_at,
                    ]);

                    $lastEventAt = $booking->created_at ?? $now;
                    foreach ($timestamps as $ts) {
                        if ($ts > $lastEventAt) {
                            $lastEventAt = $ts;
                        }
                    }

                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->update(['last_event_at' => $lastEventAt]);

                    $createdAt = $booking->created_at ?? $now;
                    $eventsToInsert[] = [
                        'booking_id' => $booking->id,
                        'actor_id' => $booking->student_id,
                        'event_type' => 'booking_created',
                        'title' => 'Booking Created',
                        'description' => 'Booking submitted for ' . $booking->date . '.',
                        'metadata' => json_encode(['date' => $booking->date]),
                        'occurred_at' => $createdAt,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    if ($payment && $payment->status === 'completed') {
                        $paidAt = $payment->paid_at ?? $createdAt;
                        $eventsToInsert[] = [
                            'booking_id' => $booking->id,
                            'actor_id' => $payment->receipt_verified_by ?? $booking->student_id,
                            'event_type' => 'payment_completed',
                            'title' => 'Payment Completed',
                            'description' => strtoupper((string) $payment->payment_method) . ' payment of ₱' . number_format((float) $payment->amount, 2) . ' completed.',
                            'metadata' => json_encode([
                                'amount' => (float) $payment->amount,
                                'payment_method' => $payment->payment_method,
                            ]),
                            'occurred_at' => $paidAt,
                            'created_at' => $paidAt,
                            'updated_at' => $paidAt,
                        ];
                    }

                    if ($booking->accepted_at || in_array($booking->status, ['confirmed', 'in_progress', 'completed'], true)) {
                        $confirmedAt = $booking->accepted_at ?? $createdAt;
                        $eventsToInsert[] = [
                            'booking_id' => $booking->id,
                            'actor_id' => $booking->instructor_id,
                            'event_type' => 'booking_confirmed',
                            'title' => 'Booking Confirmed',
                            'description' => 'Booking confirmed for ' . $booking->date . '.',
                            'metadata' => json_encode(['date' => $booking->date]),
                            'occurred_at' => $confirmedAt,
                            'created_at' => $confirmedAt,
                            'updated_at' => $confirmedAt,
                        ];
                    }

                    if ($booking->rescheduled_by_instructor_at) {
                        $rescheduledAt = $booking->rescheduled_by_instructor_at;
                        $eventsToInsert[] = [
                            'booking_id' => $booking->id,
                            'actor_id' => $booking->instructor_id,
                            'event_type' => 'booking_rescheduled',
                            'title' => 'Booking Rescheduled',
                            'description' => 'Rescheduled to ' . $booking->date . '.',
                            'metadata' => json_encode([
                                'old_date' => $booking->original_date,
                                'new_date' => $booking->date,
                            ]),
                            'occurred_at' => $rescheduledAt,
                            'created_at' => $rescheduledAt,
                            'updated_at' => $rescheduledAt,
                        ];
                    }
                }

                if (! empty($eventsToInsert)) {
                    DB::table('booking_events')->insert($eventsToInsert);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['last_event_at']);
            $table->dropColumn('last_event_at');
        });

        Schema::dropIfExists('booking_events');
    }
};
