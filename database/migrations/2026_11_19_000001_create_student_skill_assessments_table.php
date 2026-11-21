<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_skill_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_swim_deep_water')->default(false);
            $table->boolean('is_first_time')->default(false);
            $table->json('selected_skills');
            $table->json('answers');
            $table->string('checklist_level', 20);
            $table->string('knowledge_level', 20);
            $table->unsignedTinyInteger('knowledge_score');
            $table->unsignedTinyInteger('knowledge_total')->default(4);
            $table->boolean('water_safety_passed')->default(false);
            $table->string('assessed_level', 20);
            $table->json('safety_flags');
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->timestamp('skill_assessed_at')->nullable()->after('skill_level');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('skill_assessed_at');
        });

        Schema::dropIfExists('student_skill_assessments');
    }
};
