<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('previous_surf_spot_id')
                ->nullable()
                ->after('surf_spot_id')
                ->constrained('surf_spots')
                ->nullOnDelete();
            $table->text('spot_change_reason')
                ->nullable()
                ->after('previous_surf_spot_id');
            $table->timestamp('spot_changed_at')
                ->nullable()
                ->after('spot_change_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['previous_surf_spot_id']);
            $table->dropColumn([
                'previous_surf_spot_id',
                'spot_change_reason',
                'spot_changed_at',
            ]);
        });
    }
};
