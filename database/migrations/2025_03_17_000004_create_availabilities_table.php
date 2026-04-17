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
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('day_of_week'); // 0-6 (Sunday-Saturday)
            $table->string('time_period'); // morning, afternoon
            $table->boolean('is_available')->default(true);
            $table->date('specific_date')->nullable(); // For blocking specific dates
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'day_of_week']);
            $table->index(['user_id', 'specific_date']);
            $table->unique(['user_id', 'day_of_week', 'time_period', 'specific_date'], 'unique_availability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
