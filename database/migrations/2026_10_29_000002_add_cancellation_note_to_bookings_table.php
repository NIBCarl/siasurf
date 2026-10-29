<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student-facing cancellation feedback.
 *
 * `cancellation_reason` is a small enum (who/why category). The free-text note
 * the instructor or SISA typed used to be appended to `notes`, which mixed it
 * with the student's special requests and was never surfaced in the UI. This
 * column keeps the explanation in its own field so the student can read it on
 * both the booking card and the detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('cancellation_note')->nullable()->after('cancellation_reason');
        });

        // Backfill historical rows that had the reason appended to notes.
        DB::table('bookings')
            ->whereNull('cancellation_note')
            ->where('notes', 'like', '%Cancellation reason:%')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $notes = (string) $row->notes;
                    $marker = 'Cancellation reason:';
                    $position = strrpos($notes, $marker);

                    if ($position === false) {
                        continue;
                    }

                    $note = trim(substr($notes, $position + strlen($marker)));
                    $cleaned = trim(substr($notes, 0, $position));

                    DB::table('bookings')->where('id', $row->id)->update([
                        'cancellation_note' => $note !== '' ? $note : null,
                        'notes' => $cleaned !== '' ? $cleaned : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('cancellation_note');
        });
    }
};
