<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Siargao surf-spot metadata.
     *
     * `area` is the barangay/town a break actually sits in, so students can see
     * "Cloud 9, General Luna" instead of a bare name. Coordinates already exist
     * on the table; these columns make them trustworthy and admin-correctable.
     */
    public function up(): void
    {
        Schema::table('surf_spots', function (Blueprint $table) {
            $table->string('area')->nullable()->after('name');
            $table->string('region')->default('Siargao Island')->after('area');
            $table->string('break_type')->nullable()->after('difficulty');
            $table->string('best_tide')->nullable()->after('break_type');
            $table->text('hazard_notes')->nullable()->after('best_tide');
            $table->boolean('is_safe_zone')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_safe_zone');

            $table->index('area');
            $table->index('is_safe_zone');
        });
    }

    public function down(): void
    {
        Schema::table('surf_spots', function (Blueprint $table) {
            $table->dropIndex(['area']);
            $table->dropIndex(['is_safe_zone']);
            $table->dropColumn([
                'area',
                'region',
                'break_type',
                'best_tide',
                'hazard_notes',
                'is_safe_zone',
                'sort_order',
            ]);
        });
    }
};
