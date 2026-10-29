<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VAN Surf Camp surf-spot taxonomy.
     *
     * The island's breaks are described by VAN Surf Camp with a coast side
     * (North/East/South), a break zone (Inner/Outer), the barangay they sit in,
     * the season they work and how a surfer reaches them. Those are the fields
     * a student actually uses to choose a lesson, so they live on the table
     * rather than in prose inside `description`.
     *
     * `plus_code` is derived from `latitude`/`longitude` (Google Plus Code,
     * 10-digit local form) so the pin can be read out on a phone with no map
     * app and can never drift from the coordinates it is stored with.
     */
    public function up(): void
    {
        Schema::table('surf_spots', function (Blueprint $table) {
            $table->string('barangay')->nullable()->after('area');
            $table->string('side', 20)->nullable()->after('barangay');
            $table->string('section', 10)->nullable()->after('side');
            $table->string('best_season')->nullable()->after('best_tide');
            $table->string('access')->nullable()->after('best_season');
            $table->string('plus_code', 32)->nullable()->after('access');

            $table->index('side');
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::table('surf_spots', function (Blueprint $table) {
            $table->dropIndex(['side']);
            $table->dropIndex(['section']);
            $table->dropColumn([
                'barangay',
                'side',
                'section',
                'best_season',
                'access',
                'plus_code',
            ]);
        });
    }
};
