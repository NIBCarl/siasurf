<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('safety_incident_id')->nullable()
                ->constrained('safety_incidents')->nullOnDelete();
            $table->foreignId('instructor_strike_id')->nullable()
                ->constrained('instructor_strikes')->nullOnDelete();

            $table->decimal('amount', 10, 2)->default(500.00);
            $table->string('penalty_type')->default('warning_and_fine');
            $table->string('status')->default('due');
            $table->text('reason')->nullable();

            $table->foreignId('issued_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('settled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('settlement_note')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index('safety_incident_id');
            $table->index('instructor_strike_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_fines');
    }
};
