<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('instructor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->text('bio')->nullable();
            $table->tinyInteger('level')->default(1); // 1, 2, or 3
            $table->string('status')->default('pending_verification'); // pending_verification, active, suspended, inactive
            $table->decimal('rate_per_hour', 10, 2)->default(600.00);
            $table->unsignedTinyInteger('strike_count')->default(0);
            $table->string('qr_code_path')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('level');
            $table->index('status');
            $table->index(['status', 'level']);
            $table->fullText('bio'); // MySQL full-text search
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_profiles');
    }
};
