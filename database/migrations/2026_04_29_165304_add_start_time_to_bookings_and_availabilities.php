<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('time_period');
            $table->time('end_time')->nullable()->after('start_time');
            $table->integer('duration_hours')->default(1)->after('end_time');
            
            // Add index for time-based queries
            $table->index(['instructor_id', 'date', 'start_time']);
        });

        Schema::table('availabilities', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('time_period');
            $table->time('end_time')->nullable()->after('start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['instructor_id', 'date', 'start_time']);
            $table->dropColumn(['start_time', 'end_time', 'duration_hours']);
        });

        Schema::table('availabilities', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
