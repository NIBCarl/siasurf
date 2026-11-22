<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // Immutable financial snapshot at cancellation time.
            $table->decimal('lesson_price', 10, 2);
            $table->decimal('service_fee_retained', 10, 2)->default(0.00);
            $table->unsignedTinyInteger('refund_percentage');
            $table->decimal('amount', 10, 2);
            $table->decimal('instructor_retained_amount', 10, 2)->default(0.00);

            // Policy classification & day counts for auditability.
            $table->string('policy_type', 30); // long_term, short_term, instructor_fault, admin_override
            $table->string('basis', 50);
            $table->integer('days_before_session')->nullable();
            $table->integer('days_since_payment')->nullable();

            // Gateway & lifecycle state.
            $table->string('status', 30); // requested, processing, completed, failed, manual_required
            $table->string('gateway', 30); // paymongo, mock, manual_gcash
            $table->string('paymongo_refund_id', 100)->nullable()->index();
            $table->string('paymongo_payment_id', 100)->nullable();
            $table->json('gateway_payload')->nullable();
            $table->text('reason')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index('payment_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
