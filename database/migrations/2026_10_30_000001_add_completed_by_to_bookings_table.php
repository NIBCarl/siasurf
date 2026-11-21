<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completion had a timestamp but no actor, while attendance recorded both
 * (`attendance_marked_at` + `attendance_marked_by`). The finished-session
 * record needs the same actor trail for both instructor actions, so the
 * dashboard can show who closed a session and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('completed_by')
                ->nullable()
                ->after('completed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
        });
    }
};
