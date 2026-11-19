<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The details the booking form asks for, captured once.
     *
     * Date of birth rather than an age column: an age goes stale the day after
     * it is stored, and the 5-12 rule in SafetyService reads a live age. The
     * accessor derives it per request.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('skill_level');
            $table->decimal('height', 5, 2)->nullable()->after('date_of_birth');
            $table->decimal('weight', 5, 2)->nullable()->after('height');
            $table->boolean('has_board')->default(false)->after('weight');

            // Emergency contact and medical notes are optional at profile level:
            // a tourist may not know where they are staying yet, and forcing it
            // would just make them type nonsense. The booking flow still
            // collects them per participant.
            $table->string('emergency_contact_name')->nullable()->after('has_board');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->text('medical_info')->nullable()->after('emergency_contact_phone');

            // Skips are recorded so the prompt can be shown once and then left
            // alone, instead of nagging on every visit.
            $table->timestamp('onboarding_completed_at')->nullable()->after('medical_info');
            $table->timestamp('onboarding_skipped_at')->nullable()->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'height',
                'weight',
                'has_board',
                'emergency_contact_name',
                'emergency_contact_phone',
                'medical_info',
                'onboarding_completed_at',
                'onboarding_skipped_at',
            ]);
        });
    }
};
