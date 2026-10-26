<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-phase booking flow improvements.
     *
     * 1. cancellation_reason separates the CAUSE of a cancellation from the
     *    booking STATE, so "cancelled" no longer has to encode whether it was
     *    the student, the instructor, the weather, or a payment timeout.
     * 2. auto_accept_bookings enables Instant Book: a trusted instructor's
     *    calendar confirms the request immediately instead of making the
     *    student wait through the 2-hour acceptance window.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('cancellation_reason')->nullable()->after('decline_reason');
            $table->timestamp('payment_timed_out_at')->nullable()->after('cancellation_reason');

            $table->index('cancellation_reason');
        });

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->boolean('auto_accept_bookings')->default(false)->after('rate_per_hour');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The GCash checkout URL gets its own column. It was previously
            // json_encode'd into the free-text notes field, which collided with
            // notes being used for human-readable notes.
            $table->string('checkout_url', 2048)->nullable()->after('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('checkout_url');
        });

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropColumn('auto_accept_bookings');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['cancellation_reason']);
            $table->dropColumn(['cancellation_reason', 'payment_timed_out_at']);
        });
    }
};