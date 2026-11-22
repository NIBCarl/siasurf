<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('lesson_price', 10, 2)->nullable()->after('hourly_rate');
            $table->decimal('service_fee_percent', 5, 2)->default(7.00)->after('lesson_price');
            $table->decimal('service_fee_amount', 10, 2)->default(0.00)->after('service_fee_percent');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('total_amount');
            $table->decimal('instructor_retained_amount', 10, 2)->nullable()->after('refund_amount');
            $table->date('original_date')->nullable()->after('date');
            $table->boolean('is_long_term')->nullable()->after('original_date');
            $table->timestamp('rescheduled_by_instructor_at')->nullable()->after('accepted_at');
            $table->timestamp('student_accepted_reschedule_at')->nullable()->after('rescheduled_by_instructor_at');
        });

        // Preserve historical booking figures cleanly without distorting past totals.
        DB::table('bookings')->update([
            'lesson_price' => DB::raw('total_amount'),
            'service_fee_amount' => 0.00,
            'original_date' => DB::raw('date'),
        ]);

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('lesson_price', 10, 2)->nullable()->after('amount');
            $table->decimal('service_fee_amount', 10, 2)->default(0.00)->after('lesson_price');
            $table->string('gateway_payment_id', 100)->nullable()->after('transaction_id');
            $table->index('gateway_payment_id');
        });

        DB::table('payments')->update([
            'lesson_price' => DB::raw('amount'),
            'service_fee_amount' => 0.00,
        ]);

        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->string('cancellation_reason', 50)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_payment_id']);
            $table->dropColumn(['lesson_price', 'service_fee_amount', 'gateway_payment_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'lesson_price',
                'service_fee_percent',
                'service_fee_amount',
                'refund_amount',
                'instructor_retained_amount',
                'original_date',
                'is_long_term',
                'rescheduled_by_instructor_at',
                'student_accepted_reschedule_at',
            ]);
        });
    }
};
