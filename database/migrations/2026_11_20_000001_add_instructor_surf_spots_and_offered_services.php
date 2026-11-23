<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->json('offered_services')->nullable()->after('level');
            $table->decimal('rate_per_hour', 10, 2)->nullable()->default(600.00)->change();
        });

        Schema::create('instructor_surf_spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_profile_id')->constrained('instructor_profiles')->cascadeOnDelete();
            $table->foreignId('surf_spot_id')->constrained('surf_spots')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['instructor_profile_id', 'surf_spot_id']);
            $table->index('surf_spot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_surf_spots');

        DB::table('instructor_profiles')
            ->whereNull('rate_per_hour')
            ->update(['rate_per_hour' => 600.00]);

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropColumn('offered_services');
            $table->decimal('rate_per_hour', 10, 2)->nullable(false)->default(600.00)->change();
        });
    }
};
