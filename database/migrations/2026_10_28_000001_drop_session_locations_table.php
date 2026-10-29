<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the live-session location trail.
 *
 * The study's scope states that SiaSurf does not use GPS tracking, so the
 * instructor/student live-location feature and its table have been withdrawn.
 * The guard keeps this safe on a fresh database, where the table was never
 * created because the original create migration was deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_locations')) {
            Schema::drop('session_locations');
        }
    }

    public function down(): void
    {
        // Not reversible: the feature was removed, not renamed.
    }
};
