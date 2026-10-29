<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public directory map location for an instructor. Coordinates are optional:
     * instructors without a pin still appear in the list, just not on the map.
     */
    public function up(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->string('area')->nullable()->after('bio');
            $table->string('meeting_point')->nullable()->after('area');
            $table->decimal('latitude', 10, 7)->nullable()->after('meeting_point');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->index('area');
        });
    }

    public function down(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropIndex(['area']);
            $table->dropColumn(['area', 'meeting_point', 'latitude', 'longitude']);
        });
    }
};
