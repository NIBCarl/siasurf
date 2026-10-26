<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instructor booking management (FR 1.9) and session tracking (FR 1.10):
     * the 2-hour accept window, decline audit trail and attendance fields.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // FR 1.9.3 - instructor must accept or decline within 2 hours
            $table->timestamp('accept_deadline_at')->nullable()->after('payment_deadline_at');
            $table->timestamp('accepted_at')->nullable()->after('accept_deadline_at');
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
            $table->text('decline_reason')->nullable()->after('declined_at');

            // FR 1.10.1 - mark student attendance
            $table->string('attendance_status')->nullable()->after('started_at');
            $table->timestamp('attendance_marked_at')->nullable()->after('attendance_status');
            $table->foreignId('attendance_marked_by')->nullable()->after('attendance_marked_at')
                ->constrained('users')->nullOnDelete();

            $table->index('accept_deadline_at');
            $table->index('attendance_status');
        });

        Schema::table('availabilities', function (Blueprint $table) {
            // Lets the system tell a manual block apart from a slot that was
            // locked automatically because a booking claimed it.
            $table->foreignId('blocked_by_booking_id')->nullable()->after('specific_date')
                ->constrained('bookings')->nullOnDelete();

            $table->index('blocked_by_booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('availabilities', function (Blueprint $table) {
            $table->dropForeign(['blocked_by_booking_id']);
            $table->dropIndex(['blocked_by_booking_id']);
            $table->dropColumn('blocked_by_booking_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['attendance_marked_by']);
            $table->dropIndex(['accept_deadline_at']);
            $table->dropIndex(['attendance_status']);
            $table->dropColumn([
                'accept_deadline_at',
                'accepted_at',
                'declined_at',
                'decline_reason',
                'attendance_status',
                'attendance_marked_at',
                'attendance_marked_by',
            ]);
        });
    }
};