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
        Schema::create('safety_validation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Who attempted the booking
            $table->foreignId('booking_id')->nullable()->constrained()->onDelete('set null'); // Null if validation failed before booking created
            $table->string('rule_violated'); // Which of the 6 safety rules was violated
            $table->json('data_snapshot'); // JSON snapshot of attempted booking data
            $table->boolean('passed')->default(false); // Whether validation passed or failed
            $table->text('error_message')->nullable(); // Error message if validation failed
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('booking_id');
            $table->index('rule_violated');
            $table->index('passed');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('safety_validation_logs');
    }
};
