<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real-time instructor location trail for live sessions.
     *
     * Every ping during an in-progress lesson is appended here so a student can
     * see where their instructor actually is, and SISA can audit that the
     * session ran at the booked break rather than somewhere else.
     */
    public function up(): void
    {
        Schema::create('session_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();

            // Distance from the booked surf spot at the moment of the ping.
            $table->decimal('distance_from_spot_m', 10, 2)->nullable();

            $table->timestamp('recorded_at');
            $table->string('source', 20)->default('gps');

            $table->timestamps();

            $table->index(['booking_id', 'recorded_at']);
            $table->index('instructor_id');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_locations');
    }
};
