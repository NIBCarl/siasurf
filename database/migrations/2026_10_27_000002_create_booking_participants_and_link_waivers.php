<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A booking is priced per student but historically stored a single person's
 * age/height/weight and a single waiver. This introduces booking_participants
 * as the unit of identity, safety and consent, and scopes waivers per
 * participant so every surfer signs their own release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('primary'); // primary | companion
            $table->string('full_name')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->decimal('height', 5, 2)->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('skill_level')->default('beginner');
            $table->boolean('has_board')->default(false);
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->text('medical_info')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'role']);
        });

        // Backfill a primary participant for every existing booking so the
        // instructor view and waiver flow keep working for historical records.
        $now = now();

        DB::table('bookings')->orderBy('id')->chunkById(200, function ($bookings) use ($now) {
            foreach ($bookings as $booking) {
                if (DB::table('booking_participants')->where('booking_id', $booking->id)->exists()) {
                    continue;
                }

                DB::table('booking_participants')->insert([
                    'booking_id' => $booking->id,
                    'role' => 'primary',
                    'full_name' => DB::table('users')->where('id', $booking->student_id)->value('name'),
                    'age' => $booking->student_age,
                    'height' => $booking->height,
                    'weight' => $booking->weight,
                    'skill_level' => $booking->skill_level ?? 'beginner',
                    'has_board' => (bool) ($booking->has_board ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->foreignId('participant_id')->nullable()->after('booking_id')
                ->constrained('booking_participants')->cascadeOnDelete();
        });

        // One waiver per participant (not one per booking).
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropUnique('waivers_booking_id_type_unique');
            $table->unique(['participant_id', 'type']);
        });

        // Point existing waivers at their booking's primary participant. Written
        // with the query builder instead of a MySQL-only UPDATE ... JOIN so the
        // migration also runs on SQLite (test suite) and PostgreSQL.
        DB::table('waivers')
            ->whereNull('participant_id')
            ->orderBy('id')
            ->chunkById(100, function ($waivers): void {
                foreach ($waivers as $waiver) {
                    $participantId = DB::table('booking_participants')
                        ->where('booking_id', $waiver->booking_id)
                        ->where('role', 'primary')
                        ->value('id');

                    if ($participantId !== null) {
                        DB::table('waivers')
                            ->where('id', $waiver->id)
                            ->update(['participant_id' => $participantId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropUnique('waivers_participant_id_type_unique');
            $table->dropConstrainedForeignId('participant_id');
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->unique(['booking_id', 'type']);
        });

        Schema::dropIfExists('booking_participants');
    }
};
