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
        Schema::create('waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->string('type'); // liability, parental_consent
            $table->string('signed_by');
            $table->text('signature_data'); // Base64 encoded signature image
            $table->string('pdf_path');
            $table->timestamp('signed_at');
            $table->timestamp('retention_until')->nullable(); // 7 years from signed_at
            $table->timestamps();
            
            // Indexes
            $table->index('booking_id');
            $table->index('type');
            $table->unique(['booking_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waivers');
    }
};
