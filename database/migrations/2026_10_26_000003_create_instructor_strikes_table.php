<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('safety_incident_id')->nullable()
                ->constrained('safety_incidents')->nullOnDelete();

            $table->unsignedTinyInteger('weight');
            $table->string('reason_code')->nullable();
            $table->text('reason_text')->nullable();
            $table->string('source')->default('system');
            $table->string('status')->default('active');

            $table->foreignId('applied_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('revoked_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index(['safety_incident_id', 'status']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_strikes');
    }
};
