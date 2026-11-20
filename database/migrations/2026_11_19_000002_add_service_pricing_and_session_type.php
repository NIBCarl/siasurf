<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->decimal('guiding_rate', 10, 2)->nullable()->after('rate_per_hour');
            $table->decimal('coaching_rate', 10, 2)->nullable()->after('guiding_rate');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('session_type', 20)->default('teaching')->after('skill_level');
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('session_type');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['session_type', 'hourly_rate']);
        });

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropColumn(['guiding_rate', 'coaching_rate']);
        });
    }
};
