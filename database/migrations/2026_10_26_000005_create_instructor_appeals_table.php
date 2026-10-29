<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instructor_suspension_id')
                ->constrained('instructor_suspensions')->cascadeOnDelete();

            $table->text('message');
            $table->string('ground')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('pending');

            $table->text('admin_response')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('revoked_incident_ids')->nullable();

            $table->timestamps();

            $table->index(['instructor_suspension_id', 'status']);
            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_appeals');
    }
};
