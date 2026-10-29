<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('manual');
            $table->string('status')->default('active');
            $table->text('reason');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->foreignId('suspended_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('triggering_incident_id')->nullable()
                ->constrained('safety_incidents')->nullOnDelete();

            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_suspensions');
    }
};
