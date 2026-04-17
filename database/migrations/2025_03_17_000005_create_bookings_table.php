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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('surf_spot_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->string('time_period'); // morning, afternoon
            $table->string('skill_level'); // beginner, intermediate, advanced
            $table->unsignedTinyInteger('student_age');
            $table->unsignedTinyInteger('student_count')->default(1);
            $table->string('status')->default('pending'); // pending, confirmed, completed, cancelled, no_show
            $table->decimal('total_amount', 10, 2);
            $table->string('payment_status')->default('pending'); // pending, completed, failed, refunded, pending_cash
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            // Indexes
            $table->index('student_id');
            $table->index('instructor_id');
            $table->index('surf_spot_id');
            $table->index(['instructor_id', 'date', 'time_period']);
            $table->index('status');
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
