<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR 1.9.4 - instructors may cancel or reschedule a booking, but any change
     * made less than 24 hours before the lesson needs admin approval. One table
     * holds both kinds of request so the audit trail and the admin queue are
     * uniform.
     */
    public function up(): void
    {
        Schema::create('booking_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // reschedule, cancellation
            $table->string('status')->default('pending'); // pending, approved, rejected, auto_approved
            $table->boolean('requires_admin_approval')->default(false);
            $table->text('reason')->nullable();

            // Snapshot of the lesson being moved, so the history survives later edits.
            $table->date('original_date')->nullable();
            $table->string('original_time_period')->nullable();
            $table->time('original_start_time')->nullable();
            $table->time('original_end_time')->nullable();

            // Requested new slot (reschedule only).
            $table->date('new_date')->nullable();
            $table->string('new_time_period')->nullable();
            $table->time('new_start_time')->nullable();
            $table->time('new_end_time')->nullable();

            // Admin decision trail.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['status', 'requires_admin_approval']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_change_requests');
    }
};